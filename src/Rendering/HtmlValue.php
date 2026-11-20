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

/**
 * A placeholder value that contains markup. It is sanitized before insertion;
 * plain strings are always HTML-escaped instead.
 */
final readonly class HtmlValue
{
    public function __construct(public string $html)
    {
    }
}
