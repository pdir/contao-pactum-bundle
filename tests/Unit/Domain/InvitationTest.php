<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Domain;

use Pdir\PactumBundle\Domain\InvitationStatus;
use Pdir\PactumBundle\Tests\Fixtures\Factory;
use PHPUnit\Framework\TestCase;

class InvitationTest extends TestCase
{
    public function testActiveInvitationBecomesExpiredLazily(): void
    {
        $invitation = Factory::invitation(InvitationStatus::Open, 1000);

        $this->assertSame(InvitationStatus::Open, $invitation->getEffectiveStatus(999));
        $this->assertSame(InvitationStatus::Expired, $invitation->getEffectiveStatus(1000));
        $this->assertSame(InvitationStatus::Expired, Factory::invitation(InvitationStatus::OptedIn, 1000)->getEffectiveStatus(5000));
    }

    public function testFinalStatusIsNotChangedByExpiry(): void
    {
        $this->assertSame(InvitationStatus::Completed, Factory::invitation(InvitationStatus::Completed, 1000)->getEffectiveStatus(5000));
        $this->assertSame(InvitationStatus::Revoked, Factory::invitation(InvitationStatus::Revoked, 1000)->getEffectiveStatus(5000));
    }

    public function testPendingOptIn(): void
    {
        $this->assertFalse(Factory::invitation(InvitationStatus::Open)->hasPendingOptIn());
        $this->assertTrue(Factory::invitation(InvitationStatus::Open, optIn: 'pact-x', optInRequestedAt: 100)->hasPendingOptIn());
        $this->assertFalse(Factory::invitation(InvitationStatus::OptedIn, optIn: 'pact-x', optInRequestedAt: 100)->hasPendingOptIn());
    }
}
