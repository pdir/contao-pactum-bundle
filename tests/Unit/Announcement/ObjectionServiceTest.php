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

use Pdir\PactumBundle\Announcement\ObjectionCase;
use Pdir\PactumBundle\Announcement\ObjectionService;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Announcement;
use Pdir\PactumBundle\Domain\AnnouncementRecipient;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\AnnouncementRepository;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Subprocessor\SubprocessorChanges;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class ObjectionServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private AnnouncementRepository&MockObject $announcements;

    private MockObject&NotificationDispatcherInterface $notifier;

    private AuditLogger&MockObject $auditLogger;

    protected function setUp(): void
    {
        $this->announcements = $this->createMock(AnnouncementRepository::class);
        $this->notifier = $this->createMock(NotificationDispatcherInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
    }

    public function testMalformedTokensDoNotHitTheDatabase(): void
    {
        $this->announcements
            ->expects($this->never())
            ->method('findRecipientByTokenHash')
        ;

        $this->assertNull($this->createService()->findByToken('not-a-token'));
    }

    public function testTokenIsLookedUpByItsHash(): void
    {
        $token = (new InvitationTokenGenerator())->generate();

        $this->announcements
            ->expects($this->once())
            ->method('findRecipientByTokenHash')
            ->with($token->hash)
            ->willReturn($this->recipient())
        ;
        $this->announcements
            ->method('find')
            ->willReturn($this->announcement())
        ;

        $case = $this->createService()->findByToken($token->plain);

        $this->assertNotNull($case);
        $this->assertSame(['Neu GmbH', 'Geändert AG'], $case->getObjectableNames());
    }

    public function testPeriodIsOpenUntilTheEffectiveDate(): void
    {
        $service = $this->createService();

        $this->assertTrue($service->isOpen($this->case(effectiveAt: self::NOW)));
        $this->assertFalse($service->isOpen($this->case(effectiveAt: self::NOW - 1)));
        $this->assertFalse($service->isOpen($this->case(objectedAt: self::NOW - 10)));
    }

    public function testObjectionIsRecordedAndTheContractorIsNotified(): void
    {
        $this->announcements
            ->expects($this->once())
            ->method('recordObjection')
            ->with(30, 'Kim Kunde', ['Neu GmbH'], 'Datenschutzbedenken', self::NOW)
            ->willReturn(true)
        ;

        $this->auditLogger
            ->expects($this->once())
            ->method('log')
            ->with(AuditEvent::SubprocessorObjectionReceived, 0, 7)
        ;

        $this->notifier
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (Notification $n): bool => NotificationType::SubprocessorObjection === $n->type
                && 'avv@example.org' === $n->recipient
                && 'Datenschutzbedenken' === $n->context['reason']))
        ;

        $this->assertTrue($this->createService()->submit($this->case(), ['Neu GmbH', 'Neu GmbH'], ' Kim Kunde ', " Datenschutzbedenken\n"));
    }

    public function testOnlyAnnouncedSubprocessorsCanBeObjected(): void
    {
        $this->announcements
            ->expects($this->never())
            ->method('recordObjection')
        ;

        $this->expectException(PactumException::class);

        // Removed entries cannot be objected to
        $this->createService()->submit($this->case(), ['Alt GmbH'], 'Kim Kunde', 'Grund');
    }

    public function testReasonIsRequired(): void
    {
        $this->expectException(PactumException::class);

        $this->createService()->submit($this->case(), ['Neu GmbH'], 'Kim Kunde', '   ');
    }

    public function testRejectedUpdateSendsNoMail(): void
    {
        $this->announcements
            ->method('recordObjection')
            ->willReturn(false)
        ;

        $this->notifier
            ->expects($this->never())
            ->method('dispatch')
        ;

        $this->assertFalse($this->createService()->submit($this->case(), ['Neu GmbH'], 'Kim Kunde', 'Grund'));
    }

    public function testAFailedMailKeepsTheObjection(): void
    {
        $this->announcements
            ->method('recordObjection')
            ->willReturn(true)
        ;

        $this->notifier
            ->method('dispatch')
            ->willThrowException(new NotificationFailedException('failed'))
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

        $this->assertTrue($this->createService()->submit($this->case(), ['Neu GmbH'], 'Kim Kunde', 'Grund'));
        $this->assertSame([AuditEvent::SubprocessorObjectionReceived, AuditEvent::MailFailed], $events);
    }

    private function createService(): ObjectionService
    {
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

        return new ObjectionService(
            $this->announcements,
            $templates,
            $contractors,
            new InvitationTokenGenerator(),
            $this->notifier,
            $this->auditLogger,
            new MockClock('@'.self::NOW),
            new NullLogger(),
        );
    }

    private function case(int $effectiveAt = self::NOW + 86400, int $objectedAt = 0): ObjectionCase
    {
        return new ObjectionCase($this->recipient($objectedAt), $this->announcement($effectiveAt), Factory::template(), Factory::contractor());
    }

    private function recipient(int $objectedAt = 0): AnnouncementRecipient
    {
        return new AnnouncementRecipient(30, 9, 7, 'Kunde AG', 'Kim Kunde', 'kim@example.com', 'de', MailStatus::Sent, $objectedAt, '', [], '');
    }

    private function announcement(int $effectiveAt = self::NOW + 86400): Announcement
    {
        $changes = new SubprocessorChanges(
            [$this->sub('Neu GmbH')],
            [$this->sub('Geändert AG')],
            [$this->sub('Alt GmbH')],
            2,
            false,
        );

        return new Announcement(9, 'uuid', 10, 100, 'de', $changes, 14, self::NOW - 13 * 86400, $effectiveAt, 5, 3, 0);
    }

    private function sub(string $name): Subprocessor
    {
        return new Subprocessor($name, 'Musterweg 1', 'Hosting', Subprocessor::LOCATION_EU, '');
    }
}
