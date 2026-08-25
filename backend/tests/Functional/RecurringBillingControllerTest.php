<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * gym-management-billing-v1.md — recurring billing (§5.1/§5.2/§5.4) and
 * its required negative/§8 test cases, against the merged Membership/
 * Invoice/Payment model (see this phase's plan for why there's no
 * separate MemberSubscription entity). Check-in gating (§5.5) is covered
 * in AttendanceControllerTest instead, alongside the rest of the
 * check-in-blocked-reason cases it already owns.
 */
final class RecurringBillingControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE audit_log, payment, invoice, membership, membership_plan, branch_assignment, branch, referral_code, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
        );
    }

    // ---- helpers -------------------------------------------------------

    private function createUser(string $name, string $email, UserRole $role, UserStatus $status = UserStatus::ACTIVE): User
    {
        $user = new User($name, $email, null, $role, $status);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createApprovedMember(string $name, string $email): User
    {
        $user = $this->createUser($name, $email, UserRole::MEMBER);
        $this->em->persist(new MemberProfile($user));
        $this->em->flush();

        return $user;
    }

    private function accessTokenFor(User $user): string
    {
        return static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);
    }

    private function request(string $method, string $uri, User $actingAs, array $data = []): array
    {
        $this->client->request(
            $method,
            '/api' . $uri,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTPS' => 'on',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->accessTokenFor($actingAs),
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function createPlan(User $owner, string $price = '50.00', ?string $branchId = null): array
    {
        $data = ['name' => 'Standard', 'price' => $price, 'durationDays' => 30, 'features' => []];
        if ($branchId !== null) {
            $data['branchId'] = $branchId;
        }

        return $this->request('POST', '/membership-plans', $owner, $data);
    }

    /** @return array the enrolled Membership's serialized body (includes 'id', 'billingAnchorDay', 'nextBillingDate') */
    private function enroll(User $owner, string $memberUserId, string $planId): array
    {
        return $this->request('POST', '/memberships', $owner, ['memberUserId' => $memberUserId, 'planId' => $planId])['body'];
    }

    private function invoiceIdFor(string $membershipId): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT id FROM invoice WHERE membership_id = ? ORDER BY period_start DESC NULLS LAST, issued_at DESC LIMIT 1',
            [$membershipId],
        );
    }

    private function runGenerateInvoicesCommand(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:billing:generate-invoices');
        (new CommandTester($command))->execute([]);
    }

    /**
     * Simulates one billing cycle having elapsed: the original enrollment
     * invoice's period is pushed back a month (so it stops colliding with
     * "today" on the (membership, period_start) unique constraint) and
     * the membership's next cycle is forced due today.
     */
    private function forceDueToday(string $membershipId): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE invoice SET period_start = period_start - interval '1 month', period_end = period_end - interval '1 month', due_date = due_date - interval '1 month' WHERE membership_id = ? AND period_start IS NOT NULL",
            [$membershipId],
        );
        $this->em->getConnection()->executeStatement(
            'UPDATE membership SET next_billing_date = CURRENT_DATE WHERE id = ?',
            [$membershipId],
        );
    }

    // ---- §5.2 payment recording ---------------------------------------

    public function test_payment_amount_mismatch_is_rejected_422_invoice_stays_pending(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->invoiceIdFor($membership['id']);

        $result = $this->request('POST', "/invoices/{$invoiceId}/payments", $owner, [
            'amount' => '10.00', 'method' => 'cash',
        ]);

        self::assertSame(422, $result['status']);
        self::assertSame('amount_mismatch', $result['body']['error']);
        self::assertStringContainsString('Partial payments are not supported', $result['body']['message']);
        $status = $this->em->getConnection()->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoiceId]);
        self::assertSame('pending', $status);
    }

    public function test_payment_on_an_already_paid_invoice_is_rejected_409(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, '50.00');
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->invoiceIdFor($membership['id']);
        $this->request('POST', "/invoices/{$invoiceId}/payments", $owner, ['amount' => '50.00', 'method' => 'cash']);

        $second = $this->request('POST', "/invoices/{$invoiceId}/payments", $owner, ['amount' => '50.00', 'method' => 'cash']);

        self::assertSame(409, $second['status']);
    }

    /** Required negative case: "Staff submits resetBillingCycle: true → 403, no state change." */
    public function test_staff_resetBillingCycle_true_is_rejected_403_no_state_change(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->request('POST', "/branches/{$branchId}/assign", $owner, ['userId' => (string) $staff->getId()]);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, '50.00', $branchId);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->invoiceIdFor($membership['id']);
        $before = $this->em->getConnection()->fetchAssociative('SELECT i.status AS invoice_status, m.billing_anchor_day, m.next_billing_date FROM invoice i JOIN membership m ON m.id = i.membership_id WHERE i.id = ?', [$invoiceId]);

        $result = $this->request('POST', "/invoices/{$invoiceId}/payments", $staff, [
            'amount' => '50.00', 'method' => 'cash', 'resetBillingCycle' => true,
        ]);

        self::assertSame(403, $result['status']);
        $after = $this->em->getConnection()->fetchAssociative('SELECT i.status AS invoice_status, m.billing_anchor_day, m.next_billing_date FROM invoice i JOIN membership m ON m.id = i.membership_id WHERE i.id = ?', [$invoiceId]);
        self::assertSame($before, $after, 'the whole request must fail — no silent coercion to resetBillingCycle: false, no partial mutation');
    }

    public function test_staff_assigned_to_the_branch_can_record_a_payment(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $branchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->request('POST', "/branches/{$branchId}/assign", $owner, ['userId' => (string) $staff->getId()]);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, '50.00', $branchId);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->invoiceIdFor($membership['id']);

        $result = $this->request('POST', "/invoices/{$invoiceId}/payments", $staff, ['amount' => '50.00', 'method' => 'card']);

        self::assertSame(201, $result['status']);
        self::assertSame('paid', $result['body']['invoice']['status']);
    }

    /** Required negative case: "Staff acting outside their assigned branch on payment... → 403." */
    public function test_staff_not_assigned_to_the_branch_cannot_record_a_payment_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $otherBranch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->request('POST', "/branches/{$primaryBranchId}/assign", $owner, ['userId' => (string) $staff->getId()]);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, '50.00', $otherBranch['id']);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->invoiceIdFor($membership['id']);

        $result = $this->request('POST', "/invoices/{$invoiceId}/payments", $staff, ['amount' => '50.00', 'method' => 'cash']);

        self::assertSame(403, $result['status']);
    }

    // ---- §5.4 suspend / reactivate --------------------------------------

    public function test_owner_can_suspend_and_reactivate_a_membership(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $suspend = $this->request('PATCH', "/memberships/{$membership['id']}/suspend", $owner);
        self::assertSame(200, $suspend['status']);
        self::assertSame('suspended', $suspend['body']['status']);

        $reactivate = $this->request('PATCH', "/memberships/{$membership['id']}/reactivate", $owner);
        self::assertSame(200, $reactivate['status']);
        self::assertSame('active', $reactivate['body']['status']);
    }

    /** Required negative case: "...suspend/reactivate → 403" for out-of-branch Staff. */
    public function test_staff_not_assigned_to_the_branch_cannot_suspend_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $primaryBranchId = $this->request('GET', '/branches', $owner)['body']['branches'][0]['id'];
        $otherBranch = $this->request('POST', '/branches', $owner, ['name' => 'Downtown', 'address' => '1 Main St'])['body'];
        $staff = $this->createUser('Sam Staff', 'staff@example.com', UserRole::STAFF);
        $this->request('POST', "/branches/{$primaryBranchId}/assign", $owner, ['userId' => (string) $staff->getId()]);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, '50.00', $otherBranch['id']);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $result = $this->request('PATCH', "/memberships/{$membership['id']}/suspend", $staff);

        self::assertSame(403, $result['status']);
    }

    public function test_suspending_a_membership_does_not_touch_existing_invoices(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->invoiceIdFor($membership['id']);

        $this->request('PATCH', "/memberships/{$membership['id']}/suspend", $owner);

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM invoice WHERE id = ?', [$invoiceId]);
        self::assertSame('pending', $status, 'existing PENDING/ABSENT invoices are left as-is on suspension — not waived, not auto-paid');
    }

    // ---- §5.1 invoice generation -----------------------------------------

    public function test_running_generate_invoices_command_twice_same_day_creates_no_duplicate_invoices(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $this->forceDueToday($membership['id']);

        $this->runGenerateInvoicesCommand();
        $countAfterFirst = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE membership_id = ?', [$membership['id']]);

        $this->runGenerateInvoicesCommand();
        $countAfterSecond = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE membership_id = ?', [$membership['id']]);

        self::assertSame(2, $countAfterFirst, 'the enrollment invoice plus exactly one newly generated cycle invoice');
        self::assertSame($countAfterFirst, $countAfterSecond, 're-running the command the same day must not create duplicate invoices');
    }

    public function test_generation_marks_the_prior_pending_invoice_absent_before_creating_the_new_one(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $firstInvoiceId = $this->invoiceIdFor($membership['id']);
        $this->forceDueToday($membership['id']);

        $this->runGenerateInvoicesCommand();

        $firstStatus = $this->em->getConnection()->fetchOne('SELECT status FROM invoice WHERE id = ?', [$firstInvoiceId]);
        self::assertSame('absent', $firstStatus);
        $newCount = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM invoice WHERE membership_id = ? AND status = 'pending'",
            [$membership['id']],
        );
        self::assertSame(1, $newCount, 'exactly one new PENDING invoice for the new period');
    }

    /**
     * Required case: "Reactivating a subscription suspended for 3 months
     * → exactly one invoice generated going forward, no backfill of the
     * missed 3 cycles."
     */
    public function test_reactivating_after_3_months_suspended_generates_exactly_one_invoice_no_backfill(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        // Push the enrollment invoice's period back so today's post-
        // reactivation cycle doesn't collide with it on the (membership,
        // period_start) unique constraint — same reasoning as forceDueToday().
        $this->em->getConnection()->executeStatement(
            "UPDATE invoice SET period_start = period_start - interval '4 months', period_end = period_end - interval '4 months', due_date = due_date - interval '4 months' WHERE membership_id = ? AND period_start IS NOT NULL",
            [$membership['id']],
        );
        $this->request('PATCH', "/memberships/{$membership['id']}/suspend", $owner);
        // Simulate 3 months of being suspended passing.
        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET next_billing_date = next_billing_date - interval '3 months' WHERE id = ?",
            [$membership['id']],
        );

        $this->request('PATCH', "/memberships/{$membership['id']}/reactivate", $owner);
        $this->runGenerateInvoicesCommand();
        $this->runGenerateInvoicesCommand(); // idempotency, same as any other cycle

        $totalInvoices = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice WHERE membership_id = ?', [$membership['id']]);
        self::assertSame(2, $totalInvoices, 'the original enrollment invoice, plus exactly one new one for the current period — none for the 3 missed months');
    }

    /**
     * Required case: "Payment on an ABSENT invoice with resetBillingCycle:
     * true → marked PAID, anchor day and nextBillingDate updated, no
     * attempt to reopen the invoice as PENDING first."
     */
    public function test_late_payment_on_absent_invoice_with_reset_billing_cycle_marks_paid_and_updates_anchor(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner, '50.00');
        $membership = $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $firstInvoiceId = $this->invoiceIdFor($membership['id']);
        $this->forceDueToday($membership['id']);
        $this->runGenerateInvoicesCommand(); // first invoice now ABSENT

        $status = $this->em->getConnection()->fetchOne('SELECT status FROM invoice WHERE id = ?', [$firstInvoiceId]);
        self::assertSame('absent', $status);

        // Force a distinct prior anchor day (the 1st) so the reset's
        // effect is unambiguous regardless of what day-of-month the test
        // happens to run on.
        $this->em->getConnection()->executeStatement(
            "UPDATE membership SET billing_anchor_day = 1 WHERE id = ?",
            [$membership['id']],
        );

        $result = $this->request('POST', "/invoices/{$firstInvoiceId}/payments", $owner, [
            'amount' => '50.00', 'method' => 'cash', 'resetBillingCycle' => true,
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame('paid', $result['body']['invoice']['status'], 'no reopening to PENDING first — goes straight to PAID');

        $today = new \DateTimeImmutable('today');
        $membershipRow = $this->em->getConnection()->fetchAssociative(
            'SELECT billing_anchor_day, next_billing_date FROM membership WHERE id = ?',
            [$membership['id']],
        );
        self::assertSame((int) $today->format('j'), (int) $membershipRow['billing_anchor_day'], 'billingAnchorDay resets to the day of the late payment, no longer the 1st');
        $nextBillingDate = new \DateTimeImmutable($membershipRow['next_billing_date']);
        self::assertSame((int) $today->format('j'), (int) $nextBillingDate->format('j'), 'nextBillingDate lands on the new anchor day');
        self::assertGreaterThan($today, $nextBillingDate);

        // The reset is permanent — the *following* cycle also lands on the new anchor day, not just the immediate next one.
        $this->em->getConnection()->executeStatement('UPDATE membership SET next_billing_date = CURRENT_DATE WHERE id = ?', [$membership['id']]);
        $this->runGenerateInvoicesCommand();
        $afterAnchor = (int) $this->em->getConnection()->fetchOne('SELECT billing_anchor_day FROM membership WHERE id = ?', [$membership['id']]);
        self::assertSame((int) $today->format('j'), $afterAnchor, 'the anchor day change persists across multiple cycles, not just a one-time nudge');
    }
}
