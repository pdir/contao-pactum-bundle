<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Privacy;

use Pdir\PactumBundle\Privacy\ClientDataPolicy;
use PHPUnit\Framework\TestCase;

class ClientDataPolicyTest extends TestCase
{
    public function testAnonymizedIsTheDefaultBehaviour(): void
    {
        $policy = new ClientDataPolicy('anonymized', false, 90);

        $this->assertSame('192.0.2.0', $policy->ip('192.0.2.123'));
        $this->assertSame('2001:db8:85a3::', $policy->ip('2001:db8:85a3::8a2e:370:7334'));
        $this->assertSame('', $policy->userAgent('Mozilla/5.0'));
    }

    public function testNoneAndFull(): void
    {
        $this->assertSame('', (new ClientDataPolicy('none', false, 90))->ip('192.0.2.123'));
        $this->assertSame('192.0.2.123', (new ClientDataPolicy('full', true, 90))->ip('192.0.2.123'));
        $this->assertSame('Mozilla/5.0', (new ClientDataPolicy('full', true, 90))->userAgent('Mozilla/5.0'));
    }

    public function testInvalidIpIsNotStored(): void
    {
        $this->assertSame('', (new ClientDataPolicy('full', false, 90))->ip('not-an-ip'));
        $this->assertSame('', (new ClientDataPolicy('full', false, 90))->ip(null));
    }

    public function testUserAgentIsTruncated(): void
    {
        $this->assertSame(512, mb_strlen((new ClientDataPolicy('none', true, 90))->userAgent(str_repeat('x', 2000))));
    }

    public function testDeletionDate(): void
    {
        $policy = new ClientDataPolicy('anonymized', false, 10);

        $this->assertSame(1000 + 10 * 86400, $policy->deleteAfter('192.0.2.0', '', 1000));
        $this->assertSame(0, $policy->deleteAfter('', '', 1000));
    }

    public function testInvalidModeIsRejected(): void
    {
        $this->expectException(\ValueError::class);

        new ClientDataPolicy('everything', false, 1);
    }
}
