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

use Pdir\PactumBundle\Agreement\AgreementMailer;
use Pdir\PactumBundle\Agreement\AgreementVerifier;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('%contao.backend.route_prefix%/pactum/agreement/{id}', requirements: ['id' => '\d+'], defaults: ['_scope' => 'backend', '_store_referrer' => false])]
class AgreementController extends AbstractPactumBackendController
{
    public function __construct(
        private readonly AgreementRepository $agreements,
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly AuditLogRepository $auditLog,
        private readonly AgreementVerifier $verifier,
        private readonly AgreementMailer $mailer,
        private readonly PdfStorage $pdfStorage,
    ) {
    }

    #[Route('', name: 'pactum_backend_agreement_detail', methods: ['GET'])]
    public function detail(int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_AGREEMENTS);

        $agreement = $this->agreements->find($id) ?? throw new NotFoundHttpException();
        $template = $this->templates->find($agreement->templateId);

        return $this->render('@Contao/backend/pactum/agreement_detail.html.twig', [
            'headline' => $this->trans('be.agreement.detail_headline', ['%company%' => $agreement->customer->company]),
            'title' => $this->trans('be.agreement.detail_headline', ['%company%' => $agreement->customer->company]),
            'agreement' => $agreement,
            'template' => $template,
            'pactum_version' => $this->versions->find($agreement->versionId),
            'verification' => $this->verifier->verify($id),
            'entries' => $this->auditLog->findForInvitationOrAgreement($agreement->invitationId, $agreement->id),
            'pdf_url' => $this->generateUrl('pactum_backend_agreement_pdf', ['id' => $id]),
            'resend_url' => $this->generateUrl('pactum_backend_agreement_resend', ['id' => $id]),
            'back_url' => $this->generateUrl('contao_backend', ['do' => self::MODULE_AGREEMENTS]),
        ]);
    }

    #[Route('/pdf', name: 'pactum_backend_agreement_pdf', methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_AGREEMENTS);

        $agreement = $this->agreements->find($id) ?? throw new NotFoundHttpException();

        if (!$this->pdfStorage->exists($agreement->pdfPath)) {
            throw new NotFoundHttpException();
        }

        $template = $this->templates->find($agreement->templateId);

        $response = new BinaryFileResponse($this->pdfStorage->getAbsolutePath($agreement->pdfPath));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->mailer->getFileName($template->title ?? 'agreement', $agreement));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route('/resend', name: 'pactum_backend_agreement_resend', methods: ['POST'])]
    public function resend(Request $request, int $id): Response
    {
        $this->denyUnlessModule(self::MODULE_AGREEMENTS);
        $this->denyUnlessValidRequestToken($request);

        $agreement = $this->agreements->find($id) ?? throw new NotFoundHttpException();

        if ($this->mailer->send($agreement)) {
            $this->addFlash('confirm', $this->trans('be.agreement.mail_resent'));
        } else {
            $this->addFlash('error', $this->trans('be.agreement.mail_failed'));
        }

        return $this->redirectToRoute('pactum_backend_agreement_detail', ['id' => $id]);
    }
}
