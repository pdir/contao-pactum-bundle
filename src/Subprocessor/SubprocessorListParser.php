<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Subprocessor;

use Pdir\PactumBundle\Domain\Subprocessor;
use Pdir\PactumBundle\Exception\PactumException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a YAML list of subprocessors.
 *
 * Accepted structure: a top-level key "subprocessors" (or "subunternehmer") or a
 * plain list. Each entry supports English or German keys:
 *
 *   name – company name (required)
 *   address | anschrift – string or list of lines (required)
 *   service | leistung – description of the service (required)
 *   country | land – country of processing (optional, appended to the address)
 *   location – "eu" or "third_country" (optional, derived from the country)
 *   transfer_basis | hinweis – legal basis for third-country transfers (Art. 44 ff. GDPR)
 */
class SubprocessorListParser
{
    private const MAX_LENGTH = 255;

    private const KEY_ALIASES = [
        'name' => ['name'],
        'address' => ['address', 'anschrift'],
        'service' => ['service', 'leistung'],
        'country' => ['country', 'land'],
        'location' => ['location'],
        'transfer_basis' => ['transfer_basis', 'transferBasis', 'hinweis'],
    ];

    /**
     * EU member states and EEA countries (ISO code, German and English name).
     */
    private const EU_EEA = [
        'at',
        'österreich',
        'austria',
        'be',
        'belgien',
        'belgium',
        'bg',
        'bulgarien',
        'bulgaria',
        'hr',
        'kroatien',
        'croatia',
        'cy',
        'zypern',
        'cyprus',
        'cz',
        'tschechien',
        'czechia',
        'czech republic',
        'dk',
        'dänemark',
        'denmark',
        'ee',
        'estland',
        'estonia',
        'fi',
        'finnland',
        'finland',
        'fr',
        'frankreich',
        'france',
        'de',
        'd',
        'deutschland',
        'germany',
        'gr',
        'griechenland',
        'greece',
        'hu',
        'ungarn',
        'hungary',
        'ie',
        'irland',
        'ireland',
        'it',
        'italien',
        'italy',
        'lv',
        'lettland',
        'latvia',
        'lt',
        'litauen',
        'lithuania',
        'lu',
        'luxemburg',
        'luxembourg',
        'mt',
        'malta',
        'nl',
        'niederlande',
        'netherlands',
        'pl',
        'polen',
        'poland',
        'pt',
        'portugal',
        'ro',
        'rumänien',
        'romania',
        'sk',
        'slowakei',
        'slovakia',
        'si',
        'slowenien',
        'slovenia',
        'es',
        'spanien',
        'spain',
        'se',
        'schweden',
        'sweden',
        'is',
        'island',
        'iceland',
        'li',
        'liechtenstein',
        'no',
        'norwegen',
        'norway',
    ];

