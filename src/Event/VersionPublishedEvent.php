<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Event;

use Pdir\PactumBundle\Domain\Version;

/**
 * Dispatched after a version was published. Hook point for re-consent (1.1).
 */
final class VersionPublishedEvent
{
    /**
     * @param list<int> $archivedVersionIds
     */
    public function __construct(
        public readonly Version $version,
        public readonly array $archivedVersionIds,
    ) {
    }
}
