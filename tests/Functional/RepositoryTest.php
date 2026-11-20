<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Functional;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\VersionLockedException;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;

/**
 * Checks the guarantees that are enforced by the database: conditional status
 * transitions, unique token hashes and one agreement per invitation.
 */
class RepositoryTest extends DatabaseTestCase
{
    public function testStatusTransitionsAreConditional(): void
    {
        $repository = new InvitationRepository($this->getConnection());
        $id = $this->createInvitation();

        $this->assertTrue($repository->transition($id, InvitationStatus::Open, InvitationStatus::OptedIn, 1_800_000_000));

        // A concurrent request that still assumes "open" must not succeed
        $this->assertFalse($repository->transition($id, InvitationStatus::Open, InvitationStatus::Revoked, 1_800_000_001));
        $this->assertSame(InvitationStatus::OptedIn, $repository->find($id)?->status);
    }

    public function testCustomerDataIsOnlyStoredWhileOpen(): void
    {
        $repository = new InvitationRepository($this->getConnection());
        $id = $this->createInvitation(InvitationStatus::Revoked);

        $this->assertFalse($repository->saveCustomerData($id, Factory::customer(), 'pact-x', 1_800_000_000));
    }

    public function testTokenLookupUsesTheHash(): void
    {
        $repository = new InvitationRepository($this->getConnection());
        $id = $this->createInvitation();

        $repository->replaceToken($id, str_repeat('c', 64), 1_800_000_000);

        $this->assertSame($id, $repository->findByTokenHash(str_repeat('c', 64))?->id);
        $this->assertNull($repository->findByTokenHash(str_repeat('a', 64)));
    }

    public function testTokenHashesAreUnique(): void
    {
        $repository = new InvitationRepository($this->getConnection());
        $repository->replaceToken($this->createInvitation(), str_repeat('d', 64), 1_800_000_000);

        $this->expectException(UniqueConstraintViolationException::class);

        $repository->replaceToken($this->createInvitation(), str_repeat('d', 64), 1_800_000_000);
    }

    public function testOnlyOneAgreementPerInvitation(): void
    {
        $repository = new AgreementRepository($this->getConnection());
        $invitationId = $this->createInvitation(InvitationStatus::Completed);

        $this->insertAgreement($repository, $invitationId, '6f1d5a3e-0000-4000-8000-000000000001');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertAgreement($repository, $invitationId, '6f1d5a3e-0000-4000-8000-000000000002');
    }

    public function testAgreementRoundTrip(): void
    {
        $repository = new AgreementRepository($this->getConnection());
        $id = $this->insertAgreement($repository, $this->createInvitation(InvitationStatus::Completed), '6f1d5a3e-0000-4000-8000-000000000003');

        $agreement = $repository->find($id);

        $this->assertNotNull($agreement);
        $this->assertSame('Kunde AG', $agreement->customer->company);
        $this->assertSame(['read' => 'Gelesen'], $agreement->consentTexts);
        $this->assertSame('192.0.2.0', $agreement->ip);
    }

    public function testPublishedVersionsCannotBePublishedAgain(): void
    {
        $repository = new VersionRepository($this->getConnection());
        $id = $repository->insertDraft(10, 1, 'de', '<p>Text</p>', [3, 4], 1_800_000_000);

        $repository->markPublished($id, '<p>Text</p>', '[]', str_repeat('e', 64), 0, 1_800_000_000, 1);

        $version = $repository->find($id);
        $this->assertSame(VersionStatus::Published, $version?->status);
        $this->assertSame([3, 4], $version->annexIds);

        $this->expectException(VersionLockedException::class);

        $repository->markPublished($id, '<p>Anders</p>', '[]', str_repeat('f', 64), 0, 1_800_000_001, 1);
    }

    private function createInvitation(InvitationStatus $status = InvitationStatus::Open): int
    {
        $this->getConnection()->insert(InvitationRepository::TABLE, [
            'tstamp' => 1_800_000_000,
            'created_at' => 1_800_000_000,
            'template' => 10,
            'language' => 'de',
            'expires_at' => 2_000_000_000,
            'status' => $status->value,
        ]);

        return (int) $this->getConnection()->lastInsertId();
    }

    private function insertAgreement(AgreementRepository $repository, int $invitationId, string $uuid): int
    {
        return $repository->insert(
            $uuid,
            $invitationId,
            10,
            100,
            'de',
            str_repeat('a', 64),
            str_repeat('b', 64),
            '<p>Vertrag</p>',
            'agreements/2027/01/'.$uuid.'.pdf',
            str_repeat('c', 64),
            Factory::customer(),
            1_799_999_000,
            1_800_000_000,
            ['read' => 'Gelesen'],
            '192.0.2.0',
            '',
            1_807_776_000,
        );
    }
}
