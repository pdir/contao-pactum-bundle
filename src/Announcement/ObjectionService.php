<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Announcement;

use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\AnnouncementRecipient;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Objections of customers against an announced sub-processor change.
 */
class ObjectionService
{
    public const MAX_REASON_LENGTH = 5000;

    public function __construct(
        private readonly AnnouncementRepository $announcements,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly InvitationTokenGenerator $tokenGenerator,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly AuditLogger $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function findByToken(#[\SensitiveParameter] string $plainToken): ObjectionCase|null
    {
        if (!$this->tokenGenerator->isWellFormed($plainToken)) {
            return null;
        }

        $recipient = $this->announcements->findRecipientByTokenHash($this->tokenGenerator->hash($plainToken));

        return null === $recipient ? null : $this->load($recipient);
    }

    public function find(int $recipientId): ObjectionCase|null
    {
        $recipient = $this->announcements->findRecipient($recipientId);

        return null === $recipient ? null : $this->load($recipient);
    }

    public function isOpen(ObjectionCase $case): bool
    {
        return !$case->recipient->hasObjected() && $case->announcement->isObjectionPeriodOpen($this->clock->now()->getTimestamp());
    }

    /**
     * Records the objection. Returns false if it was not recorded because an
     * objection already exists or the objection period has ended.
     *
     * @param list<string> $subprocessors names of the objected sub-processors
     *
     * @throws PactumException if the input is invalid
     */
    public function submit(ObjectionCase $case, array $subprocessors, string $name, string $reason): bool
    {
        $subprocessors = array_values(array_unique($subprocessors));
        $name = trim($name);
        $reason = trim($reason);

        if ([] === $subprocessors || [] !== array_diff($subprocessors, $case->getObjectableNames())) {
            throw new PactumException('Please select at least one of the announced sub-processors.');
        }

        if ('' === $name || '' === $reason || mb_strlen($name) > 255 || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new PactumException('Name and reason are required.');
        }

        $now = $this->clock->now()->getTimestamp();

        if (!$this->announcements->recordObjection($case->recipient->id, $name, $subprocessors, $reason, $now)) {
            return false;
        }

        $this->auditLogger->log(
            AuditEvent::SubprocessorObjectionReceived,
            agreementId: $case->recipient->agreementId,
            context: ['announcement' => $case->announcement->id, 'recipient' => $case->recipient->id, 'subprocessors' => \count($subprocessors)],
            actor: AuditLogger::ACTOR_CUSTOMER,
        );

        try {
            $this->notifier->dispatch(new Notification(
                NotificationType::SubprocessorObjection,
                $case->contractor->getNotificationRecipient(),
                $case->announcement->language,
                $case->contractor,
                [
                    'company' => $case->recipient->company,
                    'objection_name' => $name,
                    'email' => $case->recipient->email,
                    'subprocessors' => $subprocessors,
                    'reason' => $reason,
                    'template_title' => $case->template->title,
                    'announcement' => $case->announcement,
                    'objected_at' => $now,
                ],
            ));
        } catch (NotificationFailedException $e) {
            // The objection is stored and visible in the back end
            $this->logger->error('Pactum: notifying the contractor about an objection failed.', ['announcement' => $case->announcement->id, 'exception' => $e]);
            $this->auditLogger->log(AuditEvent::MailFailed, agreementId: $case->recipient->agreementId, context: ['announcement' => $case->announcement->id, 'recipient' => $case->recipient->id], actor: AuditLogger::ACTOR_SYSTEM);
        }

        return true;
    }

    private function load(AnnouncementRecipient $recipient): ObjectionCase|null
    {
        $announcement = $this->announcements->find($recipient->announcementId);
        $template = $announcement ? $this->templates->find($announcement->templateId) : null;
        $contractor = $template ? $this->contractors->find($template->contractorId) : null;

        if (null === $announcement || null === $template || null === $contractor) {
            return null;
        }

        return new ObjectionCase($recipient, $announcement, $template, $contractor);
    }
}
