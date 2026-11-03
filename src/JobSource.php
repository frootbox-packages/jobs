<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs;

use Frootbox\Core\Plugin\SpaceScopes;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Search\AsSearchSource;
use Frootbox\Core\Search\PublishedEntry;
use Frootbox\Core\Search\PublishedSource;
use Frootbox\Core\Search\SearchEntry;
use Frootbox\Core\Url\Target;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobText;
use Frootbox\RichText\RichText;

/**
 * Jobs in the search of the backend, as editors see them: drafts first
 * (decision 0217); on the website the published ones (decision 0228).
 */
#[AsSearchSource('frootbox/jobs/job', 'Jobs')]
final readonly class JobSource implements PublishedSource
{
    public function __construct(
        private JobStore $store,
        private RichText $richText,
    ) {}

    public function changed(string $table, array $row): array
    {
        $id = Revisions::TABLE === $table && JobStore::TYPE === ($row['type'] ?? null) ? $row['entity_id'] ?? null : null;

        return \is_string($id) ? [$id] : [];
    }

    public function items(): iterable
    {
        yield from $this->store->ids();
    }

    public function entries(array $items): iterable
    {
        foreach ($items as $id) {
            try {
                $data = $this->store->details($id)->current();
            } catch (EntityNotFoundException) {
                continue;
            }

            foreach ($data->translations as $language => $text) {
                yield new SearchEntry(
                    item: $id,
                    title: '' === $text->title ? $id : $text->title,
                    text: $this->text($text),
                    permission: 'frootbox/jobs/job.show',
                    language: $language,
                    // Only for roles within its space (decision 0245).
                    scopeKind: SpaceScopes::KIND,
                    scopeId: SpaceScopes::object(JobStore::TYPE, $id)->id,
                );
            }
        }
    }

    /**
     * The published version for the search on the website (decision 0228).
     */
    public function published(array $items): iterable
    {
        foreach ($items as $id) {
            foreach ($this->store->published($id)->translations ?? [] as $language => $text) {
                yield new PublishedEntry($id, $language, $text->title, $this->text($text), Target::parse(JobStore::target($id)));
            }
        }
    }

    private function text(JobText $text): string
    {
        return implode("\n", array_filter([$text->teaser, $this->richText->toText($text->text)], static fn(string $part): bool => '' !== trim($part)));
    }
}
