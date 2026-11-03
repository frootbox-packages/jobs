<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Blocks;

use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\BlockController;
use Frootbox\Core\Url\BasePages;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\Urls;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\PublishedJob;
use Frootbox\Jobs\JobPages;
use Frootbox\Jobs\Website\JobRendering;

/**
 * The newest jobs, e.g. on the start page, with a link to all (decision 0231).
 */
final readonly class JobTeaser implements BlockController
{
    public function __construct(
        private JobStore $store,
        private JobRendering $rendering,
        private BasePages $bases,
        private Urls $urls,
    ) {}

    public function data(BlockContext $context): array
    {
        $language = $context->contentLanguage;
        $count = max(1, min(12, is_numeric($context->options['count'] ?? null) ? (int) $context->options['count'] : 3));
        $base = $this->bases->of([JobPages::LIST_BLOCK])[$context->language] ?? null;
        $jobs = array_filter($this->store->allPublished(), static fn(PublishedJob $job): bool => isset($job->data->translations[$language]));

        return [
            'jobs' => array_map(fn(PublishedJob $job): array => $this->rendering->teaser($job, $language, false), \array_slice(array_values($jobs), 0, $count)),
            'all' => null === $base ? null : $this->urls->href(Target::page($base), $context->language),
        ];
    }
}
