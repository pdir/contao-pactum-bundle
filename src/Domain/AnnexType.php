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

enum AnnexType: string
{
    case Tom = 'tom';
    case Subprocessor = 'subprocessor';
    case Custom = 'custom';
}
