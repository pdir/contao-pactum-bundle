<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Sample;

use Doctrine\DBAL\Connection;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Exception\PactumException;
use Pdir\PactumBundle\Repository\AnnexRepository;
use Pdir\PactumBundle\Repository\TemplateRepository;
use Pdir\PactumBundle\Repository\VersionRepository;
use Psr\Clock\ClockInterface;

/**
 * Imports the sample agreement text (DE/EN) as a DRAFT version of an existing
 * contract template, together with a sample TOM annex. Nothing is published: the
 * sample has to be reviewed and adapted before it can be used.
 */
class SampleImporter
{
    public const LANGUAGES = ['de', 'en'];

    private const TITLES = [
        'de' => 'MUSTER – Technische und organisatorische Maßnahmen',
        'en' => 'SAMPLE – Technical and organisational measures',
    ];

    private const CHANGE_NOTES = [
        'de' => 'Mustertext importiert – vor Veröffentlichung rechtlich prüfen und anpassen.',
        'en' => 'Sample text imported – review and adapt before publishing.',
    ];

    private readonly string $sampleDir;

    public function __construct(
        private readonly Connection $connection,
        private readonly TemplateRepository $templates,
        private readonly VersionRepository $versions,
        private readonly AnnexRepository $annexes,
        private readonly ClockInterface $clock,
        string|null $sampleDir = null,
    ) {
        $this->sampleDir = $sampleDir ?? \dirname(__DIR__, 2).'/samples';
    }

    /**
     * @throws PactumException
     */
    public function import(int $templateId, string $language): SampleImportResult
    {
        if (!\in_array($language, self::LANGUAGES, true)) {
            throw new PactumException(\sprintf('There is no sample text for language "%s".', $language));
        }

        if (null === $this->templates->find($templateId)) {
            throw new PactumException(\sprintf('Contract template %d does not exist.', $templateId));
        }

        $content = $this->read('agreement.'.$language.'.html');
        $tom = $this->read('tom.'.$language.'.html');
        $now = $this->clock->now()->getTimestamp();

        return $this->connection->transactional(
            function () use ($templateId, $language, $content, $tom, $now): SampleImportResult {
                $annexId = $this->annexes->insert(AnnexType::Tom, self::TITLES[$language], $tom, $now);

                $versionId = $this->versions->insertDraft(
                    $templateId,
                    $this->versions->nextVersionNumber($templateId),
                    $language,
                    $content,
                    [$annexId],
                    $now,
                    self::CHANGE_NOTES[$language],
                );

                return new SampleImportResult($versionId, $annexId);
            },
        );
    }

    private function read(string $file): string
    {
        $path = $this->sampleDir.'/'.$file;
        $content = is_file($path) ? file_get_contents($path) : false;

        if (false === $content || '' === trim($content)) {
            throw new PactumException(\sprintf('The sample file "%s" is missing.', $file));
        }

        return $content;
    }
}
