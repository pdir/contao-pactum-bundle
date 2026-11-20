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

final readonly class Agreement
{
    /**
     * @param array<string, string> $consentTexts
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public int $invitationId,
        public int $templateId,
        public int $versionId,
        public string $language,
        public string $versionHash,
        public string $documentHash,
        public string $documentHtml,
        public string $pdfPath,
        public string $pdfHash,
        public CustomerData $customer,
        public int $optInAt,
        public int $consentedAt,
        public array $consentTexts,
        public string $ip,
        public string $userAgent,
        public MailStatus $mailStatus,
    ) {
    }
}
