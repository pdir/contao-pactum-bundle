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

/**
 * The agency/service provider operating Pactum (Auftragnehmer).
 */
final readonly class Contractor
{
    public function __construct(
        public int $id,
        public string $name,
        public string $street,
        public string $postal,
        public string $city,
        public string $country,
        public string $representative,
        public string $dpoName,
        public string $dpoContact,
        public string $contactEmail,
        public string $senderEmail,
        public string $senderName,
        public string $bccEmail,
        public string|null $logo,
    ) {
    }

    /**
     * Required fields that are empty. An empty list means the profile is complete.
     *
     * @return list<string>
     */
    public function getMissingRequiredFields(): array
    {
        $required = [
            'name' => $this->name,
            'street' => $this->street,
            'postal' => $this->postal,
            'city' => $this->city,
            'representative' => $this->representative,
            'contactEmail' => $this->contactEmail,
            'senderEmail' => $this->senderEmail,
        ];

        return array_keys(array_filter($required, static fn (string $v): bool => '' === trim($v)));
    }

    public function isComplete(): bool
    {
        return [] === $this->getMissingRequiredFields();
    }

    /**
     * Recipient for the internal "agreement concluded" notification.
     */
    public function getNotificationRecipient(): string
    {
        return '' !== $this->bccEmail ? $this->bccEmail : $this->contactEmail;
    }
}
