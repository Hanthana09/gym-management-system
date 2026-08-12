<?php

namespace App\Reporting;

/** One day's revenue — either actual (historical) or projected. */
final class RevenueForecastPoint
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $revenue,
    ) {
    }
}
