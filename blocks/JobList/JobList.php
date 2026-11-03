<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Blocks;

use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\BlockController;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Url\Urls;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobType;
use Frootbox\Jobs\Job\PublishedJob;
use Frootbox\Jobs\Website\JobRendering;

/**
 * The open jobs, the newest first, filtered by kind and location without
 * a script. Below the first page with this block each job has its
 * address, there the block shows the job with the application form
 * (decision 0231).
 */
final readonly class JobList implements BlockController
{
    public function __construct(
        private JobStore $store,
        private JobRendering $rendering,
        private Urls $urls,
    ) {}

    public function data(BlockContext $context): array
    {
        $language = $context->contentLanguage;
        $base = null === $context->target ? '' : $this->urls->href($context->target, $context->language) ?? '';
        $subpage = $context->subpage;
        $form = \is_string($context->options['form'] ?? null) ? $context->options['form'] : '';

        if (null !== $subpage && JobStore::TYPE === $subpage->target->type) {
            $job = $this->store->find($subpage->target->id);

            if (null !== $job && isset($job->data->translations[$language])) {
                return ['job' => $this->rendering->detail($job, $language), 'form' => $form, 'back' => $base];
            }
        }

        $jobs = array_values(array_filter($this->store->allPublished(), static fn(PublishedJob $job): bool => isset($job->data->translations[$language])));
        $images = true === ($context->options['images'] ?? false);
        $filtering = false !== ($context->options['filters'] ?? true);

        // The choices are what the jobs have; a filter with one choice is none.
        $types = [];
        $locations = [];

        foreach ($jobs as $job) {
            foreach ($job->data->types as $type) {
                $types[$type->value] = $type;
            }

            $location = $this->rendering->location($job->data);

            if (null !== $location && null !== $job->data->location) {
                $name = $location->text($language)->name;
                $locations[Slug::fromTitle($name, $language)] = ['id' => $job->data->location, 'name' => $name];
            }
        }

        $types = array_values(array_filter(JobType::cases(), static fn(JobType $type): bool => isset($types[$type->value])));
        uasort($locations, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        $type = JobType::tryFrom((string) $context->query('type'));
        $type = null !== $type && \in_array($type, $types, true) && $filtering ? $type : null;
        $place = (string) $context->query('location');
        $place = isset($locations[$place]) && $filtering ? $place : null;

        $shown = array_values(array_filter($jobs, static fn(PublishedJob $job): bool => (null === $type || \in_array($type, $job->data->types, true)) && (null === $place || $job->data->location === $locations[$place]['id'])));
        $link = static fn(?string $type, ?string $place): string => $base . (null === $type && null === $place ? '' : '?' . http_build_query(array_filter(['type' => $type, 'location' => $place], static fn(?string $value): bool => null !== $value)));
        $filters = [];

        if ($filtering && \count($types) > 1) {
            $filters[] = [
                'label' => 'Kind of job',
                'links' => [
                    ['title' => null, 'href' => $link(null, $place), 'current' => null === $type],
                    ...array_map(static fn(JobType $item): array => ['title' => $item->title(), 'href' => $link($item->value, $place), 'current' => $item === $type], $types),
                ],
            ];
        }

        if ($filtering && \count($locations) > 1) {
            $filters[] = [
                'label' => 'Location',
                'links' => [
                    ['title' => null, 'href' => $link($type?->value, null), 'current' => null === $place],
                    ...array_map(static fn(string $slug, array $item): array => ['title' => $item['name'], 'href' => $link($type?->value, $slug), 'current' => $slug === $place], array_keys($locations), $locations),
                ],
            ];
        }

        return [
            'jobs' => array_map(fn(PublishedJob $job): array => $this->rendering->teaser($job, $language, $images), $shown),
            'filters' => $filters,
            'filtered' => null !== $type || null !== $place,
            'total' => \count($jobs),
        ];
    }
}
