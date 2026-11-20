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

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Invitation\InvitationLinkBuilder;
use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Subprocessor\SubprocessorDiff;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Announces a sub-processor change to all customers of a contract template (Art.
 * 28(2) GDPR): compares the sub-processors of a draft version with the active
 * version, stores the announcement with one personal objection token per customer
 * and sends the information mails.
 */
class AnnouncementService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VersionRepository $versions,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly AnnexRepository $annexes,
        private readonly AgreementRepository $agreements,
        private readonly AnnouncementRepository $announcements,
        private readonly InvitationTokenGenerator $tokenGenerator,
        private readonly InvitationLinkBuilder $linkBuilder,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly AuditLogger $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $subprocessorNoticeDays,
        private readonly SubprocessorDiff $diff = new SubprocessorDiff(),
    ) {
    }

    /**
     * @throws PactumException
     */
    public function preview(int $draftVersionId): AnnouncementPreview
    {
        $draft = $this->versions->find($draftVersionId) ?? throw new InvalidVersionException(\sprintf('Version %d does not exist.', $draftVersionId), translationKey: 'error.version_not_found', translationParameters: ['%id%' => $draftVersionId]);

        if (VersionStatus::Draft !== $draft->status) {
            throw new InvalidVersionException(\sprintf('Version %d is not a draft.', $draftVersionId), translationKey: 'error.version_not_draft', translationParameters: ['%id%' => $draftVersionId]);
        }

        $template = $this->templates->find($draft->templateId) ?? throw new InvalidVersionException(\sprintf('Template %d does not exist.', $draft->templateId), translationKey: 'error.template_not_found', translationParameters: ['%id%' => $draft->templateId]);
        $contractor = $this->contractors->find($template->contractorId);

        if (null === $contractor || !$contractor->isComplete()) {
            throw new IncompleteContractorException(\sprintf('The contractor profile of template %d is missing or incomplete.', $template->id), translationKey: 'be.contractor.incomplete');
        }

        $active = $this->versions->findActive($draft->templateId, $draft->language)
            ?? throw new PactumException(\sprintf('Template %d has no published version in language "%s" to compare with.', $template->id, $draft->language), translationKey: 'error.announcement.no_active_version', translationParameters: ['%language%' => $draft->language]);

        $changes = $this->diff->compare(
            $this->fromSnapshot((string) $active->annexSnapshot),
            $this->subprocessorsOf($this->annexes->findByIds($draft->annexIds)),
        );

        if (!$changes->hasContentChanges()) {
            throw new PactumException(\sprintf('The sub-processors of version %d do not differ from the active version.', $draftVersionId), translationKey: 'error.announcement.no_changes');
        }

        $noticeDays = $this->subprocessorNoticeDays;

        return new AnnouncementPreview(
            $draft,
            $active,
            $template,
            $contractor,
            $changes,
            $this->uniqueRecipients($this->agreements->findByTemplate($template->id)),
            $noticeDays,
            $this->clock->now()->getTimestamp() + $noticeDays * 86400,
        );
    }

    /**
     * @throws PactumException
     */
    public function announce(int $draftVersionId, int $userId): AnnouncementResult
    {
        $preview = $this->preview($draftVersionId);

        if ([] === $preview->recipients) {
            throw new PactumException(\sprintf('Template %d has no concluded agreements to inform.', $preview->template->id), translationKey: 'be.announcement.no_recipients');
        }

        $now = $this->clock->now()->getTimestamp();
        $effectiveAt = $now + $preview->noticeDays * 86400;

        /** @var array<int, array{agreement: Agreement, token: string}> $tokens recipient ID => data */
        $tokens = [];

        $announcementId = $this->connection->transactional(
            function () use ($preview, $userId, $now, $effectiveAt, &$tokens): int {
                // Serialize concurrent requests (double click) for the same template
                $this->templates->lockForUpdate($preview->template->id);

                if (null !== $this->announcements->findLatestForVersion($preview->draft->id)) {
                    throw new PactumException(\sprintf('A change has already been announced for version %d. Create a new draft for further changes.', $preview->draft->id), translationKey: 'error.announcement.already_announced');
                }

                $id = $this->announcements->insert(
                    Uuid::v4()->toRfc4122(),
                    $preview->template->id,
                    $preview->draft->id,
                    $preview->draft->language,
                    $preview->changes,
                    $preview->noticeDays,
                    $now,
                    $effectiveAt,
                    $userId,
                );

                foreach ($preview->recipients as $agreement) {
                    $token = $this->tokenGenerator->generate();
                    $customer = $agreement->customer;

                    $recipientId = $this->announcements->insertRecipient($id, $agreement->id, $customer->company, $customer->signatoryName, $customer->signatoryEmail, $agreement->language, $token->hash, $now);
                    $tokens[$recipientId] = ['agreement' => $agreement, 'token' => $token->plain];
                }

                $this->auditLogger->log(
                    AuditEvent::SubprocessorChangeAnnounced,
                    context: [
                        'announcement' => $id,
                        'template' => $preview->template->id,
                        'version' => $preview->draft->id,
                        'recipients' => \count($tokens),
                        'effective_at' => $effectiveAt,
                    ],
                );

                return $id;
            },
        );

        $sent = 0;
        $failed = 0;

        foreach ($tokens as $recipientId => ['agreement' => $agreement, 'token' => $token]) {
            if ($this->sendMail($preview, $agreement, $token, $effectiveAt)) {
                $this->announcements->setRecipientMailStatus($recipientId, MailStatus::Sent, $this->clock->now()->getTimestamp());
                $this->auditLogger->log(AuditEvent::SubprocessorAnnouncementSent, agreementId: $agreement->id, context: ['announcement' => $announcementId, 'recipient' => $recipientId]);
                ++$sent;
            } else {
                $this->announcements->setRecipientMailStatus($recipientId, MailStatus::Failed, $this->clock->now()->getTimestamp());
                $this->auditLogger->log(AuditEvent::MailFailed, agreementId: $agreement->id, context: ['announcement' => $announcementId, 'recipient' => $recipientId]);
                ++$failed;
            }
        }

        return new AnnouncementResult($announcementId, $sent, $failed, $effectiveAt);
    }

    private function sendMail(AnnouncementPreview $preview, Agreement $agreement, #[\SensitiveParameter] string $token, int $effectiveAt): bool
    {
        try {
            $this->notifier->dispatch(new Notification(
                NotificationType::SubprocessorAnnouncement,
                $agreement->customer->signatoryEmail,
                $agreement->language,
                $preview->contractor,
                [
                    'company' => $agreement->customer->company,
                    'signatory_name' => $agreement->customer->signatoryName,
                    'template_title' => $preview->template->title,
                    'changes' => $preview->changes,
                    'effective_at' => $effectiveAt,
                    'notice_days' => $preview->noticeDays,
                    'url' => $this->linkBuilder->buildObjection($preview->template->targetPage, $token),
                ],
            ));
        } catch (NotificationFailedException|PactumException $e) {
            $this->logger->error('Pactum: sending a sub-processor announcement failed.', ['agreement' => $agreement->id, 'exception' => $e]);

            return false;
        }

        return true;
    }

    /**
     * @param list<Agreement> $agreements newest first
     *
     * @return list<Agreement>
     */
    private function uniqueRecipients(array $agreements): array
    {
        $result = [];

        foreach ($agreements as $agreement) {
            $key = mb_strtolower(trim($agreement->customer->signatoryEmail));

            if ('' !== $key && !isset($result[$key])) {
                $result[$key] = $agreement;
            }
        }

        return array_values($result);
    }

    /**
     * @return list<Subprocessor>
     */
    private function fromSnapshot(string $snapshot): array
    {
        $data = json_decode($snapshot, true);
        $annexes = [];

        foreach (\is_array($data) ? $data : [] as $item) {
            if (\is_array($item)) {
                $annexes[] = Annex::fromArray($item);
            }
        }

        return $this->subprocessorsOf($annexes);
    }

    /**
     * @param list<Annex> $annexes
     *
     * @return list<Subprocessor>
     */
    private function subprocessorsOf(array $annexes): array
    {
        $result = [];

        foreach ($annexes as $annex) {
            if (AnnexType::Subprocessor === $annex->type) {
                array_push($result, ...$annex->subprocessors);
            }
        }

        return $result;
    }
}
