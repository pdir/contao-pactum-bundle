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

use Pdir\PactumBundle\Invitation\InvitationTokenGenerator;
use PHPUnit\Framework\TestCase;

class InvitationTokenGeneratorTest extends TestCase
{
    public function testGeneratesUrlSafeTokenWith32BytesOfEntropy(): void
    {
        $token = (new InvitationTokenGenerator())->generate();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token->plain);
        $this->assertSame(32, \strlen((string) base64_decode(strtr($token->plain, '-_', '+/'), true)));
        $this->assertSame(urlencode($token->plain), $token->plain, 'Token must not need URL encoding');
    }

    public function testOnlyTheHashIsDerivedAndItIsNotThePlainToken(): void
    {
        $generator = new InvitationTokenGenerator();
        $token = $generator->generate();

        $this->assertSame(hash('sha256', $token->plain), $token->hash);
        $this->assertSame($token->hash, $generator->hash($token->plain));
        $this->assertNotSame($token->plain, $token->hash);
        $this->assertStringNotContainsString($token->plain, $token->hash);
    }

    public function testTokensAreUnique(): void
    {
        $generator = new InvitationTokenGenerator();
        $tokens = [];

        for ($i = 0; $i < 200; ++$i) {
            $tokens[] = $generator->generate()->plain;
        }

        $this->assertCount(200, array_unique($tokens));
    }

    public function testWellFormedCheck(): void
    {
        $generator = new InvitationTokenGenerator();

        $this->assertTrue($generator->isWellFormed($generator->generate()->plain));
        $this->assertFalse($generator->isWellFormed(''));
        $this->assertFalse($generator->isWellFormed('short'));
        $this->assertFalse($generator->isWellFormed(str_repeat('a', 42).'='));
        $this->assertFalse($generator->isWellFormed(str_repeat('a', 43).'x'));
        $this->assertFalse($generator->isWellFormed(str_repeat('a', 42)."'"));
    }

    public function testTokenIsNotExposedInDebugOutput(): void
    {
        $token = (new InvitationTokenGenerator())->generate();

        try {
            (
                static function (#[\SensitiveParameter] string $plain): never {
                    throw new \RuntimeException('x');
                }
            )($token->plain);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($token->plain, $e->getTraceAsString());
        }
    }
}
