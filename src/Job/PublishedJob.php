<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use DateTimeImmutable;

/**
 * A job online on the website.
 */
final readonly class PublishedJob
{
    /**
     * @param DateTimeImmutable      $posted The day it was first published
     * @param DateTimeImmutable|null $until  When it goes offline by itself, e.g. the end of applications
     */
    public function __construct(
        public string $id,
        public JobData $data,
        public DateTimeImmutable $posted,
        public ?DateTimeImmutable $until = null,
    ) {}
}
