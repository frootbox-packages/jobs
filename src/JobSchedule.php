<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Schedule\ScheduledType;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\PublishJobs;
use Frootbox\Jobs\Job\UnpublishJob;

/**
 * The scheduler publishes jobs and takes them offline at their time.
 */
final readonly class JobSchedule implements ScheduledType
{
    public function type(): string
    {
        return JobStore::TYPE;
    }

    public function publish(array $ids): Action
    {
        return new PublishJobs($ids);
    }

    public function unpublish(string $id): Action
    {
        return new UnpublishJob($id);
    }
}
