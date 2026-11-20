<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Invitation;

use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Domain\MailStatus;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\IncompleteContractorException;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;
use Pdir\PactumBundle\Exception\InvitationNotUsableException;
use Pdir\PactumBundle\Exception\NoActiveVersionException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Invitation\InvitationLinkBuilder;
use Pdir\PactumBundle\Invitation\InvitationService;
use Pdir\PactumBundle\Invitation\InvitationStateMachine;
use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class InvitationServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InvitationRepository&MockObject $invitations;

    private MockObject&NotificationDispatcherInterface $notifier;

    private Contractor $contractor;

    private Version|null $version;

    protected function setUp(): void
    {
        $this->invitations = $this->createMock(InvitationRepository::class);
        $this->notifier = $this->createMock(NotificationDispatcherInterface::class);
        $this->contractor = Factory::contractor();
        $this->version = Factory::version(VersionStatus::Published, rendered: '<p></p>', hash: str_repeat('b', 64));
    }

    public function testGenerateLinkStoresOnlyTheHashAndRotatesTheToken(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;

        $storedHash = null;

        $this->invitations
            ->expects($this->once())
            ->method('replaceToken')
            ->willReturnCallback(
                static function (int $id, string $hash) use (&$storedHash): void {
                    $storedHash = $hash;
                },
            )
        ;

        $this->notifier
            ->expects($this->never())
            ->method('dispatch')
        ;

        $link = $this->createService()->generateLink(7, false);

        parse_str((string) parse_url($link->url, PHP_URL_QUERY), $query);

        $plain = $query['pactum'];

        $this->assertIsString($plain);
        $this->assertSame(hash('sha256', $plain), $storedHash);
        $this->assertStringNotContainsString((string) $storedHash, $link->url);
        $this->assertFalse($link->mailSent);
    }

    public function testEveryCallCreatesANewToken(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;

        $service = $this->createService();

        $this->assertNotSame($service->generateLink(7, false)->url, $service->generateLink(7, false)->url);
    }

    public function testSendsTheInvitationMail(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;
        $this->invitations
            ->expects($this->once())
            ->method('setMailStatus')
            ->with(7, MailStatus::Sent, self::NOW)
        ;

        $this->notifier
            ->expects($this->once())
            ->method('dispatch')
        ;

        $this->assertTrue($this->createService()->generateLink(7, true)->mailSent);
    }

    public function testMailFailureKeepsTheLinkAndMarksTheStatus(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;
        $this->invitations
            ->expects($this->once())
            ->method('replaceToken')
        ;
        $this->invitations
            ->expects($this->once())
            ->method('setMailStatus')
            ->with(7, MailStatus::Failed, self::NOW)
        ;

        $this->notifier
            ->method('dispatch')
            ->willThrowException(new NotificationFailedException('smtp down'))
        ;

        $link = $this->createService()->generateLink(7, true);

        $this->assertTrue($link->mailRequested);
        $this->assertFalse($link->mailSent);
    }

    public function testNoLinkForExpiredInvitations(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open, 1_000))
        ;
        $this->invitations
            ->expects($this->never())
            ->method('replaceToken')
        ;

        $this->expectException(InvitationNotUsableException::class);

        $this->createService()->generateLink(7, false);
    }

    public function testNoLinkWithoutPublishedVersion(): void
    {
        $this->version = null;

        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;
        $this->invitations
            ->expects($this->never())
            ->method('replaceToken')
        ;

        $this->expectException(NoActiveVersionException::class);

        $this->createService()->generateLink(7, false);
    }

    public function testNoLinkWithIncompleteContractor(): void
    {
        $this->contractor = Factory::contractor(name: '');

        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;
        $this->invitations
            ->expects($this->never())
            ->method('replaceToken')
        ;

        $this->expectException(IncompleteContractorException::class);

        $this->createService()->generateLink(7, false);
    }

    public function testRevokeInvalidatesTheToken(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open))
        ;
        $this->invitations
            ->expects($this->once())
            ->method('transition')
            ->with(7, InvitationStatus::Open, InvitationStatus::Revoked, self::NOW, ['token_hash' => null])
            ->willReturn(true)
        ;

        $this->createService()->revoke(7);
    }

    public function testCompletedInvitationCannotBeRevoked(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Completed))
        ;
        $this->invitations
            ->expects($this->never())
            ->method('transition')
        ;

        $this->expectException(InvalidStatusTransitionException::class);

        $this->createService()->revoke(7);
    }

    public function testExtendReopensExpiredInvitationsAndResetsTheOptIn(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Expired, 1_000, optIn: 'pact-x', optInRequestedAt: 900))
        ;
        $this->invitations
            ->expects($this->once())
            ->method('transition')
            ->with(
                7,
                InvitationStatus::Expired,
                InvitationStatus::Open,
                self::NOW,
                $this->callback(static fn (array $fields): bool => null === $fields['optin_identifier'] && 0 === $fields['optin_confirmed_at'] && $fields['expires_at'] > self::NOW + 6 * 86400),
            )
            ->willReturn(true)
        ;

        $this->createService()->extend(7, 7);
    }

    public function testExtendAddsDaysToTheCurrentExpiry(): void
    {
        $this->invitations
            ->method('find')
            ->willReturn(Factory::invitation(InvitationStatus::Open, self::NOW + 10 * 86400))
        ;
        $this->invitations
            ->expects($this->never())
            ->method('transition')
        ;
        $this->invitations
            ->expects($this->once())
            ->method('updateExpiry')
        ;

        $expiresAt = $this->createService()->extend(7, 5);

        $this->assertGreaterThanOrEqual(self::NOW + 15 * 86400, $expiresAt);
        $this->assertLessThan(self::NOW + 16 * 86400 + 86400, $expiresAt);
    }

    public function testExtendRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createService()->extend(7, 0);
    }

    public function testExpireOverdue(): void
    {
        $this->invitations
            ->method('findExpiredActiveIds')
            ->willReturn([7, 8])
        ;
        $this->invitations
            ->method('find')
            ->willReturnOnConsecutiveCalls(Factory::invitation(InvitationStatus::Open, 1_000), Factory::invitation(InvitationStatus::Completed, 1_000))
        ;
        $this->invitations
            ->expects($this->once())
            ->method('transition')
            ->willReturn(true)
        ;

        $this->assertSame(1, $this->createService()->expireOverdue());
    }

    private function createService(): InvitationService
    {
        $templates = $this->createStub(TemplateRepository::class);
        $templates
            ->method('find')
            ->willReturn(Factory::template())
        ;

        $contractors = $this->createStub(ContractorRepository::class);
        $contractors
            ->method('find')
            ->willReturn($this->contractor)
        ;

        $versions = $this->createStub(VersionRepository::class);
        $versions
            ->method('findActive')
            ->willReturn($this->version)
        ;

        $linkBuilder = $this->createStub(InvitationLinkBuilder::class);
        $linkBuilder
            ->method('build')
            ->willReturnCallback(static fn (int $page, string $token, array $params = []): string => 'https://example.org/avv?'.http_build_query(['pactum' => $token, ...$params]))
        ;

        return new InvitationService(
            $this->invitations,
            $templates,
            $contractors,
            $versions,
            new InvitationTokenGenerator(),
            $linkBuilder,
            new InvitationStateMachine(),
            $this->notifier,
            $this->createStub(AuditLogger::class),
            $this->createStub(EventDispatcherInterface::class),
            new MockClock('@'.self::NOW),
            new NullLogger(),
            30,
        );
    }
}
