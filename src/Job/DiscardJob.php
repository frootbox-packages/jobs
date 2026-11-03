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
 * @implements Action<JobDetails|null>
 */
#[AsAction('frootbox/jobs/job.discard', 'Throws away the draft of a job; one that was never published is gone then')]
final readonly class DiscardJob implements Action, Scoped
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

    public function handle(JobEditor $editor): ?JobDetails
    {
        return $editor->discard('frootbox/jobs/job.discard', $this->id);
    }
}
