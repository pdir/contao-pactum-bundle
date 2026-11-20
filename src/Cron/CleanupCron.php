<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Pdir\PactumBundle\Maintenance\Cleanup;
use Psr\Log\LoggerInterface;

#[AsCronJob('daily')]
class CleanupCron
{
    public function __construct(
        private readonly Cleanup $cleanup,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $report = $this->cleanup->run();

        $this->logger->info(
            'Pactum clean-up finished.',
            [
                'expired' => $report->expiredInvitations,
                'anonymized' => $report->anonymizedInvitations,
                'client_data_purged' => $report->purgedClientData,
                'agreements_deleted' => $report->deletedAgreements,
            ],
        );
    }
}
