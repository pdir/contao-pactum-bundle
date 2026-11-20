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
 * Customer (Auftraggeber) and signatory data entered in the frontend.
 */
final readonly class CustomerData
{
    public function __construct(
        public string $company,
        public string $street,
        public string $postal,
        public string $city,
        public string $country,
        public string $signatoryName,
        public string $signatoryRole,
        public string $signatoryEmail,
    ) {
    }

    /**
     * @return array{company: string, street: string, postal: string, city: string, country: string, signatory_name: string, signatory_role: string, signatory_email: string}
     */
    public function toRow(): array
    {
        return [
            'company' => $this->company,
            'street' => $this->street,
            'postal' => $this->postal,
            'city' => $this->city,
            'country' => $this->country,
            'signatory_name' => $this->signatoryName,
            'signatory_role' => $this->signatoryRole,
            'signatory_email' => $this->signatoryEmail,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['company'] ?? ''),
            (string) ($row['street'] ?? ''),
            (string) ($row['postal'] ?? ''),
            (string) ($row['city'] ?? ''),
            (string) ($row['country'] ?? ''),
            (string) ($row['signatory_name'] ?? ''),
            (string) ($row['signatory_role'] ?? ''),
            (string) ($row['signatory_email'] ?? ''),
        );
    }

    /**
     * Returns empty personal data, used for anonymization.
     *
     * @return array{company: string, street: string, postal: string, city: string, country: string, signatory_name: string, signatory_role: string, signatory_email: string}
     */
    public static function emptyRow(): array
    {
        return (new self('', '', '', '', '', '', '', ''))->toRow();
    }

    public function isEmpty(): bool
    {
        return '' === $this->company && '' === $this->signatoryEmail;
    }
}
