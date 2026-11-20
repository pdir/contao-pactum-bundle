<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Maintenance;

final readonly class CleanupReport
{
    public function __construct(
        public int $expiredInvitations,
        public int $anonymizedInvitations,
        public int $purgedClientData,
        public int $deletedAgreements,
    ) {
    }
}
