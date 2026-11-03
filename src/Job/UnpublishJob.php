<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<JobDetails>
 */
#[AsAction('frootbox/jobs/job.unpublish', 'Takes a job offline; it stays as a draft and can be published again')]
final readonly class UnpublishJob implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(JobEditor $editor): JobDetails
    {
        return $editor->unpublish('frootbox/jobs/job.unpublish', $this->id);
    }
}
