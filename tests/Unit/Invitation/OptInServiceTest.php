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

use Contao\CoreBundle\OptIn\OptIn;
use Contao\CoreBundle\OptIn\OptInTokenInterface;
use Pdir\PactumBundle\Audit\AuditLogger;
use Pdir\PactumBundle\Domain\Invitation;
use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Event\OptInConfirmedEvent;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;
use Pdir\PactumBundle\Exception\NotificationFailedException;
use Pdir\PactumBundle\Invitation\InvitationLinkBuilder;
use Pdir\PactumBundle\Invitation\InvitationStateMachine;
use Pdir\PactumBundle\Invitation\OptInService;
use Pdir\PactumBundle\Notification\Notification;
use Pdir\PactumBundle\Notification\NotificationDispatcherInterface;
use Pdir\PactumBundle\Notification\NotificationType;
use Pdir\PactumBundle\Repository\ContractorRepository;
use Pdir\PactumBundle\Repository\InvitationRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[AllowMockObjectsWithoutExpectations]
class OptInServiceTest extends TestCase
{
    private MockObject&OptIn $optIn;

    private InvitationRepository&MockObject $invitations;

    private MockObject&NotificationDispatcherInterface $notifier;

    private EventDispatcherInterface&MockObject $dispatcher;

    protected function setUp(): void
    {
        $this->optIn = $this->createMock(OptIn::class);
        $this->invitations = $this->createMock(InvitationRepository::class);
        $this->notifier = $this->createMock(NotificationDispatcherInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    public function testRequestStoresDataAndSendsTheMailToTheSignatory(): void
    {
        $this->optIn
            ->expects($this->once())
            ->method('create')
            ->with(
                'pact',
                'kim@example.com',
                [],
                $this->callback(static fn (\DateTimeInterface $d): bool => 1_800_000_000 + 24 * 3600 === $d->getTimestamp()),
            )
            ->willReturn($this->token('pact-abc'))
        ;

        $this->invitations
            ->expects($this->once())
            ->method('saveCustomerData')
            ->with(7, $this->anything(), 'pact-abc', 1_800_000_000)
            ->willReturn(true)
        ;
        $this->invitations
            ->expects($this->never())
            ->method('transition')
        ;

        $this->notifier
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (Notification $n): bool => NotificationType::OptIn === $n->type && 'kim@example.com' === $n->recipient && 'https://example.org/avv?pactum=plain&optin=pact-abc' === $n->context['url']))
        ;

        $this->createService()->request(Factory::invitation(InvitationStatus::Open), Factory::customer(), 'plain');
    }

    public function testChangingDataAfterTheOptInReopensTheInvitation(): void
    {
        $this->optIn
            ->method('create')
            ->willReturn($this->token('pact-new'))
        ;

        $this->invitations
            ->expects($this->once())
            ->method('transition')
            ->with(7, InvitationStatus::OptedIn, InvitationStatus::Open, 1_800_000_000)
            ->willReturn(true)
        ;
        $this->invitations
            ->method('saveCustomerData')
            ->willReturn(true)
        ;

        $this->createService()->request(Factory::invitation(InvitationStatus::OptedIn), Factory::customer(), 'plain');
    }

    public function testFinalInvitationsDoNotAcceptData(): void
    {
        $this->invitations
            ->expects($this->never())
            ->method('saveCustomerData')
        ;

        $this->expectException(InvalidStatusTransitionException::class);

        $this->createService()->request(Factory::invitation(InvitationStatus::Revoked), Factory::customer(), 'plain');
    }

    public function testMailFailureIsPropagated(): void
    {
        $this->optIn
            ->method('create')
            ->willReturn($this->token('pact-abc'))
        ;

        $this->invitations
            ->method('saveCustomerData')
            ->willReturn(true)
        ;

        $this->notifier
            ->method('dispatch')
            ->willThrowException(new NotificationFailedException('failed'))
        ;

        $this->expectException(NotificationFailedException::class);

        $this->createService()->request(Factory::invitation(InvitationStatus::Open), Factory::customer(), 'plain');
    }

