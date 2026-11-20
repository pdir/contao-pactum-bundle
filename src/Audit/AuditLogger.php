<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Audit;

use Contao\BackendUser;
use Pdir\PactumBundle\Domain\AuditEvent;
use Pdir\PactumBundle\Repository\AuditLogRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes the append-only audit log. The context must only contain IDs, statuses
 * and hashes – never personal data.
 */
class AuditLogger
{
    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_CUSTOMER = 'customer';

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly ClockInterface $clock,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function log(AuditEvent $event, int $invitationId = 0, int $agreementId = 0, array $context = [], string|null $actor = null): void
    {
        $this->repository->append(
            $event->value,
            $this->clock->now()->getTimestamp(),
            $actor ?? $this->resolveActor(),
            $invitationId,
            $agreementId,
            $context,
        );
    }

    private function resolveActor(): string
    {
        $user = $this->security->getUser();

        if ($user instanceof BackendUser) {
            return 'be_user:'.$user->id;
        }

        return \PHP_SAPI === 'cli' ? self::ACTOR_SYSTEM : self::ACTOR_CUSTOMER;
    }
}
