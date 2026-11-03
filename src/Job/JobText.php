<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * The texts of a job in one language.
 */
final readonly class JobText
{
    public const array EMPTY_TEXT = ['type' => 'doc', 'content' => []];

    /**
     * @param string       $teaser A few sentences for the list and for search engines
     * @param array<mixed> $text   Tasks, profile and offer as rich text (Tiptap JSON)
     */
    public function __construct(
        public string $title,
        public string $slug,
        public string $teaser = '',
        public array $text = self::EMPTY_TEXT,
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            \is_string($data['title'] ?? null) ? $data['title'] : '',
            \is_string($data['slug'] ?? null) ? $data['slug'] : '',
            \is_string($data['teaser'] ?? null) ? $data['teaser'] : '',
            \is_array($data['text'] ?? null) ? $data['text'] : self::EMPTY_TEXT,
        );
    }

    /**
     * @return array{title: string, slug: string, teaser: string, text: array<mixed>}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'slug' => $this->slug, 'teaser' => $this->teaser, 'text' => $this->text];
    }
}
