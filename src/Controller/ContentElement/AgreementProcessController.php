<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Pdir\PactumBundle\Agreement\AgreementCompleter;
use Pdir\PactumBundle\Announcement\ObjectionCase;
use Pdir\PactumBundle\Announcement\ObjectionService;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\EventListener\ProcessResponseListener;
use Pdir\PactumBundle\Exception\AlreadyCompletedException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Exception\VersionChangedException;
use Pdir\PactumBundle\Form\ConsentType;
use Pdir\PactumBundle\Form\CustomerDataType;
use Pdir\PactumBundle\Form\ObjectionType;
use Pdir\PactumBundle\Form\ProcessActionType;
use Pdir\PactumBundle\Frontend\ProcessSession;
use Pdir\PactumBundle\Invitation\InvitationLinkBuilder;
use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use Pdir\PactumBundle\Invitation\OptInService;
use Pdir\PactumBundle\Rendering\ContractRenderer;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Security\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Content element "Pactum: conclude agreement".
 *
 * Steps: 1 data → 2 double opt-in → 3 contract → 4 consent → 5
 * confirmation. The step is derived exclusively from the invitation status in the
 * database, so steps cannot be skipped. Every successful POST redirects (PRG), so
 * reloading or the back button never submits twice.
 */
#[AsContentElement('pactum_agreement', category: 'pactum')]
class AgreementProcessController extends AbstractContentElementController
{
    public const STEPS = ['data', 'optin', 'contract', 'done'];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProcessSession $processSession,
        private readonly InvitationRepository $invitations,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly VersionRepository $versions,
        private readonly AgreementRepository $agreements,
        private readonly InvitationTokenGenerator $tokenGenerator,
        private readonly OptInService $optInService,
        private readonly AgreementCompleter $completer,
        private readonly ContractRenderer $renderer,
        private readonly RateLimitGuard $rateLimiter,
        private readonly FormFactoryInterface $formFactory,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $router,
        private readonly UriSigner $uriSigner,
        private readonly AuditLogger $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ObjectionService $objections,
        private readonly int $downloadTtlMinutes,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $mainRequest = $this->requestStack->getMainRequest() ?? $request;
        $mainRequest->attributes->set(ProcessResponseListener::REQUEST_ATTRIBUTE, true);

        $response = $this->process($template, $mainRequest);

        // Personal data: never cache
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function process(FragmentTemplate $template, Request $request): Response
    {
        $session = $request->getSession();

        // Objection link of a sub-processor announcement opened
        if ($request->query->has(InvitationLinkBuilder::OBJECTION_PARAMETER)) {
            return $this->handleObjectionLink($template, $request);
        }

        // 1. Invitation link (and opt-in confirmation link) opened
        if ($request->query->has(InvitationLinkBuilder::TOKEN_PARAMETER)) {
            $this->processSession->clearObjection($session);

            return $this->handleTokenLink($template, $request);
        }

        $objectionRecipientId = $this->processSession->getObjectionRecipientId($session);

        if (null !== $objectionRecipientId) {
            return $this->handleObjection($template, $request, $objectionRecipientId);
        }

        $invitationId = $this->processSession->getInvitationId($session);
        $invitation = null !== $invitationId ? $this->invitations->find($invitationId) : null;

        if (null === $invitation) {
            return $this->renderState($template, 'start');
        }

        $now = $this->clock->now()->getTimestamp();
        $status = $invitation->getEffectiveStatus($now);
        $locale = $invitation->language;

        $template->set('locale', $locale);
        $template->set('notice', $this->processSession->pullNotice($session));

        if (InvitationStatus::Completed === $status) {
            return $this->renderDone($template, $invitation);
        }

        if (!$status->isActive()) {
            $this->processSession->clear($session);

            return $this->renderState($template, 'invalid');
        }

        if (!$this->isConfigurationComplete($invitation)) {
            $this->logger->error('Pactum: invitation cannot be processed, configuration incomplete.', ['invitation' => $invitation->id]);

            return $this->renderState($template, 'unavailable');
        }

        if (InvitationStatus::OptedIn === $status && !$this->processSession->isEditing($session)) {
            return $this->handleContractStep($template, $request, $invitation);
        }

        if ($invitation->hasPendingOptIn() && !$this->processSession->isEditing($session)) {
            return $this->handleOptInPendingStep($template, $request, $invitation);
        }

        return $this->handleDataStep($template, $request, $invitation);
    }

