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

/**
 * Difference between two sub-processor lists, matched by name (case-insensitive).
 */
final readonly class SubprocessorChanges
{
    /**
     * @param list<Subprocessor> $added     new entries (data of the new list)
     * @param list<Subprocessor> $changed   entries with changed data (data of the new list)
     * @param list<Subprocessor> $removed   entries no longer listed (data of the old list)
     * @param int                $unchanged number of identical entries
     * @param bool               $reordered whether the order of the remaining entries changed
     */
    public function __construct(
        public array $added,
        public array $changed,
        public array $removed,
        public int $unchanged,
        public bool $reordered,
    ) {
    }

    /**
     * Whether the content changed (added, changed or removed entries). A pure
     * re-ordering is not a content change.
     */
    public function hasContentChanges(): bool
    {
        return [] !== $this->added || [] !== $this->changed || [] !== $this->removed;
    }

    /**
     * New or changed entries – the ones a customer can object to.
     *
     * @return list<Subprocessor>
     */
    public function getObjectable(): array
    {
        return [...$this->added, ...$this->changed];
    }

    /**
     * @param list<Subprocessor> $list
     *
     * @return list<string>
     */
    public static function names(array $list): array
    {
        return array_map(static fn (Subprocessor $s): string => $s->name, $list);
    }

    /**
     * @return array{added: list<array{name: string, address: string, service: string, location: string, transferBasis: string}>, changed: list<array{name: string, address: string, service: string, location: string, transferBasis: string}>, removed: list<array{name: string, address: string, service: string, location: string, transferBasis: string}>, unchanged: int, reordered: bool}
     */
    public function toArray(): array
    {
        return [
            'added' => self::listToArray($this->added),
            'changed' => self::listToArray($this->changed),
            'removed' => self::listToArray($this->removed),
            'unchanged' => $this->unchanged,
            'reordered' => $this->reordered,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $map = static function (mixed $list): array {
            $result = [];

            foreach (\is_array($list) ? $list : [] as $item) {
                if (\is_array($item)) {
                    $result[] = Subprocessor::fromArray($item);
                }
            }

            return $result;
        };

        return new self(
            $map($data['added'] ?? []),
            $map($data['changed'] ?? []),
            $map($data['removed'] ?? []),
            \is_int($data['unchanged'] ?? null) ? $data['unchanged'] : 0,
            true === ($data['reordered'] ?? false),
        );
    }

    /**
     * @param list<Subprocessor> $list
     *
     * @return list<array{name: string, address: string, service: string, location: string, transferBasis: string}>
     */
    private static function listToArray(array $list): array
    {
        $result = [];

        foreach ($list as $subprocessor) {
            $result[] = $subprocessor->toArray();
        }

        return $result;
    }
}
