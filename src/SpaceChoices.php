<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Plugin\PluginSpaces;
use Frootbox\Jobs\Job\JobStore;

/**
 * @implements Query<list<array{value: string, label: string}>>
 */
#[AsQuery('frootbox/jobs/space.choices', 'The spaces of the jobs as choices for a block, "All" first; empty with only one space', permission: 'frootbox/jobs/job.list')]
final readonly class SpaceChoices implements Query
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function handle(PluginSpaces $spaces): array
    {
        return $spaces->choices(JobStore::TYPE);
    }
}
