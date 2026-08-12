<?php

namespace App\Reporting;

/**
 * functional requirements §10.3: "a clear indication of how it was
 * calculated" (`method`) and an explicit "not enough data yet" state
 * (`hasEnoughData`) rather than a misleadingly confident number.
 */
final class RevenueForecastResult
{
    /**
     * @param RevenueForecastPoint[] $historical
     * @param RevenueForecastPoint[] $projected
     */
    public function __construct(
        public readonly bool $hasEnoughData,
        public readonly array $historical,
        public readonly array $projected,
        public readonly ?string $method = null,
    ) {
    }
}
