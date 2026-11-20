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

final readonly class AnnouncementResult
{
    public function __construct(
        public int $announcementId,
        public int $sent,
        public int $failed,
        public int $effectiveAt,
    ) {
    }
}
