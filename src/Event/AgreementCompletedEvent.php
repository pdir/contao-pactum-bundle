<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Event;

use Pdir\PactumBundle\Domain\Agreement;

/**
 * Dispatched after an agreement was concluded and persisted (incl. PDF). Hook
 * point for webhooks, CRM sync etc. (planned for later versions).
 */
final class AgreementCompletedEvent
{
    public function __construct(public readonly Agreement $agreement)
    {
    }
}
