<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\Plugin\SpaceScopes;

/**
 * @implements Action<list<JobDetails>>
 */
#[AsAction('frootbox/jobs/job.publish', 'Publishes the drafts of one or more jobs')]
final readonly class PublishJobs implements Action, Scoped
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        #[ListOf('string')]
        public array $ids,
    ) {}

    /**
     * Each object within its space (decision 0245).
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return array_map(static fn(string $id): Scope => SpaceScopes::object(JobStore::TYPE, $id), $this->ids);
    }

    /**
     * @return list<JobDetails>
     */
    public function handle(JobEditor $editor): array
    {
        return $editor->publish('frootbox/jobs/job.publish', $this->ids);
    }
}
