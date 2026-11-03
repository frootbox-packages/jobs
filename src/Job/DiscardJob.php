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
 * @implements Action<JobDetails|null>
 */
#[AsAction('frootbox/jobs/job.discard', 'Throws away the draft of a job; one that was never published is gone then')]
final readonly class DiscardJob implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(JobEditor $editor): ?JobDetails
    {
        return $editor->discard('frootbox/jobs/job.discard', $this->id);
    }
}
