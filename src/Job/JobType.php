<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * The kind of a job (decision 0231). A job can have several, e.g. full
 * or part time. The value is also the filter of the list: ?type=part-time.
 */
enum JobType: string
{
    case FullTime = 'full-time';
    case PartTime = 'part-time';
    case MiniJob = 'mini-job';
    case Apprenticeship = 'apprenticeship';
    case DualStudy = 'dual-study';
    case Internship = 'internship';
    case Freelance = 'freelance';

    public function title(): string
    {
        return match ($this) {
            self::FullTime => 'Full time',
            self::PartTime => 'Part time',
            self::MiniJob => 'Mini job',
            self::Apprenticeship => 'Apprenticeship',
            self::DualStudy => 'Dual study',
            self::Internship => 'Internship',
            self::Freelance => 'Freelance',
        };
    }

    /**
     * The employmentType of schema.org for search engines.
     */
    public function schema(): string
    {
        return match ($this) {
            self::FullTime => 'FULL_TIME',
            self::PartTime, self::MiniJob => 'PART_TIME',
            self::Internship => 'INTERN',
            self::Freelance => 'CONTRACTOR',
            self::Apprenticeship, self::DualStudy => 'OTHER',
        };
    }
}
