<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * A salary or its range in whole units of the currency (decision 0231),
 * e.g. 2800 to 3200 EUR per month. One of both is enough.
 */
final readonly class Salary
{
    public const string CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
        public SalaryPeriod $per = SalaryPeriod::Month,
        public string $currency = 'EUR',
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $min = \is_int($data['min'] ?? null) ? $data['min'] : null;
        $max = \is_int($data['max'] ?? null) ? $data['max'] : null;

        if (null === $min && null === $max) {
            return null;
        }

        return new self(
            $min,
            $max,
            \is_string($data['per'] ?? null) ? SalaryPeriod::tryFrom($data['per']) ?? SalaryPeriod::Month : SalaryPeriod::Month,
            \is_string($data['currency'] ?? null) && 1 === preg_match(self::CURRENCY_PATTERN, $data['currency']) ? $data['currency'] : 'EUR',
        );
    }

    /**
     * @return array{min: int|null, max: int|null, per: string, currency: string}
     */
    public function toArray(): array
    {
        return ['min' => $this->min, 'max' => $this->max, 'per' => $this->per->value, 'currency' => $this->currency];
    }
}
