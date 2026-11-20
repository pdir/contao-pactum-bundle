<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Notification;

use Pdir\PactumBundle\Domain\Contractor;

final readonly class Notification
{
    /**
     * @param array<string, mixed>                                  $context     template variables
     * @param list<array{path: string, name: string, type: string}> $attachments
     */
    public function __construct(
        public NotificationType $type,
        public string $recipient,
        public string $locale,
        public Contractor $contractor,
        public array $context = [],
        public array $attachments = [],
    ) {
    }
}
