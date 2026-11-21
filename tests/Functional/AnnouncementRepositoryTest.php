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
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;

class AnnouncementRepositoryTest extends DatabaseTestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86400;

    public function testAnnouncementAndChangesAreStored(): void
    {
        $repository = new AnnouncementRepository($this->getConnection());
        $added = new Subprocessor('Neu GmbH', "Weg 1\n01662 Meißen", 'Hosting', Subprocessor::LOCATION_EU, '');
        $removed = new Subprocessor('Alt Inc.', 'Main St 1, Boston', 'Mail', Subprocessor::LOCATION_THIRD_COUNTRY, 'DPF');

        $id = $this->createAnnouncement($repository, new SubprocessorChanges([$added], [], [$removed], 3, false));
        $announcement = $repository->find($id);

        $this->assertNotNull($announcement);
        $this->assertSame(self::NOW + 14 * self::DAY, $announcement->effectiveAt);
        $this->assertSame([$added->toArray()], $announcement->changes->toArray()['added']);
        $this->assertSame([$removed->toArray()], $announcement->changes->toArray()['removed']);
        $this->assertSame(3, $announcement->changes->unchanged);
        $this->assertSame($id, $repository->findLatestForVersion(5)?->id);
        $this->assertNull($repository->findLatestForVersion(6));
    }

    public function testRecipientsAreCountedAndFoundByTokenHash(): void
    {
        $repository = new AnnouncementRepository($this->getConnection());
        $id = $this->createAnnouncement($repository);

        $recipientId = $repository->insertRecipient($id, 7, 'Kunde AG', 'Max Muster', 'max@example.com', 'de', hash('sha256', 'token'), self::NOW);
        $repository->setRecipientMailStatus($recipientId, MailStatus::Sent, self::NOW);

        $recipient = $repository->findRecipientByTokenHash(hash('sha256', 'token'));

        $this->assertNotNull($recipient);
        $this->assertSame($recipientId, $recipient->id);
        $this->assertSame(MailStatus::Sent, $recipient->mailStatus);
        $this->assertFalse($recipient->hasObjected());
        $this->assertSame(1, $repository->find($id)?->recipientCount);
        $this->assertNull($repository->findRecipientByTokenHash(hash('sha256', 'other')));
    }

    public function testTokenHashIsUnique(): void
    {
        $repository = new AnnouncementRepository($this->getConnection());
        $id = $this->createAnnouncement($repository);

        $repository->insertRecipient($id, 7, 'Kunde AG', '', 'a@example.com', 'de', str_repeat('a', 64), self::NOW);

        $this->expectException(UniqueConstraintViolationException::class);

        $repository->insertRecipient($id, 8, 'Andere AG', '', 'b@example.com', 'de', str_repeat('a', 64), self::NOW);
    }

    public function testObjectionIsRecordedOnlyOnce(): void
    {
        $repository = new AnnouncementRepository($this->getConnection());
        $id = $this->createAnnouncement($repository);
        $recipientId = $repository->insertRecipient($id, 7, 'Kunde AG', '', 'a@example.com', 'de', str_repeat('a', 64), self::NOW);

        $this->assertTrue($repository->recordObjection($recipientId, 'Max Muster', ['Neu GmbH'], 'Bedenken', self::NOW + self::DAY));
        $this->assertFalse($repository->recordObjection($recipientId, 'Erika Muster', ['Neu GmbH'], 'Nochmal', self::NOW + 2 * self::DAY));

        $recipient = $repository->findRecipient($recipientId);

        $this->assertNotNull($recipient);
        $this->assertTrue($recipient->hasObjected());
        $this->assertSame(self::NOW + self::DAY, $recipient->objectedAt);
        $this->assertSame('Max Muster', $recipient->objectionName);
        $this->assertSame(['Neu GmbH'], $recipient->objectionSubprocessors);
        $this->assertSame('Bedenken', $recipient->objectionReason);
        $this->assertSame(1, $repository->find($id)?->objectionCount);
    }

    public function testObjectionAfterThePeriodIsRejected(): void
    {
        $repository = new AnnouncementRepository($this->getConnection());
        $id = $this->createAnnouncement($repository);
        $recipientId = $repository->insertRecipient($id, 7, 'Kunde AG', '', 'a@example.com', 'de', str_repeat('a', 64), self::NOW);

        $this->assertFalse($repository->recordObjection($recipientId, 'Max Muster', ['Neu GmbH'], 'Zu spät', self::NOW + 15 * self::DAY));
        $this->assertFalse($repository->findRecipient($recipientId)?->hasObjected());
        $this->assertSame(0, $repository->find($id)?->objectionCount);
    }

    public function testRecipientsAreDeletedWithTheAgreement(): void
    {
        $repository = new AnnouncementRepository($this->getConnection());
        $id = $this->createAnnouncement($repository);

        $repository->insertRecipient($id, 7, 'Kunde AG', '', 'a@example.com', 'de', str_repeat('a', 64), self::NOW);
        $repository->insertRecipient($id, 8, 'Andere AG', '', 'b@example.com', 'de', str_repeat('b', 64), self::NOW);

        $repository->deleteRecipientsForAgreement(7);

        $remaining = $repository->findRecipients($id);

        $this->assertCount(1, $remaining);
        $this->assertSame(8, $remaining[0]->agreementId);
    }

    private function createAnnouncement(AnnouncementRepository $repository, SubprocessorChanges|null $changes = null): int
    {
        return $repository->insert(
            'b2f1c7c0-3c9a-4a4e-8d6c-0a2b9e1f5d10',
            10,
            5,
            'de',
            $changes ?? new SubprocessorChanges([new Subprocessor('Neu GmbH', '', 'Hosting', Subprocessor::LOCATION_EU, '')], [], [], 0, false),
            14,
            self::NOW,
            self::NOW + 14 * self::DAY,
            1,
        );
    }
}
