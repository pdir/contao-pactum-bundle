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

enum InvitationStatus: string
{
    case Open = 'open';
    case OptedIn = 'opted_in';
    case Completed = 'completed';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Revoked => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return self::Open === $this || self::OptedIn === $this;
    }
}
