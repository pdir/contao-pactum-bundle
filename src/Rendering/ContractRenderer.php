<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Rendering;

use Pdir\PactumBundle\Domain\Annex;
use Pdir\PactumBundle\Domain\AnnexType;
use Pdir\PactumBundle\Domain\Contractor;
use Pdir\PactumBundle\Domain\ContractTemplate;
use Pdir\PactumBundle\Domain\CustomerData;
use Pdir\PactumBundle\Domain\Version;
use Pdir\PactumBundle\Domain\VersionStatus;
use Pdir\PactumBundle\Exception\InvalidVersionException;
use Pdir\PactumBundle\Exception\UnknownPlaceholderException;

/**
 * Builds the contract document in two stages:
 *
 * 1. publish: sanitized text + annexes + contractor/version placeholders
 *               → frozen as Version::$renderedContent and hashed
 * 2. agreement: customer/signatory/date placeholders on top of the frozen text
 *               → frozen as Agreement::$documentHtml and hashed
 */
class ContractRenderer
{
    public const ANNEX_KEYS = [
        'annex.tom' => AnnexType::Tom,
        'annex.subprocessors' => AnnexType::Subprocessor,
        'annex.custom' => AnnexType::Custom,
    ];

    public function __construct(
        private readonly PlaceholderResolver $resolver,
        private readonly ContractHtmlSanitizer $sanitizer,
        private readonly AnnexRenderer $annexRenderer,
    ) {
    }

    /**
     * @param list<Annex> $annexes
     *
     * @throws UnknownPlaceholderException
     */
    public function renderForPublish(Version $version, ContractTemplate $template, Contractor $contractor, array $annexes): string
    {
        $content = $this->unwrapBlockPlaceholders($this->sanitizer->sanitize($version->content));

        // Validate the main text before anything is replaced
        $unknown = $this->resolver->findUnknown($content, array_keys(self::ANNEX_KEYS));

        // Render annexes; annexes of a type referenced by a placeholder are inserted
        // there, all others are appended at the end
        $used = array_intersect(array_keys(self::ANNEX_KEYS), $this->resolver->findPlaceholders($content));
        $inline = array_fill_keys(array_keys(self::ANNEX_KEYS), '');
        $appended = '';
        $number = 0;

        foreach ($annexes as $annex) {
            $html = $this->annexRenderer->render($annex, ++$number, $version->language);
            $key = (string) array_search($annex->type, self::ANNEX_KEYS, true);

            // Annexes must not reference other annexes
            $unknown = [...$unknown, ...$this->resolver->findUnknown($html)];

            if (\in_array($key, $used, true)) {
                $inline[$key] .= $html;
            } else {
                $appended .= $html;
            }
        }

        if ([] !== $unknown) {
            throw new UnknownPlaceholderException(array_values(array_unique($unknown)));
        }

        $document = $this->resolver->insertMarkup($content, $inline).$appended;

        $context = new PlaceholderContext(
            locale: $version->language,
            contractor: $contractor,
            template: $template,
            version: $version,
        );

        return $this->normalize($this->resolver->resolve($document, PlaceholderStage::Publish, $context));
    }

    /**
     * Renders the final document for a customer. Must be deterministic: the same
     * version, customer data and date always produce the same output.
     */
    public function renderForAgreement(Version $version, CustomerData $customer, \DateTimeImmutable $date): string
    {
        if (null === $version->renderedContent || VersionStatus::Draft === $version->status) {
            throw new InvalidVersionException(\sprintf('Version %d has not been published.', $version->id));
        }

        $context = new PlaceholderContext(
            locale: $version->language,
            version: $version,
            customer: $customer,
            agreementDate: $date,
        );

        $html = $this->resolver->resolve($version->renderedContent, PlaceholderStage::Agreement, $context);

        return $this->normalize($this->resolver->neutralize($html));
    }

    /**
     * The rich text editor wraps a placeholder on its own line in a paragraph. Annex
     * placeholders are replaced with block elements, which must not be nested in a
     * paragraph, so the paragraph is removed.
     */
    private function unwrapBlockPlaceholders(string $content): string
    {
        return (string) preg_replace('#<p>\s*(\{\{\s*annex\.[a-z_]+\s*\}\})\s*</p>#', '$1', $content);
    }

    private function normalize(string $html): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $html));
    }
}
