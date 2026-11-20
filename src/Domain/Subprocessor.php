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

final readonly class Subprocessor
{
    public const LOCATION_EU = 'eu';

    public const LOCATION_THIRD_COUNTRY = 'third_country';

    public function __construct(
        public string $name,
        public string $address,
        public string $service,
        public string $location,
        public string $transferBasis,
    ) {
    }

    public function isThirdCountry(): bool
    {
        return self::LOCATION_THIRD_COUNTRY === $this->location;
    }

    /**
     * @return array{name: string, address: string, service: string, location: string, transferBasis: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'service' => $this->service,
            'location' => $this->location,
            'transferBasis' => $this->transferBasis,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? ''),
            (string) ($data['address'] ?? ''),
            (string) ($data['service'] ?? ''),
            (string) ($data['location'] ?? self::LOCATION_EU),
            (string) ($data['transferBasis'] ?? ''),
        );
    }
}