    private function handleTokenLink(FragmentTemplate $template, Request $request): Response
    {
        $session = $request->getSession();
        $token = (string) $request->query->get(InvitationLinkBuilder::TOKEN_PARAMETER);

        if (!$this->rateLimiter->allowTokenLookup($request->getClientIp())) {
            return $this->renderState($template, 'rate_limited');
        }

        $invitation = $this->tokenGenerator->isWellFormed($token)
            ? $this->invitations->findByTokenHash($this->tokenGenerator->hash($token))
            : null;

        if (null === $invitation || !$invitation->getEffectiveStatus($this->clock->now()->getTimestamp())->isActive()) {
            $this->processSession->clear($session);

            // Neutral page without details, the token is not echoed
            return $this->renderState($template, 'invalid');
        }

        $this->processSession->bind($session, $invitation->id, $token);
        $this->auditLogger->log(AuditEvent::InvitationOpened, $invitation->id);

        if ($request->query->has(InvitationLinkBuilder::OPTIN_PARAMETER)) {
            $confirmed = $this->optInService->confirm($invitation, (string) $request->query->get(InvitationLinkBuilder::OPTIN_PARAMETER));
            $current = $this->invitations->find($invitation->id);

            if ($confirmed || InvitationStatus::OptedIn === $current?->status) {
                $this->processSession->setNotice($session, 'optin_confirmed');
            } else {
                $this->processSession->setNotice($session, 'optin_invalid');
            }
        }

        // Remove the token from the address bar, history and referrer
        return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
    }

    private function handleObjectionLink(FragmentTemplate $template, Request $request): Response
    {
        $session = $request->getSession();

        if (!$this->rateLimiter->allowTokenLookup($request->getClientIp())) {
            return $this->renderState($template, 'rate_limited');
        }

        $case = $this->objections->findByToken((string) $request->query->get(InvitationLinkBuilder::OBJECTION_PARAMETER));

        if (null === $case) {
            $this->processSession->clearObjection($session);

            // Neutral page without details, the token is not echoed
            return $this->renderState($template, 'invalid');
        }

        $this->processSession->bindObjection($session, $case->recipient->id);

        // Remove the token from the address bar, history and referrer
        return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
    }

    private function handleObjection(FragmentTemplate $template, Request $request, int $recipientId): Response
    {
        $session = $request->getSession();
        $case = $this->objections->find($recipientId);

        if (null === $case) {
            $this->processSession->clearObjection($session);

            return $this->renderState($template, 'invalid');
        }

        $locale = $case->recipient->language ?: $case->announcement->language;

        $template->set('locale', $locale);
        $template->set('notice', $this->processSession->pullNotice($session));

        if ($case->recipient->hasObjected()) {
            return $this->renderObjection($template, $case, 'done');
        }

        if (!$this->objections->isOpen($case)) {
            return $this->renderObjection($template, $case, 'closed');
        }

        $form = $this->formFactory->createNamed(
            'pactum_objection',
            ObjectionType::class,
            ['subprocessors' => [], 'name' => $case->recipient->signatoryName, 'reason' => null],
            ['pactum_locale' => $locale, 'subprocessors' => $case->getObjectableNames()],
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->rateLimiter->allowObjectionSubmit($request->getClientIp(), $case->recipient->id)) {
                return $this->renderState($template, 'rate_limited');
            }

            /** @var array{subprocessors: list<string>, name: string|null, reason: string|null} $data */
            $data = $form->getData();

            try {
                // false: objection exists already or the period has just ended – the page
                // shows the current state
                $this->objections->submit($case, array_values(array_map('strval', $data['subprocessors'])), (string) $data['name'], (string) $data['reason']);
            } catch (PactumException) {
                $this->processSession->setNotice($session, 'error');
            }

            return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
        }

        $template->set('form', $form->createView());

