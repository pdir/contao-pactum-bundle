<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Agreement;

use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the conclusion mails (customer confirmation + contractor notification)
 * with the PDF attached. Never throws: a failure is logged, recorded on the
 * agreement and can be retried from the back end.
 */
class AgreementMailer
{
    public function __construct(
        private readonly NotificationDispatcherInterface $notifier,
        private readonly AgreementRepository $agreements,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly VersionRepository $versions,
        private readonly PdfStorage $pdfStorage,
        private readonly AuditLogger $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(Agreement $agreement): bool
    {
        try {
            $template = $this->templates->find($agreement->templateId);
            $contractor = $template ? $this->contractors->find($template->contractorId) : null;

            if (null === $template || null === $contractor) {
                throw new NotificationFailedException(\sprintf('Template or contractor of agreement %d does not exist.', $agreement->id));
            }

            $version = $this->versions->find($agreement->versionId);

            $context = [
                'agreement' => $agreement,
                'company' => $agreement->customer->company,
                'signatory_name' => $agreement->customer->signatoryName,
                'template_title' => $template->title,
                'version_number' => $version?->versionNumber,
            ];

            $attachments = [[
                'path' => $this->pdfStorage->getAbsolutePath($agreement->pdfPath),
                'name' => $this->getFileName($template->title, $agreement),
                'type' => 'application/pdf',
            ]];

            $this->notifier->dispatch(new Notification(NotificationType::AgreementCustomer, $agreement->customer->signatoryEmail, $agreement->language, $contractor, $context, $attachments));
            $this->notifier->dispatch(new Notification(NotificationType::AgreementContractor, $contractor->getNotificationRecipient(), $agreement->language, $contractor, $context, $attachments));
        } catch (\InvalidArgumentException|NotificationFailedException $e) {
            $this->agreements->setMailStatus($agreement->id, MailStatus::Failed, $this->clock->now()->getTimestamp());
            $this->auditLogger->log(AuditEvent::AgreementMailFailed, $agreement->invitationId, $agreement->id);
            $this->logger->error('Pactum: sending the agreement mails failed.', ['agreement' => $agreement->id, 'exception' => $e]);

            return false;
        }

        $this->agreements->setMailStatus($agreement->id, MailStatus::Sent, $this->clock->now()->getTimestamp());
        $this->auditLogger->log(AuditEvent::AgreementMailSent, $agreement->invitationId, $agreement->id);

        return true;
    }

    public function getFileName(string $templateTitle, Agreement $agreement): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $templateTitle), '-'));

        return \sprintf('%s-%s.pdf', '' !== $slug ? $slug : 'agreement', substr($agreement->uuid, 0, 8));
    }
}
