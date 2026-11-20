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

final class InvitationTokenGenerator
{
    public const BYTES = 32;

    /**
     * base64url without padding of 32 bytes = 43 characters.
     */
    private const PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public function generate(): InvitationToken
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');

        return new InvitationToken($plain, $this->hash($plain));
    }

    public function hash(#[\SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Cheap format check before touching the database.
     */
    public function isWellFormed(#[\SensitiveParameter] string $plain): bool
    {
        return 1 === preg_match(self::PATTERN, $plain);
    }
}
