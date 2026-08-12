<?php

namespace App\Tests\Functional;

use App\Entity\AttendanceLog;
use App\Entity\Gym;
use App\Entity\Invoice;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Enum\PaymentMethod;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\DailyMetricSnapshotRepository;
use App\Reporting\DailyMetricAggregator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "The single most important test in this phase" (roadmap Phase 11) — a
 * hand-computable, known dataset spanning 3 real calendar days (3/2/1
 * days ago, so DailyMetricAggregator::backfill()'s real "earliest known
 * date → yesterday" loop exercises exactly this data, not a synthetic
 * shortcut that bypasses the backfill orchestration itself).
 *
 * Known dataset:
 *   Day1 (3 days ago): Member A enrolls ($50 plan, paid same day), checks in twice.
 *   Day2 (2 days ago): Member B enrolls ($30 plan, paid same day). Member A checks in once. Member B: 0 check-ins.
 *   Day3 (1 day ago):  Member C enrolls ($50 plan, paid same day) and is cancelled the same day.
 *                      Member A checks in once, Member B checks in once.
 *
 * Hand-computed expected snapshot per day:
 *   Day1: checkins=2, active=1 (A only), new=1 (A), cancelled=0, revenue=50.00
 *   Day2: checkins=1, active=2 (A,B),    new=1 (B), cancelled=0, revenue=30.00
 *   Day3: checkins=2, active=2 (A,B — C excluded, see DailyMetricAggregator's
 *         docblock on why a cancelled membership never counts as "active"
 *         for any day), new=1 (C — countStartedOnDate() doesn't filter by
 *         status, an enrollment is a real historical event regardless of
 *         a same-day cancellation), cancelled=1 (C), revenue=50.00 (C's
 *         invoice, paid same day)
 * at_risk_members_count is 0 on all three days by hand-inspection: every
 * member is brand new (well under the 30-day tenure needed for the
 * frequency-drop signal) and has a same-day check-in (so the "no
 * check-in" signal's threshold — 14 days — can't have been crossed yet).
 */
final class DailyMetricAggregatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DailyMetricAggregator $aggregator;
    private DailyMetricSnapshotRepository $snapshots;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->aggregator = static::getContainer()->get(DailyMetricAggregator::class);
        $this->snapshots = static::getContainer()->get(DailyMetricSnapshotRepository::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE daily_metric_snapshot, invoice, attendance_log, audit_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    private function memberUser(string $name, string $email): MemberProfile
    {
        $user = new User($name, $email, null, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist($user);
        $profile = new MemberProfile($user);
        $this->em->persist($profile);

        return $profile;
    }

    private function enrollAndPay(MemberProfile $member, MembershipPlan $plan, \DateTimeImmutable $day, int $durationDays, User $owner): Membership
    {
        $membership = new Membership($member, $plan, $day, $day->modify("+{$durationDays} days"));
        $this->em->persist($membership);
        $this->em->flush();

        $invoice = new Invoice($membership, $plan->getPrice());
        $this->em->persist($invoice);
        $invoice->markPaid($owner, PaymentMethod::CASH);
        $this->em->flush();

        // markPaid()/issuedAt both stamp "now" — back-date to the day this test says they happened.
        $this->em->getConnection()->executeStatement(
            'UPDATE invoice SET issued_at = ?, paid_at = ? WHERE id = ?',
            [$day->format('Y-m-d H:i:s'), $day->format('Y-m-d H:i:s'), (string) $invoice->getId()],
        );

        return $membership;
    }

    private function checkIn(MemberProfile $member, \DateTimeImmutable $at): void
    {
        $log = new AttendanceLog($member, $at, CheckInMethod::MANUAL);
        $this->em->persist($log);
        $this->em->flush();
    }

    public function test_backfill_produces_exact_hand_computed_snapshots_for_a_known_dataset(): void
    {
        $day1 = (new \DateTimeImmutable('-3 days'))->setTime(0, 0)->modify('+9 hours'); // 09:00, 3 days ago
        $day2 = (new \DateTimeImmutable('-2 days'))->setTime(0, 0)->modify('+9 hours');
        $day3 = (new \DateTimeImmutable('-1 days'))->setTime(0, 0)->modify('+9 hours');

        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $goldPlan = new MembershipPlan($gym, 'Gold', '50.00', 30, []);
        $silverPlan = new MembershipPlan($gym, 'Silver', '30.00', 15, []);
        $this->em->persist($goldPlan);
        $this->em->persist($silverPlan);
        $this->em->flush();

        $memberA = $this->memberUser('Member A', 'a@example.com');
        $memberB = $this->memberUser('Member B', 'b@example.com');
        $memberC = $this->memberUser('Member C', 'c@example.com');

        // Day1: A enrolls + pays, checks in twice.
        $this->enrollAndPay($memberA, $goldPlan, $day1, 30, $owner);
        $this->checkIn($memberA, $day1->modify('+1 hour'));
        $this->checkIn($memberA, $day1->modify('+8 hours'));

        // Day2: B enrolls + pays. A checks in once. B: no check-in yet.
        $this->enrollAndPay($memberB, $silverPlan, $day2, 15, $owner);
        $this->checkIn($memberA, $day2->modify('+1 hour'));

        // Day3: C enrolls + pays, then is cancelled same day. A and B each check in once.
        $membershipC = $this->enrollAndPay($memberC, $goldPlan, $day3, 30, $owner);
        $membershipC->cancel();
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'UPDATE membership SET cancelled_at = ? WHERE id = ?',
            [$day3->format('Y-m-d H:i:s'), (string) $membershipC->getId()],
        );
        $this->checkIn($memberA, $day3->modify('+1 hour'));
        $this->checkIn($memberB, $day3->modify('+2 hours'));

        $backfilledCount = $this->aggregator->backfill($gym);

        self::assertSame(3, $backfilledCount, 'expected exactly 3 days backfilled (3/2/1 days ago)');

        $rows = $this->snapshots->findForDateRange($gym, $day1->setTime(0, 0), $day3->setTime(0, 0));
        self::assertCount(3, $rows);

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->getSnapshotDate()->format('Y-m-d')] = $row;
        }

        $day1Key = $day1->format('Y-m-d');
        $day2Key = $day2->format('Y-m-d');
        $day3Key = $day3->format('Y-m-d');

        // ---- Day1: checkins=2, active=1 (A), new=1 (A), cancelled=0, revenue=50.00
        self::assertSame(2, $byDate[$day1Key]->getCheckinsCount(), 'Day1 checkins');
        self::assertSame(1, $byDate[$day1Key]->getActiveMembersCount(), 'Day1 active members');
        self::assertSame(1, $byDate[$day1Key]->getNewMembersCount(), 'Day1 new members');
        self::assertSame(0, $byDate[$day1Key]->getCancelledMembersCount(), 'Day1 cancelled members');
        self::assertSame('50.00', $byDate[$day1Key]->getRevenue(), 'Day1 revenue');
        self::assertSame(0, $byDate[$day1Key]->getAtRiskMembersCount(), 'Day1 at-risk');

        // ---- Day2: checkins=1, active=2 (A,B), new=1 (B), cancelled=0, revenue=30.00
        self::assertSame(1, $byDate[$day2Key]->getCheckinsCount(), 'Day2 checkins');
        self::assertSame(2, $byDate[$day2Key]->getActiveMembersCount(), 'Day2 active members');
        self::assertSame(1, $byDate[$day2Key]->getNewMembersCount(), 'Day2 new members');
        self::assertSame(0, $byDate[$day2Key]->getCancelledMembersCount(), 'Day2 cancelled members');
        self::assertSame('30.00', $byDate[$day2Key]->getRevenue(), 'Day2 revenue');
        self::assertSame(0, $byDate[$day2Key]->getAtRiskMembersCount(), 'Day2 at-risk');

        // ---- Day3: checkins=2, active=2 (A,B — C excluded, cancelled), new=1 (C), cancelled=1 (C), revenue=50.00
        self::assertSame(2, $byDate[$day3Key]->getCheckinsCount(), 'Day3 checkins');
        self::assertSame(2, $byDate[$day3Key]->getActiveMembersCount(), 'Day3 active members');
        self::assertSame(1, $byDate[$day3Key]->getNewMembersCount(), 'Day3 new members');
        self::assertSame(1, $byDate[$day3Key]->getCancelledMembersCount(), 'Day3 cancelled members');
        self::assertSame('50.00', $byDate[$day3Key]->getRevenue(), 'Day3 revenue');
        self::assertSame(0, $byDate[$day3Key]->getAtRiskMembersCount(), 'Day3 at-risk');
    }

    public function test_aggregate_is_idempotent_recomputing_the_same_day_updates_in_place_not_duplicates(): void
    {
        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $plan = new MembershipPlan($gym, 'Gold', '50.00', 30, []);
        $this->em->persist($plan);
        $this->em->flush();

        $day = (new \DateTimeImmutable('-5 days'))->setTime(9, 0);
        $member = $this->memberUser('Member A', 'a@example.com');
        $this->enrollAndPay($member, $plan, $day, 30, $owner);
        $this->checkIn($member, $day->modify('+1 hour'));

        $first = $this->aggregator->aggregate($gym, $day);
        self::assertSame(1, $first->getCheckinsCount());

        $this->checkIn($member, $day->modify('+2 hours'));
        $second = $this->aggregator->aggregate($gym, $day);

        self::assertSame((string) $first->getId(), (string) $second->getId(), 'recomputing the same day must update the existing row, not create a new one');
        self::assertSame(2, $second->getCheckinsCount());

        $rows = $this->snapshots->findForDateRange($gym, $day->setTime(0, 0), $day->setTime(0, 0));
        self::assertCount(1, $rows);
    }

    public function test_a_day_with_no_activity_produces_an_all_zero_snapshot_not_a_gap(): void
    {
        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $this->em->flush();

        $quietDay = (new \DateTimeImmutable('-2 days'))->setTime(0, 0);
        $snapshot = $this->aggregator->aggregate($gym, $quietDay);

        self::assertSame(0, $snapshot->getCheckinsCount());
        self::assertSame(0, $snapshot->getActiveMembersCount());
        self::assertSame(0, $snapshot->getNewMembersCount());
        self::assertSame(0, $snapshot->getCancelledMembersCount());
        self::assertSame('0.00', $snapshot->getRevenue());
        self::assertSame(0, $snapshot->getAtRiskMembersCount());
    }
}
