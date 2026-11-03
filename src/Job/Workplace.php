<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * Where the work is done (decision 0231).
 */
enum Workplace: string
{
    case OnSite = 'on-site';
    case Hybrid = 'hybrid';
    case Remote = 'remote';

    public function title(): string
    {
        return match ($this) {
            self::OnSite => 'On site',
            self::Hybrid => 'Partly remote',
            self::Remote => 'Remote',
        };
    }
}
