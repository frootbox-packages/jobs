<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use DateTimeImmutable;

/**
 * A job with its draft, published version and planned times.
 */
final readonly class JobDetails
{
    /**
     * @param array<string, string> $urls Where visitors find it by language, e.g. {"de": "/jobs/barista"}
     */
    public function __construct(
        public string $id,
        public JobStatus $status,
        public ?int $version,
        public ?JobData $draft,
        public ?JobData $published,
        public ?DateTimeImmutable $publishAt = null,
        public ?DateTimeImmutable $unpublishAt = null,
        public array $urls = [],
    ) {}

    /**
     * The draft, else the published version.
     */
    public function current(): JobData
    {
        return $this->draft ?? $this->published ?? new JobData();
    }
}
