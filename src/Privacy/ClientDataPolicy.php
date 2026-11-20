<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Privacy;

use Pdir\PactumBundle\Domain\IpMode;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides which client data (IP address, user agent) is stored with an agreement
 * and for how long.
 */
class ClientDataPolicy
{
    private readonly IpMode $mode;

    public function __construct(
        string $ipMode,
        private readonly bool $storeUserAgent,
        private readonly int $ipRetentionDays,
    ) {
        $this->mode = IpMode::from($ipMode);
    }

    public function ip(string|null $ip): string
    {
        if (null === $ip || '' === $ip || IpMode::None === $this->mode) {
            return '';
        }

        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        return IpMode::Anonymized === $this->mode ? IpUtils::anonymize($ip) : $ip;
    }

    public function userAgent(string|null $userAgent): string
    {
        if (!$this->storeUserAgent || null === $userAgent) {
            return '';
        }

        return mb_substr($userAgent, 0, 512);
    }

    /**
     * Timestamp after which IP/user agent are removed, 0 if nothing is stored.
     */
    public function deleteAfter(string $storedIp, string $storedUserAgent, int $now): int
    {
        if ('' === $storedIp && '' === $storedUserAgent) {
            return 0;
        }

        return $now + $this->ipRetentionDays * 86400;
    }
}
