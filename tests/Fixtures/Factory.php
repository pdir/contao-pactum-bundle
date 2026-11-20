<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Fixtures;

use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\ContractTemplate;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;

/**
 * Test data builders. All data is fictitious.
 */
final class Factory
{
    public static function contractor(string $name = 'Muster Agentur GmbH', string $senderEmail = 'noreply@example.org'): Contractor
    {
        return new Contractor(1, $name, 'Musterstraße 1', '12345', 'Musterstadt', 'Deutschland', 'Erika Muster', 'Max Datenschutz', 'dsb@example.org', 'kontakt@example.org', $senderEmail, '', 'avv@example.org', null);
    }

    public static function template(): ContractTemplate
    {
        return new ContractTemplate(10, 'AVV Hosting', 1, 5, 0);
    }

    public static function customer(string $company = 'Kunde AG'): CustomerData
    {
        return new CustomerData($company, 'Kundenweg 2', '54321', 'Kundenstadt', 'Deutschland', 'Kim Kunde', 'Geschäftsführung', 'kim@example.com');
    }

    /**
     * @param list<int> $annexIds
     */
    public static function version(VersionStatus $status = VersionStatus::Draft, string $content = '<p>Vertrag mit {{contractor.name}}</p>', string|null $rendered = null, string|null $hash = null, int $id = 100, array $annexIds = []): Version
    {
        return new Version($id, 10, 3, 'de', $content, null, $status, '', $annexIds, $rendered, null === $rendered ? null : '[]', $hash, null === $rendered ? null : 1_700_000_000);
    }

    public static function invitation(InvitationStatus $status = InvitationStatus::OptedIn, int $expiresAt = 2_000_000_000, CustomerData|null $customer = null, string|null $optIn = null, int|null $optInRequestedAt = null): Invitation
    {
        return new Invitation(7, 10, 'de', str_repeat('a', 64), $expiresAt, $status, 'Kunde AG', 'kim@example.com', 1, $customer ?? self::customer(), $optIn, $optInRequestedAt, InvitationStatus::OptedIn === $status ? 1_700_000_100 : null);
    }
}
