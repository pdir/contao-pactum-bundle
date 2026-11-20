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

use Pdir\PactumBundle\Domain\Announcement;
use Pdir\PactumBundle\Domain\AnnouncementRecipient;
use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\ContractTemplate;

/**
 * Everything the objection page of one recipient needs.
 */
final readonly class ObjectionCase
{
    public function __construct(
        public AnnouncementRecipient $recipient,
        public Announcement $announcement,
        public ContractTemplate $template,
        public Contractor $contractor,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getObjectableNames(): array
    {
        return array_map(static fn ($s): string => $s->name, $this->announcement->changes->getObjectable());
    }
}
