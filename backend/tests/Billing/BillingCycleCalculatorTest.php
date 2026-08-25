<?php

namespace App\Tests\Billing;

use App\Billing\BillingCycleCalculator;
use PHPUnit\Framework\TestCase;

/**
 * gym-management-billing-v1.md §5.1 / §8's required case: "billingAnchorDay
 * = 31 rolling into a 30-day or 28/29-day month → clamps to last day, no
 * crash." Also confirms the un-clamped, non-edge-case path and that
 * clamping is per-occurrence (a clamped month doesn't permanently lower
 * the anchor day — the next full month rolls back up to it).
 */
final class BillingCycleCalculatorTest extends TestCase
{
    public function test_normal_anchor_day_advances_to_the_same_day_next_month(): void
    {
        $result = BillingCycleCalculator::advance(new \DateTimeImmutable('2027-01-15'), 15);

        self::assertSame('2027-02-15', $result->format('Y-m-d'));
    }

    public function test_anchor_day_31_clamps_into_a_28_day_february(): void
    {
        // 2027 is not a leap year.
        $result = BillingCycleCalculator::advance(new \DateTimeImmutable('2027-01-31'), 31);

        self::assertSame('2027-02-28', $result->format('Y-m-d'));
    }

    public function test_anchor_day_31_clamps_into_a_29_day_leap_february(): void
    {
        $result = BillingCycleCalculator::advance(new \DateTimeImmutable('2028-01-31'), 31);

        self::assertSame('2028-02-29', $result->format('Y-m-d'));
    }

    public function test_anchor_day_31_clamps_into_a_30_day_month(): void
    {
        $result = BillingCycleCalculator::advance(new \DateTimeImmutable('2027-03-15'), 31);

        self::assertSame('2027-04-30', $result->format('Y-m-d'));
    }

    /** A clamped month doesn't permanently lower the anchor — the next 31-day month rolls back up to the true anchor day. */
    public function test_anchor_day_rolls_back_up_once_a_31_day_month_is_reached_again(): void
    {
        $clamped = BillingCycleCalculator::advance(new \DateTimeImmutable('2027-03-15'), 31); // 2027-04-30
        $next = BillingCycleCalculator::advance($clamped, 31);

        self::assertSame('2027-05-31', $next->format('Y-m-d'));
    }
}
