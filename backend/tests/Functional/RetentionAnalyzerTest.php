<?php

namespace App\Tests\Functional;

use App\Entity\AttendanceLog;
use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\CheckInMethod;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Reporting\RetentionAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * architecture doc §6.8 / functional requirements §10.4: every signal is
 * a plain, named threshold, and every flag carries a specific reason
 * string — the tests below assert on the actual reason text, not just
 * "was flagged," since a bare true/false would let the requirement's own
 * point (an unexplained risk score isn't actionable) go untested.
 */
final class RetentionAnalyzerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RetentionAnalyzer $retention;
    private Gym $gym;
    private Branch $branch;
    private MembershipPlan $plan;
    private User $owner;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->retention = static::getContainer()->get(RetentionAnalyzer::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE attendance_log, membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );

        $this->owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($this->owner);
        $this->gym = new Gym('Test Gym', '1 Main St', $this->owner);
        $this->em->persist($this->gym);
        $this->branch = new Branch($this->gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($this->branch);
        $this->plan = new MembershipPlan($this->branch, 'Gold', '50.00', 60, []);
        $this->em->persist($this->plan);
        $this->em->flush();

        $this->today = new \DateTimeImmutable('today');
    }

    private function member(string $email): MemberProfile
    {
        static $n = 0;
        ++$n;
        $user = new User("Member {$n}", $email, null, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist($user);
        $profile = new MemberProfile($user);
        $this->em->persist($profile);

        return $profile;
    }

    private function membership(MemberProfile $member, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $autoRenew = false): Membership
    {
        $membership = new Membership($member, $this->plan, $start, $end, $autoRenew);
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    private function checkIn(MemberProfile $member, \DateTimeImmutable $at): void
    {
        $this->em->persist(new AttendanceLog($member, $this->branch, $at, CheckInMethod::MANUAL));
        $this->em->flush();
    }

    public function test_a_member_with_normal_recent_activity_is_not_flagged(): void
    {
        $member = $this->member('normal@example.com');
        $membership = $this->membership($member, $this->today->modify('-40 days'), $this->today->modify('+20 days'), true);
        $this->checkIn($member, $this->today->modify('-1 days'));
        $this->checkIn($member, $this->today->modify('-4 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertSame([], $reasons);
    }

    public function test_a_member_with_no_recent_checkin_is_flagged_with_the_exact_day_count(): void
    {
        $member = $this->member('quiet@example.com');
        $membership = $this->membership($member, $this->today->modify('-40 days'), $this->today->modify('+20 days'), true);
        $this->checkIn($member, $this->today->modify('-16 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertCount(1, $reasons);
        self::assertSame('No check-in in 16 days', $reasons[0]);
    }

    public function test_a_member_with_a_recent_checkin_just_under_the_threshold_is_not_flagged_by_that_signal(): void
    {
        $member = $this->member('borderline@example.com');
        $membership = $this->membership($member, $this->today->modify('-40 days'), $this->today->modify('+20 days'), true);
        $this->checkIn($member, $this->today->modify('-13 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertSame([], $reasons);
    }

    public function test_a_membership_expiring_soon_without_auto_renew_is_flagged(): void
    {
        $member = $this->member('expiring@example.com');
        $membership = $this->membership($member, $this->today->modify('-25 days'), $this->today->modify('+5 days'), false);
        $this->checkIn($member, $this->today->modify('-1 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertCount(1, $reasons);
        self::assertSame('Membership expires in 5 days with auto-renew off', $reasons[0]);
    }

    public function test_a_membership_expiring_soon_with_auto_renew_on_is_not_flagged_by_that_signal(): void
    {
        $member = $this->member('autorenews@example.com');
        $membership = $this->membership($member, $this->today->modify('-25 days'), $this->today->modify('+5 days'), true);
        $this->checkIn($member, $this->today->modify('-1 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertSame([], $reasons);
    }

    public function test_a_long_tenured_member_with_a_sharp_frequency_drop_is_flagged(): void
    {
        $member = $this->member('dropoff@example.com');
        $membership = $this->membership($member, $this->today->modify('-90 days'), $this->today->modify('+30 days'), true);
        // ~1 check-in every 2 days for the first 60 of their 90-day tenure (30 check-ins), then nothing for the last 30 days.
        for ($daysAgo = 90; $daysAgo > 30; $daysAgo -= 2) {
            $this->checkIn($member, $this->today->modify("-{$daysAgo} days"));
        }
        // One check-in 20 days ago so the "no check-in" signal doesn't also fire and muddy this test.
        $this->checkIn($member, $this->today->modify('-13 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertCount(1, $reasons);
        self::assertStringContainsString('Check-ins dropped to', $reasons[0]);
        self::assertStringContainsString('14 days', $reasons[0]);
    }

    public function test_a_new_member_with_sparse_activity_is_not_flagged_by_the_frequency_signal_yet(): void
    {
        $member = $this->member('new@example.com');
        // Only 10 days into their membership — below the 30-day minimum tenure for the frequency signal.
        $membership = $this->membership($member, $this->today->modify('-10 days'), $this->today->modify('+50 days'), true);
        $this->checkIn($member, $this->today->modify('-9 days'));

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertSame([], $reasons);
    }

    public function test_a_member_who_never_checked_in_since_joining_long_ago_is_flagged(): void
    {
        $member = $this->member('ghost@example.com');
        $membership = $this->membership($member, $this->today->modify('-20 days'), $this->today->modify('+40 days'), true);

        $reasons = $this->retention->evaluate($membership, $this->today);

        self::assertCount(1, $reasons);
        self::assertSame('No check-ins recorded since joining 20 days ago', $reasons[0]);
    }

    public function test_at_risk_members_list_includes_only_flagged_members_and_excludes_cancelled(): void
    {
        $normal = $this->member('normal@example.com');
        $this->membership($normal, $this->today->modify('-40 days'), $this->today->modify('+20 days'), true);
        $this->checkIn($normal, $this->today->modify('-1 days'));

        $atRisk = $this->member('atrisk@example.com');
        $this->membership($atRisk, $this->today->modify('-40 days'), $this->today->modify('+20 days'), true);
        $this->checkIn($atRisk, $this->today->modify('-20 days'));

        $cancelled = $this->member('cancelled@example.com');
        $cancelledMembership = $this->membership($cancelled, $this->today->modify('-40 days'), $this->today->modify('+20 days'), true);
        $cancelledMembership->cancel();
        $this->em->flush();

        $results = $this->retention->atRiskMembers($this->today);

        self::assertCount(1, $results);
        self::assertSame('atrisk@example.com', $results[0]['member']->getUser()->getEmail());
        self::assertNotEmpty($results[0]['reasons']);
    }
}
