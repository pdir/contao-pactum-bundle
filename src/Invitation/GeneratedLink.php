<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Invitation;

final readonly class GeneratedLink
{
    public function __construct(
        #[\SensitiveParameter]
        public string $url,
        public bool $mailRequested,
        public bool $mailSent,
    ) {
    }
}