    public function testConfirmSucceedsWithMatchingIdentifier(): void
    {
        $token = $this->token('pact-abc');
        $token
            ->expects($this->once())
            ->method('confirm')
        ;

        $this->optIn
            ->method('find')
            ->with('pact-abc')
            ->willReturn($token)
        ;

        $this->invitations
            ->expects($this->once())
            ->method('transition')
            ->with(7, InvitationStatus::Open, InvitationStatus::OptedIn, 1_800_000_000, ['optin_confirmed_at' => 1_800_000_000])
            ->willReturn(true)
        ;

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(OptInConfirmedEvent::class))
        ;

        $this->assertTrue($this->createService()->confirm($this->pending(), 'pact-abc'));
    }

    public function testConfirmRejectsAnIdentifierOfAnotherInvitation(): void
    {
        $this->optIn
            ->expects($this->never())
            ->method('find')
        ;

        $this->invitations
            ->expects($this->never())
            ->method('transition')
        ;

        $this->assertFalse($this->createService()->confirm($this->pending(), 'pact-other'));
    }

    public function testConfirmRejectsExpiredOrUsedTokens(): void
    {
        $expired = $this->token('pact-abc', valid: false);
        $used = $this->token('pact-abc', confirmed: true);

        $this->optIn
            ->method('find')
            ->willReturnOnConsecutiveCalls(null, $expired, $used)
        ;

        $this->invitations
            ->expects($this->never())
            ->method('transition')
        ;

        $service = $this->createService();

        $this->assertFalse($service->confirm($this->pending(), 'pact-abc'));
        $this->assertFalse($service->confirm($this->pending(), 'pact-abc'));
        $this->assertFalse($service->confirm($this->pending(), 'pact-abc'));
    }

    public function testConfirmIsNotPossibleTwice(): void
    {
        $this->optIn
            ->expects($this->never())
            ->method('find')
        ;

        $invitation = Factory::invitation(InvitationStatus::OptedIn, optIn: 'pact-abc', optInRequestedAt: 1_799_999_000);

        $this->assertFalse($this->createService()->confirm($invitation, 'pact-abc'));
    }

    public function testConcurrentConfirmationDoesNotConfirmTheToken(): void
    {
        $token = $this->token('pact-abc');
        $token
            ->expects($this->never())
            ->method('confirm')
        ;

        $this->optIn
            ->method('find')
            ->willReturn($token)
        ;

        $this->invitations
            ->method('transition')
            ->willReturn(false)
        ;

        $this->assertFalse($this->createService()->confirm($this->pending(), 'pact-abc'));
    }

    private function pending(): Invitation
    {
        return Factory::invitation(InvitationStatus::Open, optIn: 'pact-abc', optInRequestedAt: 1_799_999_000);
    }

    private function token(string $identifier, bool $valid = true, bool $confirmed = false): MockObject&OptInTokenInterface
    {
        $token = $this->createMock(OptInTokenInterface::class);
        $token
            ->method('getIdentifier')
            ->willReturn($identifier)
        ;
        $token
            ->method('isValid')
            ->willReturn($valid)
        ;
        $token
            ->method('isConfirmed')
            ->willReturn($confirmed)
        ;

        return $token;
    }

    private function createService(): OptInService
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

        $linkBuilder = $this->createStub(InvitationLinkBuilder::class);
        $linkBuilder
            ->method('build')
            ->willReturnCallback(static fn (int $page, string $token, array $params = []): string => 'https://example.org/avv?'.http_build_query(['pactum' => $token, ...$params]))
        ;

        return new OptInService(
            $this->optIn,
            $this->invitations,
            $templates,
            $contractors,
            $linkBuilder,
            new InvitationStateMachine(),
            $this->notifier,
            $this->createStub(AuditLogger::class),
            $this->dispatcher,
            new MockClock('@1800000000'),
            new NullLogger(),
            24,
        );
    }
}
