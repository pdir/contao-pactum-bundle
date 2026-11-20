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

use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Subprocessor\SubprocessorImporter;
use Pdir\PactumBundle\Subprocessor\SubprocessorListParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pactum:subprocessor:import', description: 'Replaces the subprocessors of a subprocessor annex with the list from a YAML file.')]
class SubprocessorImportCommand extends Command
{
    public function __construct(
        private readonly SubprocessorListParser $parser,
        private readonly SubprocessorImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('annex-id', InputArgument::REQUIRED, 'The ID of an existing annex of type "subprocessor"')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the YAML file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show the changes, do not write anything')
            ->setHelp(
                <<<'HELP'
                    The YAML file contains a list of subprocessors, either at the top level or
                    below the key "subprocessors" (or "subunternehmer"). Each entry supports
                    English or German keys:

                      subprocessors:
                        - name: "Hosting GmbH"
                          address: ["Serverweg 1", "12345 Berlin"]   # or: anschrift
                          service: "Webhosting"                        # or: leistung
                          country: "Deutschland"                       # or: land
                          transfer_basis: ""                           # or: hinweis

                    "location" (eu / third_country) is derived from the country if omitted.
                    Third-country entries require a transfer basis.

                    The current list of the annex is replaced completely. Published versions
                    keep their snapshot; publish a new version to use the new list, and
                    inform existing customers about new subprocessors in good time.
                    HELP,
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $annexId = (int) $input->getArgument('annex-id');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($annexId < 1) {
            $io->error('Please provide a valid annex ID.');

            return Command::INVALID;
        }

        try {
            $subprocessors = $this->parser->parseFile((string) $input->getArgument('file'));
            $result = $this->importer->import($annexId, $subprocessors, $dryRun);
        } catch (PactumException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$result->hasChanges()) {
            $io->success(\sprintf('Annex %d is already up to date (%d subprocessors).', $annexId, $result->unchanged));

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($result->added as $name) {
            $rows[] = ['+ added', $name];
        }

        foreach ($result->changed as $name) {
            $rows[] = ['~ changed', $name];
        }

        foreach ($result->removed as $name) {
            $rows[] = ['- removed', $name];
        }

        $io->table(['Change', 'Subprocessor'], $rows);
        $io->writeln(\sprintf('%d unchanged.', $result->unchanged));

        if ($dryRun) {
            $io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('Annex %d now lists %d subprocessors.', $annexId, \count($subprocessors)));
        $io->note([
            'Published versions are not affected. Publish a new version so that new agreements contain the updated list.',
            'New subprocessors usually have to be announced to existing customers before they are used (objection period).',
        ]);

        return Command::SUCCESS;
    }
}