    /**
     * @return list<Subprocessor>
     *
     * @throws PactumException
     */
    public function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new PactumException(\sprintf('The file "%s" does not exist or is not readable.', $path));
        }

        return $this->parse((string) file_get_contents($path));
    }

    /**
     * @return list<Subprocessor>
     *
     * @throws PactumException
     */
    public function parse(string $yaml): array
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new PactumException('Invalid YAML: '.$e->getMessage(), 0, $e);
        }

        if (\is_array($data) && !array_is_list($data)) {
            $data = $data['subprocessors'] ?? $data['subunternehmer'] ?? null;
        }

        if (!\is_array($data) || [] === $data || !array_is_list($data)) {
            throw new PactumException('The file does not contain a list of subprocessors.');
        }

        $result = [];
        $names = [];

        foreach ($data as $index => $entry) {
            $position = $index + 1;

            if (!\is_array($entry)) {
                throw new PactumException(\sprintf('Entry %d is not a key/value map.', $position));
            }

            $subprocessor = $this->parseEntry($entry, $position);
            $key = mb_strtolower($subprocessor->name);

            if (isset($names[$key])) {
                throw new PactumException(\sprintf('Entry %d: "%s" is listed more than once.', $position, $subprocessor->name));
            }

            $names[$key] = true;
            $result[] = $subprocessor;
        }

        return $result;
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws PactumException
     */
    private function parseEntry(array $entry, int $position): Subprocessor
    {
        $name = $this->string($entry, 'name', $position);
        $label = '' !== $name ? \sprintf('Entry %d (%s)', $position, $name) : \sprintf('Entry %d', $position);

        $addressLines = $this->lines($entry, 'address', $label);
        $service = $this->string($entry, 'service', $position);
        $country = $this->string($entry, 'country', $position);
        $location = $this->string($entry, 'location', $position);
        $transferBasis = $this->string($entry, 'transfer_basis', $position);

        $missing = array_keys(array_filter(['name' => $name, 'address' => implode('', $addressLines), 'service' => $service], static fn (string $v): bool => '' === $v));

        if ([] !== $missing) {
            throw new PactumException(\sprintf('%s: missing required fields: %s.', $label, implode(', ', $missing)));
        }

        if ('' === $location) {
            if ('' === $country) {
                throw new PactumException(\sprintf('%s: either "country" or "location" is required.', $label));
            }

            $location = \in_array(mb_strtolower($country), self::EU_EEA, true) ? Subprocessor::LOCATION_EU : Subprocessor::LOCATION_THIRD_COUNTRY;
        }

        if (!\in_array($location, [Subprocessor::LOCATION_EU, Subprocessor::LOCATION_THIRD_COUNTRY], true)) {
            throw new PactumException(\sprintf('%s: "location" must be "eu" or "third_country".', $label));
        }

        if (Subprocessor::LOCATION_THIRD_COUNTRY === $location && '' === $transferBasis) {
            throw new PactumException(\sprintf('%s: a third-country subprocessor requires a "transfer_basis" (Art. 44 ff. GDPR).', $label));
        }

        if (mb_strlen($name) > self::MAX_LENGTH || mb_strlen($transferBasis) > self::MAX_LENGTH) {
            throw new PactumException(\sprintf('%s: "name" and "transfer_basis" must not exceed %d characters.', $label, self::MAX_LENGTH));
        }

        // The data model has no separate country field: keep the country visible as last
        // address line.
        if ('' !== $country && mb_strtolower((string) end($addressLines)) !== mb_strtolower($country)) {
            $addressLines[] = $country;
        }

        return new Subprocessor($name, implode("\n", $addressLines), $service, $location, $transferBasis);
    }

    /**
     * @param array<mixed> $entry
     *
     * @throws PactumException
     */
    private function string(array $entry, string $field, int $position): string
    {
        $value = $this->value($entry, $field);

        if (null === $value) {
            return '';
        }

        if (!\is_scalar($value)) {
            throw new PactumException(\sprintf('Entry %d: "%s" must be a string.', $position, $field));
        }

        return trim((string) $value);
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<string>
     *
     * @throws PactumException
     */
    private function lines(array $entry, string $field, string $label): array
    {
        $value = $this->value($entry, $field);

        if (null === $value) {
            return [];
        }

        if (\is_scalar($value)) {
            $value = preg_split('/\R/', (string) $value) ?: [];
        }

        if (!\is_array($value)) {
            throw new PactumException(\sprintf('%s: "%s" must be a string or a list of lines.', $label, $field));
        }

        $lines = [];

        foreach ($value as $line) {
            if (!\is_scalar($line)) {
                throw new PactumException(\sprintf('%s: "%s" must only contain text lines.', $label, $field));
            }

            if ('' !== trim((string) $line)) {
                $lines[] = trim((string) $line);
            }
        }

        return $lines;
    }

    /**
     * @param array<mixed> $entry
     */
    private function value(array $entry, string $field): mixed
    {
        foreach (self::KEY_ALIASES[$field] as $key) {
            if (\array_key_exists($key, $entry)) {
                return $entry[$key];
            }
        }

        return null;
    }
}
