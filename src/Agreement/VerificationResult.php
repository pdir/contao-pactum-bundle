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

final readonly class VerificationResult
{
    /**
     * @param list<VerificationCheck> $checks
     */
    public function __construct(
        public int $agreementId,
        public array $checks,
    ) {
    }

    public function isValid(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->critical && !$check->passed) {
                return false;
            }
        }

        return [] !== $this->checks;
    }
}
