<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Announcement;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Announcement\AnnouncementService;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Agreement;
use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Announcement;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Invitation\InvitationLinkBuilder;
use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\AgreementRepository;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class AnnouncementServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private MockObject&VersionRepository $versions;

    private AgreementRepository&MockObject $agreements;

    private AnnouncementRepository&MockObject $announcements;

    private MockObject&NotificationDispatcherInterface $notifier;

    private AuditLogger&MockObject $auditLogger;

    /**
     * @var list<Subprocessor>
     */
    private array $draftSubprocessors = [];

    protected function setUp(): void
    {
        $this->versions = $this->createMock(VersionRepository::class);
        $this->agreements = $this->createMock(AgreementRepository::class);
        $this->announcements = $this->createMock(AnnouncementRepository::class);
        $this->notifier = $this->createMock(NotificationDispatcherInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);

        $this->versions
            ->method('find')
            ->willReturn(Factory::version(annexIds: [5]))
        ;
        $this->versions
            ->method('findActive')
            ->willReturn($this->activeVersion([$this->sub('Alpha GmbH'), $this->sub('Beta GmbH')]))
        ;

        $this->draftSubprocessors = [$this->sub('Alpha GmbH'), $this->sub('Beta GmbH'), $this->sub('Neu GmbH')];
    }

    public function testPreviewComparesTheDraftWithTheActiveVersion(): void
    {
        $this->agreements
            ->method('findByTemplate')
            ->willReturn([$this->agreement(1, 'kim@example.com'), $this->agreement(2, 'other@example.com')])
        ;

        $preview = $this->createService()->preview(100);

        $this->assertSame(['Neu GmbH'], SubprocessorChanges::names($preview->changes->added));
        $this->assertSame(2, $preview->changes->unchanged);
        $this->assertCount(2, $preview->recipients);
        $this->assertSame(14, $preview->noticeDays);
        $this->assertSame(self::NOW + 14 * 86400, $preview->effectiveAt);
    }

    public function testRecipientsAreDeduplicatedByEmailKeepingTheNewestAgreement(): void
    {
        $this->agreements
            ->method('findByTemplate')
            ->willReturn([$this->agreement(3, 'Kim@Example.com'), $this->agreement(2, 'kim@example.com '), $this->agreement(1, 'other@example.com')])
        ;

        $recipients = $this->createService()->preview(100)->recipients;

        $this->assertSame([3, 1], array_map(static fn (Agreement $a): int => $a->id, $recipients));
    }

    public function testOnlyDraftsCanBeAnnounced(): void
    {
        $this->versions = $this->createMock(VersionRepository::class);
        $this->versions
            ->method('find')
            ->willReturn(Factory::version(VersionStatus::Published, rendered: 'x', hash: 'y'))
        ;

        $this->expectException(InvalidVersionException::class);

        $this->createService()->preview(100);
    }

    public function testAnActiveVersionIsRequired(): void
    {
        $this->versions = $this->createMock(VersionRepository::class);
        $this->versions
            ->method('find')
            ->willReturn(Factory::version(annexIds: [5]))
        ;
        $this->versions
            ->method('findActive')
            ->willReturn(null)
        ;

        $this->expectException(PactumException::class);
        $this->expectExceptionMessage('no published version');

        $this->createService()->preview(100);
    }

    public function testUnchangedSubprocessorsCannotBeAnnounced(): void
    {
        $this->draftSubprocessors = [$this->sub('Beta GmbH'), $this->sub('Alpha GmbH')];

        $this->expectException(PactumException::class);
        $this->expectExceptionMessage('do not differ');

        $this->createService()->preview(100);
    }

    public function testAnnouncementWithoutRecipientsIsRejected(): void
    {
        $this->agreements
            ->method('findByTemplate')
            ->willReturn([])
        ;

        $this->announcements
            ->expects($this->never())
            ->method('insert')
        ;

        $this->expectException(PactumException::class);

        $this->createService()->announce(100, 5);
    }

    public function testASecondAnnouncementOfTheSameDraftIsRejected(): void
    {
        $this->agreements
            ->method('findByTemplate')
            ->willReturn([$this->agreement(1, 'kim@example.com')])
        ;

        $this->announcements
            ->method('findLatestForVersion')
            ->willReturn(new Announcement(9, 'uuid', 10, 100, 'de', new SubprocessorChanges([], [], [], 0, false), 14, self::NOW, self::NOW, 5, 1, 0))
        ;
        $this->announcements
            ->expects($this->never())
            ->method('insert')
        ;

        $this->expectException(PactumException::class);
        $this->expectExceptionMessage('already been announced');

        $this->createService()->announce(100, 5);
    }

    public function testAnnounceStoresRecipientsAndSendsOneMailEach(): void
    {
        $this->agreements
            ->method('findByTemplate')
            ->willReturn([$this->agreement(1, 'kim@example.com'), $this->agreement(2, 'other@example.com')])
        ;

        $this->announcements
            ->expects($this->once())
            ->method('insert')
            ->with($this->anything(), 10, 100, 'de', $this->isInstanceOf(SubprocessorChanges::class), 14, self::NOW, self::NOW + 14 * 86400, 5)
            ->willReturn(9)
        ;

        $hashes = [];

        $this->announcements
            ->expects($this->exactly(2))
            ->method('insertRecipient')
            ->willReturnCallback(
                static function (int $announcementId, int $agreementId, string $company, string $name, string $email, string $language, string $hash) use (&$hashes): int {
                    $hashes[] = $hash;

                    return 20 + $agreementId;
                },
            )
        ;

        $statuses = [];

        $this->announcements
            ->method('setRecipientMailStatus')
            ->willReturnCallback(
                static function (int $id, MailStatus $status) use (&$statuses): void {
                    $statuses[$id] = $status;
                },
            )
        ;

        $mails = [];

        $this->notifier
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(
                static function (Notification $notification) use (&$mails): void {
                    $mails[] = $notification;
                },
            )
        ;

        $result = $this->createService()->announce(100, 5);

        $this->assertSame(9, $result->announcementId);
        $this->assertSame(2, $result->sent);
        $this->assertSame(0, $result->failed);
        $this->assertSame([21 => MailStatus::Sent, 22 => MailStatus::Sent], $statuses);

        $this->assertSame(NotificationType::SubprocessorAnnouncement, $mails[0]->type);
        $this->assertSame('kim@example.com', $mails[0]->recipient);
        $this->assertSame(self::NOW + 14 * 86400, $mails[0]->context['effective_at']);

        // Only hashes are stored, the plain token is only part of the link
        $this->assertCount(2, array_unique($hashes));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashes[0]);
        $this->assertStringStartsWith('https://example.org/avv?pactum_objection=', (string) $mails[0]->context['url']);
        $this->assertStringNotContainsString($hashes[0], (string) $mails[0]->context['url']);
    }

    public function testAFailedMailIsRecordedWithoutStoppingTheOthers(): void
    {
        $this->agreements
            ->method('findByTemplate')
            ->willReturn([$this->agreement(1, 'kim@example.com'), $this->agreement(2, 'other@example.com')])
        ;

        $this->announcements
            ->method('insert')
            ->willReturn(9)
        ;
        $this->announcements
            ->method('insertRecipient')
            ->willReturnCallback(static fn (int $announcementId, int $agreementId): int => 20 + $agreementId)
        ;

        $this->notifier
            ->method('dispatch')
            ->willReturnCallback(
                static function (Notification $notification): void {
                    if ('kim@example.com' === $notification->recipient) {
                        throw new NotificationFailedException('failed');
                    }
                },
            )
        ;

        $events = [];

        $this->auditLogger
            ->method('log')
            ->willReturnCallback(
                static function (AuditEvent $event) use (&$events): void {
                    $events[] = $event;
                },
            )
        ;

        $result = $this->createService()->announce(100, 5);

        $this->assertSame(1, $result->sent);
        $this->assertSame(1, $result->failed);
        $this->assertSame([AuditEvent::SubprocessorChangeAnnounced, AuditEvent::MailFailed, AuditEvent::SubprocessorAnnouncementSent], $events);
    }

    private function createService(): AnnouncementService
    {
        $connection = $this->createStub(Connection::class);
        $connection
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback($connection))
        ;

        $templates = $this->createStub(TemplateRepository::class);
        $templates
            ->method('find')
            ->willReturn(Factory::template())
        ;

        $contractors = $this->createStub(ContractorRepository::class);
        $contractors
            ->method('find')
            ->willReturn(Factory::contractor())
        ;

        $annexes = $this->createStub(AnnexRepository::class);
        $annexes
            ->method('findByIds')
            ->willReturn([new Annex(5, AnnexType::Subprocessor, 'Anlage 2', '', $this->draftSubprocessors)])
        ;

        $linkBuilder = $this->createStub(InvitationLinkBuilder::class);
        $linkBuilder
            ->method('buildObjection')
            ->willReturnCallback(static fn (int $pageId, string $token): string => 'https://example.org/avv?pactum_objection='.$token)
        ;

        return new AnnouncementService(
            $connection,
            $this->versions,
            $templates,
            $contractors,
            $annexes,
            $this->agreements,
            $this->announcements,
            new InvitationTokenGenerator(),
            $linkBuilder,
            $this->notifier,
            $this->auditLogger,
            new MockClock('@'.self::NOW),
            new NullLogger(),
            14,
        );
    }

    /**
     * @param list<Subprocessor> $subprocessors
     */
    private function activeVersion(array $subprocessors): Version
    {
        $snapshot = json_encode([
            (new Annex(1, AnnexType::Tom, 'Anlage 1', '<p>TOM</p>'))->toArray(),
            (new Annex(5, AnnexType::Subprocessor, 'Anlage 2', '', $subprocessors))->toArray(),
        ]);

        return new Version(90, 10, 2, 'de', 'x', null, VersionStatus::Published, '', [1, 5], 'x', (string) $snapshot, 'hash', 1_700_000_000);
    }

    private function agreement(int $id, string $email): Agreement
    {
        $customer = Factory::customer();
        $customer = new $customer($customer->company, $customer->street, $customer->postal, $customer->city, $customer->country, $customer->signatoryName, $customer->signatoryRole, $email);

        return new Agreement($id, 'uuid-'.$id, $id, 10, 90, 'de', 'vh', 'dh', '<p>x</p>', 'file.pdf', 'ph', $customer, 0, 1_700_000_000 + $id, [], '', '', MailStatus::Sent);
    }

    private function sub(string $name): Subprocessor
    {
        return new Subprocessor($name, 'Musterweg 1, 12345 Musterstadt', 'Hosting', Subprocessor::LOCATION_EU, '');
    }
}
