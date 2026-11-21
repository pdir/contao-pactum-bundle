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

use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\ContractTemplate;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;

/**
 * What would be announced for a draft version: the sub-processor changes against
 * the active version and the customers that would be informed.
 */
final readonly class AnnouncementPreview
{
    /**
     * @param list<Agreement> $recipients one agreement per customer e-mail address (the newest)
     */
    public function __construct(
        public Version $draft,
        public Version $activeVersion,
        public ContractTemplate $template,
        public Contractor $contractor,
        public SubprocessorChanges $changes,
        public array $recipients,
        public int $noticeDays,
        public int $effectiveAt,
    ) {
    }
}
