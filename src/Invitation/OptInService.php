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

use Contao\CoreBundle\OptIn\OptIn;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Event\OptInConfirmedEvent;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;
use Pdir\PactumBundle\Exception\InvitationNotUsableException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Double opt-in of the signatory e-mail address, based on the Contao core opt-in
 * service (tokens, validity, confirmation). The mail itself is sent via Pactum's
 * notification dispatcher (HTML + text, contractor as sender).
 *
 * The confirmation link contains both the invitation token and the opt-in
 * identifier; a confirmation is only accepted if both match.
 */
class OptInService
{
    public const TOKEN_PREFIX = 'pact';

    public function __construct(
        #[Autowire(service: 'contao.opt_in')]
        private readonly OptIn $optIn,
        private readonly InvitationRepository $invitations,
        private readonly TemplateRepository $templates,
        private readonly ContractorRepository $contractors,
        private readonly InvitationLinkBuilder $linkBuilder,
        private readonly InvitationStateMachine $stateMachine,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly AuditLogger $auditLogger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $optInTtlHours,
    ) {
    }

    /**
     * Stores the customer data and sends the opt-in mail.
     *
     * @throws InvalidStatusTransitionException
     * @throws NotificationFailedException
     */
    public function request(Invitation $invitation, CustomerData $customer, #[\SensitiveParameter] string $plainToken): void
    {
        if (InvitationStatus::OptedIn === $invitation->status) {
            // Customer wants to change the data: back to "open", new opt-in required
            $this->stateMachine->assert(InvitationStatus::OptedIn, InvitationStatus::Open);
            $this->invitations->transition($invitation->id, InvitationStatus::OptedIn, InvitationStatus::Open, $this->now());
            $this->auditLogger->log(AuditEvent::CustomerDataReopened, $invitation->id);
        } elseif (InvitationStatus::Open !== $invitation->status) {
            throw new InvalidStatusTransitionException(\sprintf('Invitation %d does not accept customer data.', $invitation->id));
        }

        $identifier = $this->createToken($customer->signatoryEmail);

        if (!$this->invitations->saveCustomerData($invitation->id, $customer, $identifier, $this->now())) {
            throw new InvalidStatusTransitionException(\sprintf('Invitation %d was changed concurrently.', $invitation->id));
        }

        $this->auditLogger->log(AuditEvent::CustomerDataSubmitted, $invitation->id);

        $this->send($invitation, $customer, $identifier, $plainToken);
    }

    /**
     * Re-sends the opt-in mail for the pending request (new identifier).
     *
     * @throws NotificationFailedException
     */
    public function resend(Invitation $invitation, #[\SensitiveParameter] string $plainToken): void
    {
        if (!$invitation->hasPendingOptIn() || '' === $invitation->customer->signatoryEmail) {
            throw new InvitationNotUsableException(\sprintf('Invitation %d has no pending opt-in.', $invitation->id));
        }

        $identifier = $this->createToken($invitation->customer->signatoryEmail);

        $this->invitations->updateOptInIdentifier($invitation->id, $identifier, $this->now());

        $this->send($invitation, $invitation->customer, $identifier, $plainToken);
    }

    /**
     * Confirms the opt-in. Returns false for any invalid, expired or already used
     * identifier (the caller shows a neutral message).
     */
    public function confirm(Invitation $invitation, string $identifier): bool
    {
        if (
            InvitationStatus::Open !== $invitation->status
            || null === $invitation->optInIdentifier
            || !hash_equals($invitation->optInIdentifier, $identifier)
        ) {
            return false;
        }

        $token = $this->optIn->find($identifier);

        if (null === $token || !$token->isValid() || $token->isConfirmed()) {
            return false;
        }

        $now = $this->now();

        if (!$this->invitations->transition($invitation->id, InvitationStatus::Open, InvitationStatus::OptedIn, $now, ['optin_confirmed_at' => $now])) {
            return false;
        }

        $token->confirm();

        $this->auditLogger->log(AuditEvent::OptInConfirmed, $invitation->id);
        $this->eventDispatcher->dispatch(new OptInConfirmedEvent($invitation->id));

        return true;
    }

    private function createToken(string $email): string
    {
        $validUntil = $this->clock->now()->modify('+'.$this->optInTtlHours.' hours');

        // No related records: the core clean-up removes the token after expiry
        return $this->optIn->create(self::TOKEN_PREFIX, $email, [], $validUntil)->getIdentifier();
    }

    /**
     * @throws NotificationFailedException
     */
    private function send(Invitation $invitation, CustomerData $customer, string $identifier, #[\SensitiveParameter] string $plainToken): void
    {
        $template = $this->templates->find($invitation->templateId) ?? throw new InvitationNotUsableException(\sprintf('Template of invitation %d does not exist.', $invitation->id));
        $contractor = $this->contractors->find($template->contractorId) ?? throw new InvitationNotUsableException(\sprintf('Contractor of template %d does not exist.', $template->id));

        $url = $this->linkBuilder->build($template->targetPage, $plainToken, [InvitationLinkBuilder::OPTIN_PARAMETER => $identifier]);

        try {
            $this->notifier->dispatch(new Notification(
                NotificationType::OptIn,
                $customer->signatoryEmail,
                $invitation->language,
                $contractor,
                [
                    'url' => $url,
                    'company' => $customer->company,
                    'signatory_name' => $customer->signatoryName,
                    'template_title' => $template->title,
                    'valid_hours' => $this->optInTtlHours,
                ],
            ));
        } catch (NotificationFailedException $e) {
            $this->auditLogger->log(AuditEvent::MailFailed, $invitation->id, context: ['type' => NotificationType::OptIn->value]);
            $this->logger->error('Pactum: sending the opt-in mail failed.', ['invitation' => $invitation->id, 'exception' => $e]);

            throw $e;
        }

        $this->auditLogger->log(AuditEvent::OptInRequested, $invitation->id);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
