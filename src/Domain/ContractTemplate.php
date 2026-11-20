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

final readonly class ContractTemplate
{
    public function __construct(
        public int $id,
        public string $title,
        public int $contractorId,
        public int $targetPage,
        public int $invitationTtlDays,
    ) {
    }
}
