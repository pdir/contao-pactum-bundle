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

enum PlaceholderStage: string
{
    /**
     * Resolved when a version is published (contractor, version, annexes). The result
     * is frozen and hashed.
     */
    case Publish = 'publish';

    /**
     * Resolved when the document is shown to/concluded by a customer.
     */
    case Agreement = 'agreement';
}
