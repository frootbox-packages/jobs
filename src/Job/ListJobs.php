<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Language\Languages;

/**
 * @implements Query<JobList>
 */
#[AsQuery('frootbox/jobs/job.list', 'Lists the jobs with drafts and published ones, by title')]
final readonly class ListJobs implements Query
{
    public function __construct(
        #[Description('Language of the titles; left out for the first language of the website')]
        public ?string $language = null,
        #[Description('Only jobs whose title holds this text')]
        #[Length(max: 100)]
        public ?string $search = null,
        #[Range(min: 1)]
        public int $page = 1,
        #[Range(min: 1, max: 200)]
        public int $perPage = 50,
        #[Description('Only the jobs of this space; left out for all')]
        public ?string $space = null,
    ) {}

    public function handle(JobStore $store, Languages $languages): JobList
    {
        $language = null !== $this->language && $languages->has($this->language) ? $this->language : $languages->default()->code;
        $search = mb_strtolower(trim($this->search ?? ''));
        $items = [];

        foreach ($store->ids() as $id) {
            $details = $store->details($id);
            $data = $details->current();

            if (null !== $this->space && $data->space !== $this->space) {
                continue;
            }
            $title = $data->text($language)->title ?? '';

            if ('' !== $search && !str_contains(mb_strtolower($title), $search)) {
                continue;
            }

            $items[] = new JobSummary($id, $details->status, $title, $data->types, array_keys($data->translations), $details->publishAt, $details->unpublishAt);
        }

        usort($items, static fn(JobSummary $a, JobSummary $b): int => [mb_strtolower($a->title), $a->id] <=> [mb_strtolower($b->title), $b->id]);
        $pages = max(1, (int) ceil(\count($items) / $this->perPage));

        return new JobList(\array_slice($items, ($this->page - 1) * $this->perPage, $this->perPage), \count($items), $this->page, $pages);
    }
}
