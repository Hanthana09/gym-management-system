<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\DailyMetricSnapshot;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Reporting\RevenueForecaster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * architecture doc §6.8: plain weighted moving average + an explainable
 * renewal-risk adjustment — no ML, hand-verifiable arithmetic throughout.
 * functional requirements §10.3: the explicit "not enough data" state is
 * tested first, since a forecast with too little history must never
 * produce a confident-looking number.
 */
final class RevenueForecasterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RevenueForecaster $forecaster;
    private Gym $gym;
    private Branch $branch;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->forecaster = static::getContainer()->get(RevenueForecaster::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE daily_metric_snapshot, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );

        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $this->gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($this->gym);
        $this->branch = new Branch($this->gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($this->branch);
        $this->em->flush();

        $this->today = new \DateTimeImmutable('today');
    }

    /** @param array<int, string> $revenues oldest-first */
    private function snapshotsWithRevenue(array $revenues): array
    {
        $snapshots = [];
        $daysAgo = count($revenues);
        foreach ($revenues as $revenue) {
            $date = $this->today->modify("-{$daysAgo} days");
            $snapshot = new DailyMetricSnapshot($this->gym, $date, 0, 0, 0, 0, $revenue, 0);
            $this->em->persist($snapshot);
            $snapshots[] = $snapshot;
            --$daysAgo;
        }
        $this->em->flush();

        return $snapshots;
    }

    public function test_fewer_than_14_days_of_history_reports_not_enough_data(): void
    {
        $snapshots = $this->snapshotsWithRevenue(array_fill(0, 13, '100.00'));

        $result = $this->forecaster->forecast($snapshots, 30, $this->today);

        self::assertFalse($result->hasEnoughData);
        self::assertSame([], $result->projected);
        self::assertCount(13, $result->historical);
    }

    public function test_constant_revenue_history_projects_the_same_flat_amount(): void
    {
        $snapshots = $this->snapshotsWithRevenue(array_fill(0, 14, '100.00'));

        $result = $this->forecaster->forecast($snapshots, 30, $this->today);

        self::assertTrue($result->hasEnoughData);
        self::assertCount(30, $result->projected);
        foreach ($result->projected as $point) {
            self::assertSame('100.00', $point->revenue);
        }
        self::assertNotNull($result->method);
        self::assertStringContainsString('Weighted moving average', $result->method);
    }

    /** Recency-weighting: a history that's low-then-high must project closer to the recent (high) end than a plain average would. */
    public function test_recent_days_are_weighted_more_heavily_than_older_days(): void
    {
        $revenues = array_merge(array_fill(0, 7, '10.00'), array_fill(0, 7, '110.00'));
        $snapshots = $this->snapshotsWithRevenue($revenues);
        $simpleAverage = (7 * 10.0 + 7 * 110.0) / 14; // 60.00

        $result = $this->forecaster->forecast($snapshots, 30, $this->today);

        self::assertTrue($result->hasEnoughData);
        $projectedAmount = (float) $result->projected[0]->revenue;
        self::assertGreaterThan($simpleAverage, $projectedAmount, 'weighted average should skew toward the more recent, higher values');
    }

    public function test_active_memberships_expiring_without_renewal_reduce_the_projection(): void
    {
        $snapshots = $this->snapshotsWithRevenue(array_fill(0, 14, '100.00'));

        $plan = new MembershipPlan($this->branch, 'Gold', '50.00', 60, []);
        $this->em->persist($plan);
        $this->em->flush();

        // 2 active memberships; 1 expires within the 30-day horizon with auto-renew off.
        $renewing = $this->memberWithMembership($plan, $this->today->modify('-40 days'), $this->today->modify('+40 days'), true);
        $lapsing = $this->memberWithMembership($plan, $this->today->modify('-40 days'), $this->today->modify('+10 days'), false);

        $result = $this->forecaster->forecast($snapshots, 30, $this->today);

        // Hand-computed: activeCount=2, expiringWithoutRenewal=1, riskRatio=0.5,
        // dampening=0.5 => adjustmentFactor = 1 - 0.5*0.5 = 0.75 => 100 * 0.75 = 75.00
        self::assertSame('75.00', $result->projected[0]->revenue);
        self::assertStringContainsString('1 of 2 active member', $result->method);
    }

    private function memberWithMembership(MembershipPlan $plan, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $autoRenew): Membership
    {
        static $n = 0;
        ++$n;
        $user = new User("Member {$n}", "member{$n}@example.com", null, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist($user);
        $profile = new MemberProfile($user);
        $this->em->persist($profile);
        $membership = new Membership($profile, $plan, $start, $end, $autoRenew);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }
}
