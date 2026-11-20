<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Rendering;

use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\ContractTemplate;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Version;

final readonly class PlaceholderContext
{
    public function __construct(
        public string $locale,
        public Contractor|null $contractor = null,
        public ContractTemplate|null $template = null,
        public Version|null $version = null,
        public CustomerData|null $customer = null,
        public \DateTimeImmutable|null $agreementDate = null,
    ) {
    }
}
