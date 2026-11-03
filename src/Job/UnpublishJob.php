<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Plugin\SpaceScopes;
use Frootbox\Database\Ulid;

/**
 * @implements Action<JobDetails>
 */
#[AsAction('frootbox/jobs/job.unpublish', 'Takes a job offline; it stays as a draft and can be published again')]
final readonly class UnpublishJob implements Action, Scoped
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
    ) {}

    /**
     * The object within its space (decision 0245).
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [SpaceScopes::object(JobStore::TYPE, $this->id)];
    }

    public function handle(JobEditor $editor): JobDetails
    {
        return $editor->unpublish('frootbox/jobs/job.unpublish', $this->id);
    }
}
