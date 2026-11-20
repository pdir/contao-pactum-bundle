<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Agreement;

final readonly class VerificationCheck
{
    /**
     * @param bool $critical a failed critical check means the evidence is compromised
     */
    public function __construct(
        public string $name,
        public bool $passed,
        public bool $critical = true,
        public string $details = '',
    ) {
    }
}
