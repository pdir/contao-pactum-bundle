<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Invitation;

use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Event\InvitationCreatedEvent;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;
use Pdir\PactumBundle\Exception\InvitationNotUsableException;
use Pdir\PactumBundle\Exception\NoActiveVersionException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Back end side of invitations: link generation (incl. re-send with token
 * rotation), revoke, extend and expiry.
 */
class InvitationService
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly VersionRepository $versions,
        private readonly InvitationTokenGenerator $tokenGenerator,
        private readonly InvitationLinkBuilder $linkBuilder,
        private readonly InvitationStateMachine $stateMachine,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly AuditLogger $auditLogger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $invitationTtlDays,
    ) {
    }

    public function getDefaultExpiry(int $templateId): int
    {
        $template = $this->templates->find($templateId);
        $days = $template && $template->invitationTtlDays > 0 ? $template->invitationTtlDays : $this->invitationTtlDays;

        return $this->clock->now()->modify('+'.$days.' days')->setTime(23, 59, 59)->getTimestamp();
    }

    /**
     * Generates a new link. Any previously generated link becomes invalid, because
     * only the hash of the current token is stored.
     *
     * @throws InvitationNotUsableException
     * @throws NoActiveVersionException
     * @throws IncompleteContractorException
     */
    public function generateLink(int $invitationId, bool $sendMail): GeneratedLink
    {
        $now = $this->clock->now()->getTimestamp();
        $invitation = $this->requireInvitation($invitationId);

        if (!$invitation->getEffectiveStatus($now)->isActive()) {
            throw new InvitationNotUsableException(\sprintf('Invitation %d is not active.', $invitationId));
        }

        $template = $this->templates->find($invitation->templateId) ?? throw new InvitationNotUsableException(\sprintf('Template of invitation %d does not exist.', $invitationId));
        $contractor = $this->contractors->find($template->contractorId);

        if (null === $contractor || !$contractor->isComplete()) {
            throw new IncompleteContractorException(\sprintf('The contractor profile of template %d is missing or incomplete.', $template->id));
        }

        if (null === $this->versions->findActive($template->id, $invitation->language)) {
            throw new NoActiveVersionException(\sprintf('Template %d has no published version in the invitation language.', $template->id));
        }

        if ($sendMail && '' === $invitation->prefillEmail) {
            throw new InvitationNotUsableException(\sprintf('Invitation %d has no e-mail address.', $invitationId));
        }

        $token = $this->tokenGenerator->generate();
        $url = $this->linkBuilder->build($template->targetPage, $token->plain);
        $rotated = null !== $invitation->tokenHash;

        $this->invitations->replaceToken($invitationId, $token->hash, $now);
        $this->auditLogger->log(AuditEvent::InvitationLinkGenerated, $invitationId, context: ['rotated' => $rotated]);

        $mailSent = false;

        if ($sendMail) {
            try {
                $this->notifier->dispatch(new Notification(
                    NotificationType::Invitation,
                    $invitation->prefillEmail,
                    $invitation->language,
                    $contractor,
                    [
                        'url' => $url,
                        'company' => $invitation->prefillCompany,
                        'template_title' => $template->title,
                        'expires_at' => $invitation->expiresAt,
                    ],
                ));

                $mailSent = true;
                $this->invitations->setMailStatus($invitationId, MailStatus::Sent, $now);
                $this->auditLogger->log(AuditEvent::InvitationSent, $invitationId);
            } catch (NotificationFailedException $e) {
                $this->invitations->setMailStatus($invitationId, MailStatus::Failed, $now);
                $this->auditLogger->log(AuditEvent::MailFailed, $invitationId, context: ['type' => NotificationType::Invitation->value]);
                $this->logger->error('Pactum: sending the invitation mail failed.', ['invitation' => $invitationId, 'exception' => $e]);
            }
        }

        $this->eventDispatcher->dispatch(new InvitationCreatedEvent($invitationId, $template->id, $mailSent));

        return new GeneratedLink($url, $sendMail, $mailSent);
    }

    /**
     * @throws InvalidStatusTransitionException
     */
    public function revoke(int $invitationId): void
    {
        $now = $this->clock->now()->getTimestamp();
        $invitation = $this->requireInvitation($invitationId);

        $this->stateMachine->assert($invitation->status, InvitationStatus::Revoked);

        if (!$this->invitations->transition($invitationId, $invitation->status, InvitationStatus::Revoked, $now, ['token_hash' => null])) {
            throw new InvalidStatusTransitionException(\sprintf('Invitation %d was changed concurrently.', $invitationId));
        }

        $this->auditLogger->log(AuditEvent::InvitationRevoked, $invitationId);
    }

    /**
     * Extends the expiry date by the given number of days (counted from today if
     * already expired). Expired invitations are re-opened.
     *
     * @throws InvalidStatusTransitionException
     */
    public function extend(int $invitationId, int $days): int
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('The number of days must be positive.');
        }

        $now = $this->clock->now()->getTimestamp();
        $invitation = $this->requireInvitation($invitationId);

        if ($invitation->status->isFinal()) {
            throw new InvalidStatusTransitionException(\sprintf('Invitation %d is %s and cannot be extended.', $invitationId, $invitation->status->value));
        }

        $base = (new \DateTimeImmutable('@'.max($now, $invitation->expiresAt)))->setTimezone($this->clock->now()->getTimezone());
        $expiresAt = $base->modify('+'.$days.' days')->setTime(23, 59, 59)->getTimestamp();

        if (InvitationStatus::Expired === $invitation->status) {
            // Re-open; a pending or confirmed opt-in has to be repeated
            $this->stateMachine->assert(InvitationStatus::Expired, InvitationStatus::Open);

            $this->invitations->transition(
                $invitationId,
                InvitationStatus::Expired,
                InvitationStatus::Open,
                $now,
                [
                    'expires_at' => $expiresAt,
                    'optin_identifier' => null,
                    'optin_requested_at' => 0,
                    'optin_confirmed_at' => 0,
                ],
            );
        } else {
            $this->invitations->updateExpiry($invitationId, $expiresAt, $now);
        }

        $this->auditLogger->log(AuditEvent::InvitationExtended, $invitationId, context: ['expires_at' => $expiresAt]);

        return $expiresAt;
    }

    /**
     * Marks active invitations past their expiry date as expired.
     *
     * @return int number of expired invitations
     */
    public function expireOverdue(): int
    {
        $now = $this->clock->now()->getTimestamp();
        $count = 0;

        foreach ($this->invitations->findExpiredActiveIds($now) as $id) {
            $invitation = $this->invitations->find($id);

            if (null === $invitation || !$invitation->status->isActive()) {
                continue;
            }

            if ($this->invitations->transition($id, $invitation->status, InvitationStatus::Expired, $now)) {
                $this->auditLogger->log(AuditEvent::InvitationExpired, $id);
                ++$count;
            }
        }

        return $count;
    }

    private function requireInvitation(int $id): Invitation
    {
        return $this->invitations->find($id) ?? throw new InvitationNotUsableException(\sprintf('Invitation %d does not exist.', $id));
    }
}
