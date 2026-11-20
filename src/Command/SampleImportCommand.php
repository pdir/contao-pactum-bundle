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
use Pdir\PactumBundle\Sample\SampleImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'pactum:sample:import', description: 'Imports the sample agreement text as a draft version of a contract template (not legally reviewed).')]
class SampleImportCommand extends Command
{
    public function __construct(private readonly SampleImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('template-id', InputArgument::REQUIRED, 'The ID of an existing contract template')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Languages to import (de, en)', SampleImporter::LANGUAGES)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $templateId = (int) $input->getArgument('template-id');

        if ($templateId < 1) {
            $io->error('Please provide a valid template ID.');

            return Command::INVALID;
        }

        /** @var list<string> $languages */
        $languages = $input->getOption('language');

        $io->warning('The sample text is NOT legally reviewed and does not constitute legal advice. It is imported as a draft and must be reviewed and adapted before publishing.');

        try {
            foreach ($languages as $language) {
                $result = $this->importer->import($templateId, $language);
                $io->writeln(\sprintf('Imported draft version %d (%s) with sample TOM annex %d.', $result->versionId, $language, $result->annexId));
            }
        } catch (PactumException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Done. Review the drafts in the back end before publishing them.');

        return Command::SUCCESS;
    }
}
