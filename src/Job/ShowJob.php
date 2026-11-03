<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Query<JobDetails>
 */
#[AsQuery('frootbox/jobs/job.show', 'Returns a job with its draft, its published version and where visitors find it')]
final readonly class ShowJob implements Query
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    public function handle(JobStore $store): JobDetails
    {
        return $store->details($this->id, true);
    }
}
