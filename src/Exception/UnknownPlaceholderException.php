<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Exception;

class UnknownPlaceholderException extends PactumException
{
    /**
     * @param list<string> $placeholders
     */
    public function __construct(public readonly array $placeholders)
    {
        parent::__construct('Unknown placeholders: '.implode(', ', $placeholders));
    }
}
