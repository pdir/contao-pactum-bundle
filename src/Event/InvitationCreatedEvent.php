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

/**
 * Dispatched after a new invitation link was generated (also when re-sent). The
 * plain token is intentionally not part of the event.
 */
final class InvitationCreatedEvent
{
    public function __construct(
        public readonly int $invitationId,
        public readonly int $templateId,
        public readonly bool $sentByMail,
    ) {
    }
}
