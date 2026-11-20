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

use Pdir\PactumBundle\Agreement\AgreementVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pactum:verify', description: 'Verifies the hashes of an agreement against the stored version text, document and PDF.')]
class VerifyCommand extends Command
{
    public function __construct(private readonly AgreementVerifier $verifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('agreement-id', InputArgument::REQUIRED, 'The ID of the agreement');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('agreement-id');

        if ($id < 1) {
            $io->error('Please provide a valid agreement ID.');

            return Command::INVALID;
        }

        $result = $this->verifier->verify($id);
        $rows = [];

        foreach ($result->checks as $check) {
            $rows[] = [
                $check->name,
                $check->passed ? '<info>OK</info>' : ($check->critical ? '<error>FAILED</error>' : '<comment>WARNING</comment>'),
                $check->details,
            ];
        }

        $io->table(['Check', 'Result', 'Details'], $rows);

        if (!$result->isValid()) {
            $io->error(\sprintf('Agreement %d could NOT be verified.', $id));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Agreement %d verified successfully.', $id));

        return Command::SUCCESS;
    }
}
