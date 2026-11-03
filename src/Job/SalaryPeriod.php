<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * What a salary is paid for (decision 0231).
 */
enum SalaryPeriod: string
{
    case Hour = 'hour';
    case Month = 'month';
    case Year = 'year';

    /**
     * The unitText of schema.org for search engines.
     */
    public function schema(): string
    {
        return strtoupper($this->value);
    }
}
