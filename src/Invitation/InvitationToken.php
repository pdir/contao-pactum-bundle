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

/**
 * A freshly generated invitation token. The plain value only exists in memory and
 * in the link sent to the customer; only the hash is persisted.
 */
final readonly class InvitationToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $plain,
        public string $hash,
    ) {
    }
}
