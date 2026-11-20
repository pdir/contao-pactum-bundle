<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Domain;

final readonly class Invitation
{
    public function __construct(
        public int $id,
        public int $templateId,
        public string $language,
        public string|null $tokenHash,
        public int $expiresAt,
        public InvitationStatus $status,
        public string $prefillCompany,
        public string $prefillEmail,
        public int $createdBy,
        public CustomerData $customer,
        public string|null $optInIdentifier,
        public int|null $optInRequestedAt,
        public int|null $optInConfirmedAt,
    ) {
    }

    public function isExpiredAt(int $timestamp): bool
    {
        return $this->expiresAt <= $timestamp;
    }

    /**
     * The status taking the expiry date into account (expiry is applied lazily).
     */
    public function getEffectiveStatus(int $now): InvitationStatus
    {
        if ($this->status->isActive() && $this->isExpiredAt($now)) {
            return InvitationStatus::Expired;
        }

        return $this->status;
    }

    public function hasPendingOptIn(): bool
    {
        return InvitationStatus::Open === $this->status && null !== $this->optInRequestedAt;
    }
}
