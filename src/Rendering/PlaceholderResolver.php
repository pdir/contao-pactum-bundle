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

/**
 * Replaces {{ namespace.key }} placeholders. This is intentionally NOT Twig:
 * contract texts come from the back end and must never be executed as code.
 */
final class PlaceholderResolver
{
    public const KEY_PATTERN = '[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+';

    private const PLACEHOLDER_REGEX = '/\{\{\s*('.self::KEY_PATTERN.')\s*\}\}/';

    private const ANY_TAG_REGEX = '/\{\{(.*?)\}\}/s';

    /**
     * @var list<PlaceholderProviderInterface>
     */
    private readonly array $providers;

    /**
     * @param iterable<PlaceholderProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly ContractHtmlSanitizer $sanitizer,
    ) {
        $list = [];

        foreach ($providers as $provider) {
            $list[] = $provider;
        }

        $this->providers = $list;
    }

    /**
     * @return list<string>
     */
    public function getKnownKeys(PlaceholderStage|null $stage = null): array
    {
        $keys = [];

        foreach ($this->providers as $provider) {
            if (null !== $stage && $provider->getStage() !== $stage) {
                continue;
            }

            foreach ($provider->getKeys() as $key) {
                $keys[] = $key;
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Returns all well-formed placeholder keys used in the markup.
     *
     * @return list<string>
     */
    public function findPlaceholders(string $html): array
    {
        preg_match_all(self::PLACEHOLDER_REGEX, $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Returns every {{ … }} expression that is not a known placeholder, e.g. typos,
     * Contao insert tags or placeholders broken up by markup.
     *
     * @param list<string> $additionalKeys
     *
     * @return list<string>
     */
    public function findUnknown(string $html, array $additionalKeys = []): array
    {
        $known = array_flip([...$this->getKnownKeys(), ...$additionalKeys]);

        preg_match_all(self::ANY_TAG_REGEX, $html, $matches);

        $unknown = [];

        foreach ($matches[1] as $expression) {
            $name = trim($expression);

            if (!isset($known[$name])) {
                $unknown[] = $name;
            }
        }

        // Unbalanced braces are unknown as well
        $remainder = (string) preg_replace(self::ANY_TAG_REGEX, '', $html);

        if (str_contains($remainder, '{{') || str_contains($remainder, '}}')) {
            $unknown[] = '{{…}}';
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Resolves all placeholders of the given stage. Placeholders of other stages are
     * left untouched.
     */
    public function resolve(string $html, PlaceholderStage $stage, PlaceholderContext $context): string
    {
        $values = $this->collectValues($stage, $context);

        return $this->replace($html, $values);
    }

    /**
     * Replaces the given placeholders with values (strings are escaped, HtmlValue
     * instances are sanitized).
     *
     * @param array<string, string|HtmlValue> $values
     */
    public function replace(string $html, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_REGEX,
            function (array $match) use ($values): string {
                if (!\array_key_exists($match[1], $values)) {
                    return $match[0];
                }

                return $this->toHtml($values[$match[1]]);
            },
            $html,
        );
    }

    /**
     * Inserts already sanitized markup that may itself contain placeholders of a
     * later stage (used for annexes during publishing). The markup is sanitized
     * again, but braces are kept so the placeholders still resolve.
     *
     * @param array<string, string> $sanitizedHtml
     */
    public function insertMarkup(string $html, array $sanitizedHtml): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_REGEX,
            fn (array $match): string => \array_key_exists($match[1], $sanitizedHtml) ? $this->sanitizer->sanitize($sanitizedHtml[$match[1]]) : $match[0],
            $html,
        );
    }

    /**
     * Escapes a plain value for HTML output. Curly braces are encoded as well, so
     * customer input can never form a placeholder or a Contao insert tag.
     */
    public function escape(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return nl2br(str_replace(['{', '}'], ['&#123;', '&#125;'], $escaped), false);
    }

    /**
     * Encodes any remaining curly braces pairs, so the output is never parsed as
     * insert tags by Contao.
     */
    public function neutralize(string $html): string
    {
        return str_replace(['{{', '}}'], ['&#123;&#123;', '&#125;&#125;'], $html);
    }

    private function toHtml(HtmlValue|string $value): string
    {
        if ($value instanceof HtmlValue) {
            return $this->neutralize($this->sanitizer->sanitize($value->html));
        }

        return $this->escape($value);
    }

    /**
     * @return array<string, string|HtmlValue>
     */
    private function collectValues(PlaceholderStage $stage, PlaceholderContext $context): array
    {
        $values = [];

        foreach ($this->providers as $provider) {
            if ($provider->getStage() !== $stage) {
                continue;
            }

            $provided = $provider->provide($context);

            foreach ($provider->getKeys() as $key) {
                if (\array_key_exists($key, $provided)) {
                    $values[$key] = $provided[$key];
                }
            }
        }

        return $values;
    }
}
