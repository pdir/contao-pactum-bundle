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

use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;

/**
 * Announcement of a sub-processor change to existing customers (Art. 28(2) GDPR).
 * Customers can object until "effectiveAt".
 */
final readonly class Announcement
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $templateId,
        public int $versionId,
        public string $language,
        public SubprocessorChanges $changes,
        public int $noticeDays,
        public int $sentAt,
        public int $effectiveAt,
        public int $createdBy,
        public int $recipientCount,
        public int $objectionCount,
    ) {
    }

    public function isObjectionPeriodOpen(int $now): bool
    {
        return $now <= $this->effectiveAt;
    }
}
