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

use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Exception\InvalidStatusTransitionException;
use Pdir\PactumBundle\Invitation\InvitationStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InvitationStateMachineTest extends TestCase
{
    #[DataProvider('transitionProvider')]
    public function testTransitions(InvitationStatus $from, InvitationStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, (new InvitationStateMachine())->can($from, $to));
    }

    /**
     * @return iterable<string, array{InvitationStatus, InvitationStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'open → opted_in' => [InvitationStatus::Open, InvitationStatus::OptedIn, true];
        yield 'open → completed (skip opt-in)' => [InvitationStatus::Open, InvitationStatus::Completed, false];
        yield 'opted_in → completed' => [InvitationStatus::OptedIn, InvitationStatus::Completed, true];
        yield 'opted_in → open (edit data)' => [InvitationStatus::OptedIn, InvitationStatus::Open, true];
        yield 'completed → open' => [InvitationStatus::Completed, InvitationStatus::Open, false];
        yield 'completed → completed (double)' => [InvitationStatus::Completed, InvitationStatus::Completed, false];
        yield 'completed → revoked' => [InvitationStatus::Completed, InvitationStatus::Revoked, false];
        yield 'revoked → open' => [InvitationStatus::Revoked, InvitationStatus::Open, false];
        yield 'expired → completed' => [InvitationStatus::Expired, InvitationStatus::Completed, false];
        yield 'expired → open (extend)' => [InvitationStatus::Expired, InvitationStatus::Open, true];
        yield 'expired → revoked' => [InvitationStatus::Expired, InvitationStatus::Revoked, true];
        yield 'open → revoked' => [InvitationStatus::Open, InvitationStatus::Revoked, true];
        yield 'opted_in → expired' => [InvitationStatus::OptedIn, InvitationStatus::Expired, true];
    }

    public function testFinalStatusesHaveNoTransitions(): void
    {
        $machine = new InvitationStateMachine();

        $this->assertSame([], $machine->getAllowedTransitions(InvitationStatus::Completed));
        $this->assertSame([], $machine->getAllowedTransitions(InvitationStatus::Revoked));
    }

    public function testAssertThrowsWithoutPersonalData(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);
        $this->expectExceptionMessage('completed -> open');

        (new InvitationStateMachine())->assert(InvitationStatus::Completed, InvitationStatus::Open);
    }
}
