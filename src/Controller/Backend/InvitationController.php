<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Controller\Backend;

use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;
use Pdir\PactumBundle\Exception\InvitationNotUsableException;
use Pdir\PactumBundle\Exception\NoActiveVersionException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Invitation\InvitationService;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('%contao.backend.route_prefix%/pactum/invitation/{id}', requirements: ['id' => '\d+'], defaults: ['_scope' => 'backend', '_store_referrer' => false])]
class InvitationController extends AbstractPactumBackendController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly TemplateRepository $templates,
        private readonly AgreementRepository $agreements,
        private readonly AuditLogRepository $auditLog,
        private readonly InvitationService $invitationService,
        private readonly ClockInterface $clock,
        private readonly int $invitationTtlDays,
    ) {
    }

    /**
     * Generates (or re-generates) the invitation link. The plain link is shown
     * exactly once; afterwards only its hash exists.
     */
    #[Route('/link', name: 'pactum_backend_invitation_link', methods: ['GET', 'POST'])]
    public function link(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_INVITATIONS);

        $invitation = $this->invitations->find($id) ?? throw new NotFoundHttpException();

        $generated = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $this->denyUnlessValidRequestToken($request);

            try {
                $generated = $this->invitationService->generateLink($id, $request->request->has('send_mail'));
            } catch (IncompleteContractorException) {
                $error = $this->trans('be.contractor.incomplete');
            } catch (NoActiveVersionException) {
                $error = $this->trans('be.invitation.no_active_version');
            } catch (InvitationNotUsableException) {
                $error = $this->trans('be.invitation.not_usable');
            } catch (PactumException $e) {
                $error = $this->transException($e);
            }

            // Reload the record (status, mail status)
            $invitation = $this->invitations->find($id) ?? $invitation;
        }

        return $this->render('@Contao/backend/pactum/invitation_link.html.twig', [
            'headline' => $this->trans('be.invitation.link_headline'),
            'title' => $this->trans('be.invitation.link_headline'),
            'invitation' => $invitation,
            'effective_status' => $invitation->getEffectiveStatus($this->clock->now()->getTimestamp())->value,
            'template' => $this->templates->find($invitation->templateId),
            'generated' => $generated,
            'action_error' => $error,
            'back_url' => $this->generateUrl('contao_backend', ['do' => self::MODULE_INVITATIONS]),
        ]);
    }

    #[Route('/extend', name: 'pactum_backend_invitation_extend', methods: ['GET', 'POST'])]
    public function extend(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_INVITATIONS);

        $invitation = $this->invitations->find($id) ?? throw new NotFoundHttpException();
        $back = $this->generateUrl('contao_backend', ['do' => self::MODULE_INVITATIONS]);
        $error = null;

        if ($request->isMethod('POST')) {
            $this->denyUnlessValidRequestToken($request);

            $days = (int) $request->request->get('days');

            try {
                $expiresAt = $this->invitationService->extend($id, $days);
                $this->addFlash('confirm', $this->trans('be.invitation.extended', ['%date%' => date('Y-m-d', $expiresAt)]));

                return $this->redirect($back);
            } catch (InvalidStatusTransitionException) {
                $error = $this->trans('be.invitation.not_usable');
            } catch (\InvalidArgumentException) {
                $error = $this->trans('be.invitation.invalid_days');
            }
        }

        return $this->render('@Contao/backend/pactum/invitation_extend.html.twig', [
            'headline' => $this->trans('be.invitation.extend_headline'),
            'title' => $this->trans('be.invitation.extend_headline'),
            'invitation' => $invitation,
            'default_days' => $this->invitationTtlDays,
            'action_error' => $error,
            'back_url' => $back,
        ]);
    }

    #[Route('/revoke', name: 'pactum_backend_invitation_revoke', methods: ['GET', 'POST'])]
    public function revoke(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_INVITATIONS);

        $this->invitations->find($id) ?? throw new NotFoundHttpException();
        $back = $this->generateUrl('contao_backend', ['do' => self::MODULE_INVITATIONS]);

        if ($request->isMethod('POST')) {
            $this->denyUnlessValidRequestToken($request);

            try {
                $this->invitationService->revoke($id);

                $this->addFlash('confirm', $this->trans('be.invitation.revoked'));
            } catch (InvalidStatusTransitionException) {
                $this->addFlash('error', $this->trans('be.invitation.not_usable'));
            }

            return $this->redirect($back);
        }

        return $this->render('@Contao/backend/pactum/confirm.html.twig', [
            'headline' => $this->trans('be.invitation.revoke_headline'),
            'title' => $this->trans('be.invitation.revoke_headline'),
            'question' => $this->trans('be.invitation.revoke_question'),
            'submit_label' => $this->trans('be.invitation.revoke_submit'),
            'back_url' => $back,
        ]);
    }

    #[Route('/audit', name: 'pactum_backend_invitation_audit', methods: ['GET'])]
    public function audit(int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_INVITATIONS);

        $invitation = $this->invitations->find($id) ?? throw new NotFoundHttpException();
        $agreement = $this->agreements->findByInvitation($id);

        return $this->render('@Contao/backend/pactum/audit_log.html.twig', [
            'headline' => $this->trans('be.audit.headline'),
            'title' => $this->trans('be.audit.headline'),
            'invitation' => $invitation,
            'entries' => $this->auditLog->findForInvitationOrAgreement($id, $agreement->id ?? 0),
            'back_url' => $this->generateUrl('contao_backend', ['do' => self::MODULE_INVITATIONS]),
        ]);
    }
}
