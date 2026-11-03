<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs;

use Frootbox\Core\Plugin\Offer;
use Frootbox\Core\Plugin\Plugin;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobText;

/**
 * The jobs as a plugin (decisions 0231, 0232): a page that shows a space
 * lists its jobs at plugin(), each published job has its address below
 * that page, its slug. The teaser and the image describe it for search
 * engines and sharing (decision 0223).
 */
final readonly class JobPlugin implements Plugin
{
    public const string LIST_BLOCK = 'frootbox/jobs/JobList';

    public function __construct(private JobStore $store) {}

    public function type(): string
    {
        return JobStore::TYPE;
    }

    public function label(): string
    {
        return 'Jobs';
    }

    public function block(): string
    {
        return self::LIST_BLOCK;
    }

    public function details(): Offer
    {
        return Offer::Required;
    }

    public function categories(): Offer
    {
        return Offer::Optional;
    }

    public function spaces(): bool
    {
        return true;
    }

    public function paths(string $space): iterable
    {
        foreach ($this->store->allPublished($space) as $job) {
            yield $job->id => array_map(static fn(JobText $text): string => $text->slug, $job->data->translations);
        }
    }

    public function count(string $space): int
    {
        return \count(array_filter($this->store->ids(), fn(string $id): bool => $this->store->current($id)->space === $space));
    }

    public function title(string $id, string $language): ?string
    {
        $title = $this->store->published($id)->translations[$language]->title ?? null;

        return null === $title || '' === $title ? null : $title;
    }

    public function description(string $id, string $language): ?string
    {
        $teaser = $this->store->published($id)->translations[$language]->teaser ?? null;

        return null === $teaser || '' === $teaser ? null : $teaser;
    }

    public function image(string $id, string $language): ?array
    {
        $image = $this->store->published($id)?->image;
        $media = $image['media'] ?? null;

        if (!\is_string($media) || '' === $media) {
            return null;
        }

        $alt = $image['alt'] ?? null;

        return \is_string($alt) ? ['media' => $media, 'alt' => $alt] : ['media' => $media];
    }
}
