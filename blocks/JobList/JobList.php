<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Blocks;

use Frootbox\Core\Category\Category;
use Frootbox\Core\Category\ObjectCategories;
use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\BlockController;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Plugin\CategoryPages;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Tag\ObjectTags;
use Frootbox\Core\Tag\Tag;
use Frootbox\Core\Url\Urls;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobType;
use Frootbox\Jobs\Job\PublishedJob;
use Frootbox\Jobs\Website\JobRendering;

/**
 * The open jobs, the newest first, filtered by kind, location, tag
 * (decision 0239) and category (with the ones below it, decision 0233)
 * without a script, of the space the page shows: the view of the jobs at
 * plugin() (decision 0232). Below the page each job has its address,
 * there the view shows the job with the application form (decision 0231).
 */
final readonly class JobList implements BlockController
{
    public function __construct(
        private JobStore $store,
        private JobRendering $rendering,
        private Urls $urls,
        private ObjectCategories $categories,
        private ObjectTags $tags,
        private CategoryPages $pages,
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
                return ['job' => $this->rendering->detail($job, $language, $context->language), 'form' => $form, 'back' => $base];
            }
        }

        $jobs = array_values(array_filter($this->store->allPublished($context->plugin->space ?? Space::DEFAULT), static fn(PublishedJob $job): bool => isset($job->data->translations[$language])));
        $images = true === ($context->options['images'] ?? false);
        $filtering = false !== ($context->options['filters'] ?? true);

        // The choices are what the jobs have; a filter with one choice is none.
        $types = [];
        $locations = [];
        $assigned = $this->categories->of(JobStore::TYPE, array_map(static fn(PublishedJob $job): string => $job->id, $jobs));
        $used = array_flip(array_map(static fn(Category $category): string => $category->id, array_merge(...array_values($assigned))));
        $topics = [];
        $tagged = $this->tags->of(JobStore::TYPE, array_map(static fn(PublishedJob $job): string => $job->id, $jobs));
        $words = [];

        foreach (array_merge(...array_values($tagged)) as $tag) {
            foreach ($this->tags->visible([$tag], $language) as $word) {
                $words[$word['slug']] = ['id' => $tag->id, 'title' => $word['label']];
            }
        }

        uasort($words, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['title']), mb_strtolower($b['title'])));

        foreach ($this->categories->tree(JobStore::TYPE, JobStore::CATEGORIES) as $item) {
            $slug = $item->slug($language);
            $title = $item->title($language);

            if (isset($used[$item->id]) && null !== $slug && null !== $title) {
                $topics[$slug] = ['id' => $item->id, 'title' => $title];
            }
        }

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
        $word = (string) $context->query('tag');
        $word = isset($words[$word]) && $filtering ? $word : null;
        // On the page of a category it is that one (decision 0243).
        $own = $this->pages->current($context);
        [$topic, $chosen] = $own ?? ($filtering ? $this->categories->bySlug(JobStore::TYPE, $language, (string) $context->query('category')) : null) ?? [null, null];
        $here = null === $own || null === $subpage ? $base : $this->urls->href($subpage->target, $context->language) ?? $base;

        $shown = array_values(array_filter($jobs, static fn(PublishedJob $job): bool => (null === $type || \in_array($type, $job->data->types, true))
            && (null === $place || $job->data->location === $locations[$place]['id'])
            && (null === $word || \in_array($words[$word]['id'], array_map(static fn(Tag $tag): string => $tag->id, $tagged[$job->id] ?? []), true))
            && (null === $chosen || [] !== array_intersect($chosen, array_map(static fn(Category $category): string => $category->id, $assigned[$job->id] ?? [])))));
        $current = ['category' => null === $own ? $topic?->slug($language) : null, 'type' => $type?->value, 'location' => $place, 'tag' => $word];
        $link = static function (array $change, ?string $to = null) use ($here, $current): string {
            $query = array_filter([...$current, ...$change], static fn(mixed $value): bool => null !== $value);

            return ($to ?? $here) . ([] === $query ? '' : '?' . http_build_query($query));
        };
        $filters = [];

        if ($filtering && \count($topics) > 1) {
            $filters[] = [
                'label' => 'Category',
                'links' => [
                    ['title' => null, 'href' => $link(['category' => null], $base), 'current' => null === $topic],
                    ...array_map(fn(string $slug, array $item): array => ['title' => $item['title'], 'href' => $this->categoryLink($context, $item['id'], $slug, $link), 'current' => $item['id'] === $topic?->id], array_keys($topics), $topics),
                ],
            ];
        }

        if ($filtering && \count($types) > 1) {
            $filters[] = [
                'label' => 'Kind of job',
                'links' => [
                    ['title' => null, 'href' => $link(['type' => null]), 'current' => null === $type],
                    ...array_map(static fn(JobType $item): array => ['title' => $item->title(), 'href' => $link(['type' => $item->value]), 'current' => $item === $type], $types),
                ],
            ];
        }

        if ($filtering && \count($locations) > 1) {
            $filters[] = [
                'label' => 'Location',
                'links' => [
                    ['title' => null, 'href' => $link(['location' => null]), 'current' => null === $place],
                    ...array_map(static fn(string $slug, array $item): array => ['title' => $item['name'], 'href' => $link(['location' => $slug]), 'current' => $slug === $place], array_keys($locations), $locations),
                ],
            ];
        }

        if ($filtering && \count($words) > 1) {
            $filters[] = [
                'label' => 'Tag',
                'links' => [
                    ['title' => null, 'href' => $link(['tag' => null]), 'current' => null === $word],
                    ...array_map(static fn(string $slug, array $item): array => ['title' => $item['title'], 'href' => $link(['tag' => $slug]), 'current' => $slug === $word], array_keys($words), $words),
                ],
            ];
        }

        return [
            'jobs' => array_map(fn(PublishedJob $job): array => $this->rendering->teaser($job, $language, $images), $shown),
            'filters' => $filters,
            'filtered' => null !== $type || null !== $place || null !== $topic || null !== $word,
            'category' => null === $own ? null : ['title' => $own[0]->titleIn([$language]), 'description' => $own[0]->description($language)],
            'total' => \count($jobs),
        ];
    }

    /**
     * A category of the filter: its page if it has one (decision 0243),
     * else the list with ?category=; the other filters stay.
     *
     * @param callable(array<string, string|null>, string|null): string $link
     */
    private function categoryLink(BlockContext $context, string $id, string $slug, callable $link): string
    {
        $page = $this->pages->href($context, $id);

        return null === $page ? $link(['category' => $slug], null) : $link(['category' => null], $page);
    }
}
