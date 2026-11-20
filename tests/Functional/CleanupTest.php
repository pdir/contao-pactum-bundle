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

use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Invitation\InvitationService;
use Pdir\PactumBundle\Maintenance\Cleanup;
use Pdir\PactumBundle\Pdf\PdfStorage;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Data minimization: anonymization of stale invitations, removal of client data
 * after the retention period and the optional deletion of agreements.
 */
class CleanupTest extends DatabaseTestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86400;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDir = sys_get_temp_dir().'/pactum-cleanup-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->storageDir)) {
            return;
        }

        (new Filesystem())->remove($this->storageDir);
    }

    public function testStaleInvitationsAreAnonymized(): void
    {
        $stale = $this->createInvitation(InvitationStatus::Revoked, self::NOW - 31 * self::DAY);
        $recent = $this->createInvitation(InvitationStatus::Expired, self::NOW - 5 * self::DAY);
        $open = $this->createInvitation(InvitationStatus::Open, self::NOW - 60 * self::DAY);

        $report = $this->createCleanup()->run();

        $this->assertSame(1, $report->anonymizedInvitations);

        $row = $this->fetchInvitation($stale);
        $this->assertSame('', $row['company']);
        $this->assertSame('', $row['signatory_email']);
        $this->assertSame('', $row['prefill_email']);
        $this->assertNull($row['token_hash']);
        $this->assertSame(self::NOW, (int) $row['anonymized_at']);

        $this->assertSame('kim@example.com', $this->fetchInvitation($recent)['signatory_email']);
        $this->assertSame('kim@example.com', $this->fetchInvitation($open)['signatory_email']);

        // Running again does not touch anonymized invitations
        $this->assertSame(0, $this->createCleanup()->run()->anonymizedInvitations);
    }

    public function testClientDataIsRemovedAfterTheRetentionPeriod(): void
    {
        $repository = new AgreementRepository($this->getConnection());
        $due = $this->createAgreement($repository, ipDeleteAfter: self::NOW - 1);
        $notDue = $this->createAgreement($repository, ipDeleteAfter: self::NOW + self::DAY);

        $report = $this->createCleanup()->run();

        $this->assertSame(1, $report->purgedClientData);
        $this->assertSame('', $repository->find($due)?->ip);
        $this->assertSame('192.0.2.0', $repository->find($notDue)?->ip);

        // The evidence itself is kept
        $this->assertSame('Kunde AG', $repository->find($due)->customer->company);
    }

    public function testAgreementsAreKeptByDefault(): void
    {
        $repository = new AgreementRepository($this->getConnection());
        $id = $this->createAgreement($repository, consentedAt: self::NOW - 5000 * self::DAY);

        $this->assertSame(0, $this->createCleanup()->run()->deletedAgreements);
        $this->assertNotNull($repository->find($id));
    }

    public function testAgreementsAreDeletedAfterTheConfiguredRetention(): void
    {
        $repository = new AgreementRepository($this->getConnection());
        $storage = new PdfStorage($this->storageDir);

        $old = $this->createAgreement($repository, consentedAt: self::NOW - 400 * self::DAY, storage: $storage);
        $young = $this->createAgreement($repository, consentedAt: self::NOW - 10 * self::DAY, storage: $storage);
        $oldAgreement = $repository->find($old);

        $this->assertNotNull($oldAgreement);
        $this->assertTrue($storage->exists($oldAgreement->pdfPath));

        $report = $this->createCleanup(retentionDays: 365)->run();

        $this->assertSame(1, $report->deletedAgreements);
        $this->assertNull($repository->find($old));
        $this->assertNull((new InvitationRepository($this->getConnection()))->find($oldAgreement->invitationId));
        $this->assertFalse($storage->exists($oldAgreement->pdfPath));
        $this->assertNotNull($repository->find($young));
    }

    private function createCleanup(int|null $retentionDays = null): Cleanup
    {
        $connection = $this->getConnection();

        return new Cleanup(
            $connection,
            $this->createStub(InvitationService::class),
            new InvitationRepository($connection),
            new AgreementRepository($connection),
            new AuditLogRepository($connection),
            $this->createStub(AuditLogger::class),
            new PdfStorage($this->storageDir),
            new MockClock('@'.self::NOW),
            30,
            $retentionDays,
        );
    }

    private function createInvitation(InvitationStatus $status, int $tstamp): int
    {
        $this->getConnection()->insert(InvitationRepository::TABLE, [
            ...Factory::customer()->toRow(),
            'tstamp' => $tstamp,
            'created_at' => $tstamp,
            'template' => 10,
            'language' => 'de',
            'expires_at' => $tstamp,
            'status' => $status->value,
            'prefill_company' => 'Kunde AG',
            'prefill_email' => 'kim@example.com',
            'token_hash' => hash('sha256', (string) random_int(0, PHP_INT_MAX)),
        ]);

        return (int) $this->getConnection()->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchInvitation(int $id): array
    {
        return $this->getConnection()->fetchAssociative('SELECT * FROM '.InvitationRepository::TABLE.' WHERE id = ?', [$id]) ?: [];
    }

    private function createAgreement(AgreementRepository $repository, int $consentedAt = self::NOW, int $ipDeleteAfter = 0, PdfStorage|null $storage = null): int
    {
        $invitationId = $this->createInvitation(InvitationStatus::Completed, $consentedAt);
        $uuid = \sprintf('6f1d5a3e-0000-4000-8000-%012d', $invitationId);
        $pdfPath = $storage?->store($uuid, '%PDF-test', $consentedAt) ?? 'agreements/2027/01/'.$uuid.'.pdf';

        return $repository->insert(
            $uuid,
            $invitationId,
            10,
            100,
            'de',
            str_repeat('a', 64),
            str_repeat('b', 64),
            '<p>Vertrag</p>',
            $pdfPath,
            str_repeat('c', 64),
            Factory::customer(),
            $consentedAt - 100,
            $consentedAt,
            [],
            '192.0.2.0',
            '',
            $ipDeleteAfter,
        );
    }
}
