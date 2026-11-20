<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Pdf;

use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Version;

final readonly class PdfDocumentData
{
    /**
     * @param array<string, string> $consentTexts
     */
    public function __construct(
        public Contractor $contractor,
        public string $templateTitle,
        public Version $version,
        public CustomerData $customer,
        public string $documentHtml,
        public bool $preview,
        public string $reference = '',
        public int $optInAt = 0,
        public int $consentedAt = 0,
        public string $documentHash = '',
        public array $consentTexts = [],
    ) {
    }
}
