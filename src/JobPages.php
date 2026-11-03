<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs;

use Frootbox\Core\Url\DescribedSubpages;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobText;

/**
 * Each published job has an address below the first page with the list
 * of jobs, its slug (decisions 0219, 0231). The teaser and the image
 * describe it for search engines and sharing (decision 0223).
 */
final readonly class JobPages implements DescribedSubpages
{
    public const string LIST_BLOCK = 'frootbox/jobs/JobList';

    public function __construct(private JobStore $store) {}

    public function type(): string
    {
        return JobStore::TYPE;
    }

    public function blocks(): array
    {
        return [self::LIST_BLOCK];
    }

    public function paths(): iterable
    {
        foreach ($this->store->allPublished() as $job) {
            yield $job->id => array_map(static fn(JobText $text): string => $text->slug, $job->data->translations);
        }
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
