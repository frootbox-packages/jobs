<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use DateTimeImmutable;

/**
 * A job in the list of the backend.
 */
final readonly class JobSummary
{
    /**
     * @param list<string> $languages The languages it has, in the draft if there is one
     * @param list<JobType> $types
     */
    public function __construct(
        public string $id,
        public JobStatus $status,
        public string $title,
        public array $types,
        public array $languages,
        public ?DateTimeImmutable $publishAt = null,
        public ?DateTimeImmutable $unpublishAt = null,
    ) {}
}
