<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\ListOf;

/**
 * @implements Action<list<JobDetails>>
 */
#[AsAction('frootbox/jobs/job.publish', 'Publishes the drafts of one or more jobs')]
final readonly class PublishJobs implements Action
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        #[ListOf('string')]
        public array $ids,
    ) {}

    /**
     * @return list<JobDetails>
     */
    public function handle(JobEditor $editor): array
    {
        return $editor->publish('frootbox/jobs/job.publish', $this->ids);
    }
}
