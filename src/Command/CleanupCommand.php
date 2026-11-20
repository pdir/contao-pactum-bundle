<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Command;

use Pdir\PactumBundle\Maintenance\Cleanup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pactum:cleanup', description: 'Expires overdue invitations, anonymizes stale invitations and removes client data after the retention period.')]
class CleanupCommand extends Command
{
    public function __construct(private readonly Cleanup $cleanup)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->cleanup->run();

        $io->definitionList(
            ['Expired invitations' => (string) $report->expiredInvitations],
            ['Anonymized invitations' => (string) $report->anonymizedInvitations],
            ['Agreements with IP/user agent removed' => (string) $report->purgedClientData],
            ['Deleted agreements (retention)' => (string) $report->deletedAgreements],
        );

        $io->success('Clean-up finished.');

        return Command::SUCCESS;
    }
}
