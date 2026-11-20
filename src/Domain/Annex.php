<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Domain;

final readonly class Annex
{
    /**
     * @param list<Subprocessor> $subprocessors
     */
    public function __construct(
        public int $id,
        public AnnexType $type,
        public string $title,
        public string $content,
        public array $subprocessors = [],
    ) {
    }

    /**
     * @return array{id: int, type: string, title: string, content: string, subprocessors: list<array{name: string, address: string, service: string, location: string, transferBasis: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'content' => $this->content,
            'subprocessors' => array_map(static fn (Subprocessor $s): array => $s->toArray(), $this->subprocessors),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $subprocessors = [];

        foreach ((array) ($data['subprocessors'] ?? []) as $sub) {
            if (\is_array($sub)) {
                $subprocessors[] = Subprocessor::fromArray($sub);
            }
        }

        return new self(
            (int) ($data['id'] ?? 0),
            AnnexType::tryFrom((string) ($data['type'] ?? '')) ?? AnnexType::Custom,
            (string) ($data['title'] ?? ''),
            (string) ($data['content'] ?? ''),
            $subprocessors,
        );
    }
}
