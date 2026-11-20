<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Controller\Frontend;

use Pdir\PactumBundle\Agreement\AgreementMailer;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Frontend\ProcessSession;
use Pdir\PactumBundle\Pdf\PdfDocumentData;
use Pdir\PactumBundle\Pdf\PdfGenerator;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Rendering\ContractRenderer;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PDF downloads for customers. Every URL is signed with a short expiry AND bound
 * to the session that went through the process, so a PDF can never be fetched
 * with a guessed or forwarded URL. Any failure is a neutral 404.
 */
#[AsController]
#[Route('/_pactum', defaults: ['_scope' => 'frontend'])]
class DocumentController
{
    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly ProcessSession $processSession,
        private readonly InvitationRepository $invitations,
        private readonly AgreementRepository $agreements,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly VersionRepository $versions,
        private readonly ContractRenderer $renderer,
        private readonly PdfGenerator $pdfGenerator,
        private readonly PdfStorage $pdfStorage,
        private readonly AgreementMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/document/{uuid}', name: 'pactum_frontend_document', requirements: ['uuid' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function document(Request $request, string $uuid): Response
    {
        $this->denyUnlessSigned($request);

        $agreement = $this->agreements->findByUuid($uuid);

        if (null === $agreement || $this->processSession->getInvitationId($request->getSession()) !== $agreement->invitationId || !$this->pdfStorage->exists($agreement->pdfPath)) {
            throw new NotFoundHttpException();
        }

        $template = $this->templates->find($agreement->templateId);

        $response = new BinaryFileResponse($this->pdfStorage->getAbsolutePath($agreement->pdfPath));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->mailer->getFileName($template->title ?? 'agreement', $agreement));
        $response->headers->set('Content-Type', 'application/pdf');

        return $this->secure($response);
    }

    #[Route('/preview/{invitation}', name: 'pactum_frontend_preview', requirements: ['invitation' => '\d+'], methods: ['GET'])]
    public function preview(Request $request, int $invitation): Response
    {
        $this->denyUnlessSigned($request);

        $model = $this->invitations->find($invitation);
        $now = $this->clock->now();

        if (
            null === $model
            || $this->processSession->getInvitationId($request->getSession()) !== $model->id
            || InvitationStatus::OptedIn !== $model->getEffectiveStatus($now->getTimestamp())
        ) {
            throw new NotFoundHttpException();
        }

        $template = $this->templates->find($model->templateId);
        $contractor = $template ? $this->contractors->find($template->contractorId) : null;
        $version = $this->versions->findActive($model->templateId, $model->language);

        if (null === $template || null === $contractor || null === $version) {
            throw new NotFoundHttpException();
        }

        $pdf = $this->pdfGenerator->generate(new PdfDocumentData(
            contractor: $contractor,
            templateTitle: $template->title,
            version: $version,
            customer: $model->customer,
            documentHtml: $this->renderer->renderForAgreement($version, $model->customer, $now),
            preview: true,
        ));

        $response = new Response($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'preview.pdf'));

        return $this->secure($response);
    }

    private function denyUnlessSigned(Request $request): void
    {
        if (!$this->uriSigner->checkRequest($request)) {
            throw new NotFoundHttpException();
        }
    }

    private function secure(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