        return $this->renderObjection($template, $case, 'form');
    }

    private function renderObjection(FragmentTemplate $template, ObjectionCase $case, string $step): Response
    {
        $template->set('state', 'objection');
        $template->set('objection_step', $step);
        $template->set('objection', $case);
        $template->set('contract_title', $case->template->title);

        return $template->getResponse();
    }

    private function handleDataStep(FragmentTemplate $template, Request $request, Invitation $invitation): Response
    {
        $session = $request->getSession();
        $customer = $invitation->customer;

        $form = $this->formFactory->createNamed(
            'pactum_customer',
            CustomerDataType::class,
            [
                'company' => $customer->company ?: $invitation->prefillCompany,
                'street' => $customer->street,
                'postal' => $customer->postal,
                'city' => $customer->city,
                'country' => $customer->country,
                'signatory_name' => $customer->signatoryName,
                'signatory_role' => $customer->signatoryRole,
                'signatory_email' => $customer->signatoryEmail ?: $invitation->prefillEmail,
            ],
            ['pactum_locale' => $invitation->language],
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->rateLimiter->allowSubmit($request->getClientIp(), $invitation->id) || !$this->rateLimiter->allowOptInMail($invitation->id)) {
                return $this->renderState($template, 'rate_limited');
            }

            /** @var array<string, string|null> $data */
            $data = $form->getData();

            try {
                $this->optInService->request($invitation, CustomerData::fromRow(array_map('strval', $data)), $this->processSession->getToken($session));
                $this->processSession->setNotice($session, 'optin_sent');
            } catch (NotificationFailedException) {
                $this->processSession->setNotice($session, 'mail_failed');
            } catch (PactumException) {
                $this->processSession->setNotice($session, 'error');
            }

            $this->processSession->setEditing($session, false);

            return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
        }

        $template->set('step', 'data');
        $template->set('form', $form->createView());

        return $this->renderProcess($template, $invitation);
    }

    private function handleOptInPendingStep(FragmentTemplate $template, Request $request, Invitation $invitation): Response
    {
        $session = $request->getSession();

        $form = $this->createActionForm($invitation->language, ['resend' => 'action.resend_optin', 'edit' => 'action.edit_data']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->isClicked($form, 'edit')) {
                $this->processSession->setEditing($session, true);
            } elseif ($this->isClicked($form, 'resend')) {
                if (!$this->rateLimiter->allowOptInMail($invitation->id)) {
                    $this->processSession->setNotice($session, 'rate_limited');
                } else {
                    try {
                        $this->optInService->resend($invitation, $this->processSession->getToken($session));
                        $this->processSession->setNotice($session, 'optin_sent');
                    } catch (PactumException) {
                        $this->processSession->setNotice($session, 'mail_failed');
                    }
                }
            }

            return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
        }

        $template->set('step', 'optin');
        $template->set('action_form', $form->createView());

        return $this->renderProcess($template, $invitation);
    }

    private function handleContractStep(FragmentTemplate $template, Request $request, Invitation $invitation): Response
    {
        $session = $request->getSession();
        $version = $this->versions->findActive($invitation->templateId, $invitation->language);

        if (null === $version) {
            return $this->renderState($template, 'unavailable');
        }

        $locale = $invitation->language;

        $consentTexts = [
            'read' => $this->translator->trans('consent.read', [], 'pactum', $locale),
            'authorized' => $this->translator->trans('consent.authorized', ['%company%' => $invitation->customer->company], 'pactum', $locale),
        ];

        $form = $this->formFactory->createNamed(
            'pactum_consent',
            ConsentType::class,
            ['version_id' => (string) $version->id],
            [
                'consent_texts' => $consentTexts,
                'submit_label' => $this->translator->trans('form.submit_consent', [], 'pactum', $locale),
            ],
        );

        $actionForm = $this->createActionForm($locale, ['edit' => 'action.edit_data']);

        $form->handleRequest($request);
        $actionForm->handleRequest($request);

        if ($actionForm->isSubmitted() && $actionForm->isValid() && $this->isClicked($actionForm, 'edit')) {
            $this->processSession->setEditing($session, true);

            return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->rateLimiter->allowSubmit($request->getClientIp(), $invitation->id)) {
                return $this->renderState($template, 'rate_limited');
            }

            /** @var array{version_id: string|null} $data */
            $data = $form->getData();

            try {
                $this->completer->complete($invitation->id, (int) $data['version_id'], $consentTexts, $request->getClientIp(), $request->headers->get('User-Agent'));
            } catch (AlreadyCompletedException) {
                // Double submit: the agreement exists already, show it
            } catch (VersionChangedException) {
                $this->processSession->setNotice($session, 'version_changed');
            } catch (PactumException $e) {
                $this->logger->error('Pactum: concluding the agreement failed.', ['invitation' => $invitation->id, 'exception' => $e]);
                $this->processSession->setNotice($session, 'error');
            }

            return new RedirectResponse($this->getCleanUrl($request), Response::HTTP_SEE_OTHER);
        }

        $document = $this->renderer->renderForAgreement($version, $invitation->customer, $this->clock->now());

        $template->set('step', 'contract');
        $template->set('version', $version);
        $template->set('document', $document);
        $template->set('consent_form', $form->createView());
        $template->set('action_form', $actionForm->createView());
        $template->set('preview_url', $this->signedUrl('pactum_frontend_preview', ['invitation' => $invitation->id]));

        return $this->renderProcess($template, $invitation);
    }

    private function renderDone(FragmentTemplate $template, Invitation $invitation): Response
    {
        $agreement = $this->agreements->findByInvitation($invitation->id);

        $template->set('step', 'done');
        $template->set('agreement', $agreement);
        $template->set('download_url', $agreement ? $this->signedUrl('pactum_frontend_document', ['uuid' => $agreement->uuid]) : null);

        return $this->renderProcess($template, $invitation);
    }

    private function renderProcess(FragmentTemplate $template, Invitation $invitation): Response
    {
        $templateModel = $this->templates->find($invitation->templateId);

        $template->set('state', 'process');
        $template->set('progress', $this->buildProgress((string) $template->get('step')));
        $template->set('invitation', $invitation);
        $template->set('contract_title', $templateModel ? $templateModel->title : '');
        $template->set('locale', $invitation->language);

        return $template->getResponse();
    }

    /**
     * @return list<array{key: string, current: bool, done: bool}>
     */
    private function buildProgress(string $step): array
    {
        $index = array_search($step, self::STEPS, true);
        $index = false === $index ? 0 : $index;

        $progress = [];

        foreach (self::STEPS as $i => $key) {
            $progress[] = ['key' => $key, 'current' => $i === $index, 'done' => $i < $index || 'done' === $step];
        }

        return $progress;
    }

    private function renderState(FragmentTemplate $template, string $state): Response
    {
        $template->set('state', $state);

        return $template->getResponse();
    }

    /**
     * @param array<string, string> $actions name => translation key
     *
     * @return FormInterface<mixed>
     */
    private function createActionForm(string $locale, array $actions): FormInterface
    {
        $labels = array_map(fn (string $key): string => $this->translator->trans($key, [], 'pactum', $locale), $actions);

        return $this->formFactory->createNamed('pactum_action', ProcessActionType::class, null, ['actions' => $labels]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function isClicked(FormInterface $form, string $name): bool
    {
        if (!$form->has($name)) {
            return false;
        }

        $button = $form->get($name);

        return $button instanceof ClickableInterface && $button->isClicked();
    }

    private function isConfigurationComplete(Invitation $invitation): bool
    {
        $template = $this->templates->find($invitation->templateId);
        $contractor = $template ? $this->contractors->find($template->contractorId) : null;

        return null !== $contractor && $contractor->isComplete() && null !== $this->versions->findActive($invitation->templateId, $invitation->language);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function signedUrl(string $route, array $parameters): string
    {
        $url = $this->router->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, new \DateInterval('PT'.$this->downloadTtlMinutes.'M'));
    }

    private function getCleanUrl(Request $request): string
    {
        $query = $request->query->all();
        unset($query[InvitationLinkBuilder::TOKEN_PARAMETER], $query[InvitationLinkBuilder::OPTIN_PARAMETER], $query[InvitationLinkBuilder::OBJECTION_PARAMETER]);

        $url = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo();

        return [] === $query ? $url : $url.'?'.http_build_query($query);
    }
}
