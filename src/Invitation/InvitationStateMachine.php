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

use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;

/**
 * Defines which status changes of an invitation are allowed.
 *
 *   open ──opt-in──> opted_in ──consent──> completed
 *    ▲ └────────────────┘ (edit data)
 *    │ extend
 *   expired <── open|opted_in (time) ; revoked <── open|opted_in|expired
 */
final class InvitationStateMachine
{
    private const TRANSITIONS = [
        'open' => [InvitationStatus::OptedIn, InvitationStatus::Expired, InvitationStatus::Revoked],
        'opted_in' => [InvitationStatus::Open, InvitationStatus::Completed, InvitationStatus::Expired, InvitationStatus::Revoked],
        'expired' => [InvitationStatus::Open, InvitationStatus::Revoked],
        'completed' => [],
        'revoked' => [],
    ];

    public function can(InvitationStatus $from, InvitationStatus $to): bool
    {
        return \in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * @throws InvalidStatusTransitionException
     */
    public function assert(InvitationStatus $from, InvitationStatus $to): void
    {
        if (!$this->can($from, $to)) {
            throw new InvalidStatusTransitionException(\sprintf('Invitation status transition %s -> %s is not allowed.', $from->value, $to->value));
        }
    }

    /**
     * @return list<InvitationStatus>
     */
    public function getAllowedTransitions(InvitationStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
