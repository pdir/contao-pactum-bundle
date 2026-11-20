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

final readonly class AnnouncementRecipient
{
    /**
     * @param list<string> $objectionSubprocessors
     */
    public function __construct(
        public int $id,
        public int $announcementId,
        public int $agreementId,
        public string $company,
        public string $signatoryName,
        public string $email,
        public string $language,
        public MailStatus $mailStatus,
        public int $objectedAt,
        public string $objectionName,
        public array $objectionSubprocessors,
        public string $objectionReason,
    ) {
    }

    public function hasObjected(): bool
    {
        return $this->objectedAt > 0;
    }
}
