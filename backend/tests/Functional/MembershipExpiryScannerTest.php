<?php

namespace App\Tests\Functional;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Entity\Membership;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Event\MembershipExpiredEvent;
use App\Event\MembershipExpiringEvent;
use App\Membership\MembershipExpiryScanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §8.3's daily scan, tested directly against the scanner
 * service rather than through a real cron tick or Messenger worker — the
 * schedule wiring itself is confirmed separately via `debug:scheduler`
 * (shows the "membership" schedule with next-run time). Notification
 * *delivery* was already tested in earlier phases' event wiring; this
 * only checks the scan fires the right event for the right memberships.
 */
final class MembershipExpiryScannerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE membership, membership_plan, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    private function memberWithMembershipEndingIn(Gym $gym, MembershipPlan $plan, string $email, int $daysFromToday): Membership
    {
        static $counter = 0;
        ++$counter;

        $user = new User("Member {$counter}", $email, null, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist($user);
        $profile = new MemberProfile($user);
        $this->em->persist($profile);

        $membership = new Membership(
            $profile,
            $plan,
            new \DateTimeImmutable('-29 days'),
            new \DateTimeImmutable(($daysFromToday >= 0 ? '+' : '') . $daysFromToday . ' days'),
        );
        $this->em->persist($membership);
        $this->em->flush();

        return $membership;
    }

    public function test_scan_fires_expiring_event_at_7_3_and_1_day_thresholds_only(): void
    {
        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($branch);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $this->em->persist($plan);
        $this->em->flush();

        $sevenDays = $this->memberWithMembershipEndingIn($gym, $plan, 'seven@example.com', 7);
        $threeDays = $this->memberWithMembershipEndingIn($gym, $plan, 'three@example.com', 3);
        $oneDay = $this->memberWithMembershipEndingIn($gym, $plan, 'one@example.com', 1);
        // Control cases that must NOT fire a reminder.
        $this->memberWithMembershipEndingIn($gym, $plan, 'ten@example.com', 10);
        $this->memberWithMembershipEndingIn($gym, $plan, 'two@example.com', 2);

        $expiringEvents = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            MembershipExpiringEvent::NAME,
            function (MembershipExpiringEvent $event) use (&$expiringEvents) { $expiringEvents[] = $event; },
        );

        $result = static::getContainer()->get(MembershipExpiryScanner::class)->scan();

        self::assertSame(3, $result['expiring']);
        self::assertCount(3, $expiringEvents);

        $matchedByDays = [];
        foreach ($expiringEvents as $event) {
            $matchedByDays[$event->getDaysUntilExpiry()] = $event->getMembership()->getId();
        }
        self::assertEqualsCanonicalizing([7, 3, 1], array_keys($matchedByDays));
        self::assertSame((string) $sevenDays->getId(), (string) $matchedByDays[7]);
        self::assertSame((string) $threeDays->getId(), (string) $matchedByDays[3]);
        self::assertSame((string) $oneDay->getId(), (string) $matchedByDays[1]);
    }

    public function test_scan_transitions_past_due_active_memberships_to_expired_and_emits_event(): void
    {
        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($branch);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $this->em->persist($plan);
        $this->em->flush();

        $pastDue = $this->memberWithMembershipEndingIn($gym, $plan, 'pastdue@example.com', -5);
        $stillActive = $this->memberWithMembershipEndingIn($gym, $plan, 'stillactive@example.com', 20);

        $expiredEvents = [];
        static::getContainer()->get(EventDispatcherInterface::class)->addListener(
            MembershipExpiredEvent::NAME,
            function (MembershipExpiredEvent $event) use (&$expiredEvents) { $expiredEvents[] = $event; },
        );

        $result = static::getContainer()->get(MembershipExpiryScanner::class)->scan();

        self::assertSame(1, $result['expired']);
        self::assertCount(1, $expiredEvents);
        self::assertSame((string) $pastDue->getId(), (string) $expiredEvents[0]->getMembership()->getId());

        $pastDueStatus = $this->em->getConnection()->fetchOne('SELECT status FROM membership WHERE id = ?', [(string) $pastDue->getId()]);
        self::assertSame('expired', $pastDueStatus);

        $stillActiveStatus = $this->em->getConnection()->fetchOne('SELECT status FROM membership WHERE id = ?', [(string) $stillActive->getId()]);
        self::assertSame('active', $stillActiveStatus);
    }

    public function test_scan_does_not_touch_paused_memberships_even_if_past_end_date(): void
    {
        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym('Test Gym', '1 Main St', $owner);
        $this->em->persist($gym);
        $branch = new Branch($gym, 'Main', '1 Main St', isPrimary: true);
        $this->em->persist($branch);
        $plan = new MembershipPlan($branch, 'Standard', '49.99', 30, []);
        $this->em->persist($plan);
        $this->em->flush();

        $paused = $this->memberWithMembershipEndingIn($gym, $plan, 'paused@example.com', -5);
        $paused->pause();
        $this->em->flush();

        $result = static::getContainer()->get(MembershipExpiryScanner::class)->scan();

        self::assertSame(0, $result['expired']);
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM membership WHERE id = ?', [(string) $paused->getId()]);
        self::assertSame('paused', $status);
    }
}
