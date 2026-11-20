<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Unit\Security;

use Pdir\PactumBundle\Security\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class RateLimitGuardTest extends TestCase
{
    public function testLimitsArePerClientAndPerInvitation(): void
    {
        $guard = new RateLimitGuard($this->factory('t', 2), $this->factory('s', 1), $this->factory('o', 1));

        $this->assertTrue($guard->allowTokenLookup('192.0.2.1'));
        $this->assertTrue($guard->allowTokenLookup('192.0.2.1'));
        $this->assertFalse($guard->allowTokenLookup('192.0.2.1'));
        $this->assertTrue($guard->allowTokenLookup('192.0.2.2'));

        $this->assertTrue($guard->allowSubmit('192.0.2.1', 7));
        $this->assertFalse($guard->allowSubmit('192.0.2.1', 7));
        $this->assertTrue($guard->allowSubmit('192.0.2.1', 8));

        $this->assertTrue($guard->allowOptInMail(7));
        $this->assertFalse($guard->allowOptInMail(7));
    }

    public function testNoIpAddressIsStoredInPlainText(): void
    {
        $storage = new InMemoryStorage();

        $guard = new RateLimitGuard(new RateLimiterFactory(['id' => 't', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '10 minutes'], $storage), $this->factory('s', 1), $this->factory('o', 1));
        $guard->allowTokenLookup('192.0.2.1');

        $this->assertStringNotContainsString('192.0.2.1', serialize($storage));
    }

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '10 minutes'], new InMemoryStorage());
    }
}
