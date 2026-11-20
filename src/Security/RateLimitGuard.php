<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Security;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limits for the public endpoints. The limiter configuration is prepended by
 * the bundle and can be overridden in "framework.rate_limiter". Keys are hashed,
 * so no IP addresses end up in the cache in plain text.
 */
class RateLimitGuard
{
    public function __construct(
        #[Target('pactum_token')]
        private readonly RateLimiterFactoryInterface $tokenLimiter,
        #[Target('pactum_submit')]
        private readonly RateLimiterFactoryInterface $submitLimiter,
        #[Target('pactum_optin_mail')]
        private readonly RateLimiterFactoryInterface $optInMailLimiter,
    ) {
    }

    public function allowTokenLookup(string|null $clientIp): bool
    {
        return $this->tokenLimiter->create($this->key('ip', (string) $clientIp))->consume()->isAccepted();
    }

    public function allowSubmit(string|null $clientIp, int $invitationId): bool
    {
        return $this->submitLimiter->create($this->key('submit', $clientIp.'|'.$invitationId))->consume()->isAccepted();
    }

    public function allowOptInMail(int $invitationId): bool
    {
        return $this->optInMailLimiter->create($this->key('optin', (string) $invitationId))->consume()->isAccepted();
    }

    private function key(string $prefix, string $value): string
    {
        return $prefix.'-'.hash('sha256', $value);
    }
}
