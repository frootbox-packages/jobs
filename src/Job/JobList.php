<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * A page of the list of jobs.
 */
final readonly class JobList
{
    /**
     * @param list<JobSummary> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
