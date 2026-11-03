<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use DateTimeImmutable;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<JobDetails>
 */
#[AsAction('frootbox/jobs/job.schedule', 'Plans when the draft of a job is published and when it goes offline')]
final readonly class ScheduleJob implements Action
{
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('When the draft is published; left out or null for no time')]
        public ?DateTimeImmutable $publishAt = null,
        #[Description('When the job goes offline, e.g. at the end of applications; left out or null for no time')]
        public ?DateTimeImmutable $unpublishAt = null,
    ) {}

    public function handle(JobEditor $editor): JobDetails
    {
        return $editor->schedule('frootbox/jobs/job.schedule', $this->id, $this->publishAt, $this->unpublishAt);
    }
}
