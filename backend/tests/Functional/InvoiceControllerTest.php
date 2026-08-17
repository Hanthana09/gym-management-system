<?php

namespace App\Tests\Functional;

use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\AuditLogRepository;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * roadmap Phase 10 / functional requirements §8.1–8.2. Covers the full
 * enroll → invoice pending → Owner marks paid → membership stays active
 * → Member notified → audit log loop, InvoiceVoter's MARK_PAID rejection
 * of a Member at the API level (not just hidden in the UI), and the
 * §8.1 "no automatic assumption of payment" criterion.
 */
final class InvoiceControllerTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTPS' => 'on']);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE audit_log, invoice, membership, membership_plan, referral_code, coach_profile, member_profile, invitation, gym, otp_code, refresh_token, "user" CASCADE',
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
            ],
            content: $method === 'GET' ? null : json_encode($data, \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent() !== '' ? json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR) : null,
        ];
    }

    private function createPlan(User $owner, string $name = 'Gold', string $price = '79.99', int $durationDays = 30): array
    {
        return $this->request('POST', '/membership-plans', $owner, [
            'name' => $name,
            'price' => $price,
            'durationDays' => $durationDays,
            'features' => [],
        ]);
    }

    private function enroll(User $owner, string $memberUserId, string $planId): array
    {
        return $this->request('POST', '/memberships', $owner, ['memberUserId' => $memberUserId, 'planId' => $planId]);
    }

    private function notificationsFor(User $user): array
    {
        return $this->request('GET', '/notifications', $user)['body']['notifications'];
    }

    // ---- §8.1 enrollment invoice -----------------------------------------

    public function test_given_a_member_enrolls_when_enrollment_completes_then_a_pending_invoice_is_created(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);

        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $ownerView = $this->request('GET', '/invoices', $owner);
        self::assertSame(200, $ownerView['status']);
        self::assertCount(1, $ownerView['body']['invoices']);
        self::assertSame('pending', $ownerView['body']['invoices'][0]['status']);
        self::assertSame('79.99', $ownerView['body']['invoices'][0]['amount']);
        self::assertNull($ownerView['body']['invoices'][0]['paymentMethod']);
        self::assertNull($ownerView['body']['invoices'][0]['paidAt']);
    }

    public function test_the_member_can_see_the_amount_they_owe(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $memberView = $this->request('GET', '/members/me/invoices', $member);

        self::assertSame(200, $memberView['status']);
        self::assertCount(1, $memberView['body']['invoices']);
        self::assertSame('79.99', $memberView['body']['invoices'][0]['amount']);
        self::assertSame('pending', $memberView['body']['invoices'][0]['status']);
    }

    public function test_a_member_never_sees_another_members_invoices(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $memberA = $this->createApprovedMember('Alice', 'alice@example.com');
        $memberB = $this->createApprovedMember('Bob', 'bob@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $memberA->getId(), $plan['body']['id']);

        $bView = $this->request('GET', '/members/me/invoices', $memberB);

        self::assertSame(200, $bView['status']);
        self::assertCount(0, $bView['body']['invoices']);
    }

    public function test_non_owner_cannot_list_all_invoices_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $coachAttempt = $this->request('GET', '/invoices', $coach);
        $memberAttempt = $this->request('GET', '/invoices', $member);

        self::assertSame(403, $coachAttempt['status']);
        self::assertSame(403, $memberAttempt['status']);
    }

    /**
     * functional requirements §8.1: "it remains visible to the Owner as
     * outstanding — no automatic assumption of payment or automatic
     * membership activation without an explicit Owner action." Simulates
     * an invoice that's been pending a long time and confirms nothing
     * about its state changes just from that passage of time.
     */
    public function test_an_invoice_pending_for_an_extended_period_stays_outstanding_no_automatic_activation(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $this->em->getConnection()->executeStatement(
            "UPDATE invoice SET issued_at = current_timestamp - interval '90 day'",
        );

        $ownerView = $this->request('GET', '/invoices', $owner);

        self::assertSame('pending', $ownerView['body']['invoices'][0]['status']);
        self::assertNull($ownerView['body']['invoices'][0]['paidAt']);
        self::assertNull($ownerView['body']['invoices'][0]['recordedByName']);
    }

    // ---- §8.1 the full mark-paid loop --------------------------------------

    /** The full loop the roadmap's Definition of Done names explicitly. */
    public function test_the_full_loop_enroll_to_marked_paid_to_membership_active_to_member_notified(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];

        $result = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'cash']);

        self::assertSame(200, $result['status']);
        self::assertSame('paid', $result['body']['status']);
        self::assertSame('cash', $result['body']['paymentMethod']);
        self::assertSame('Olivia Owner', $result['body']['recordedByName']);
        self::assertNotNull($result['body']['paidAt']);

        // membership becomes/stays active (already active on enroll in
        // this codebase's Phase 4 model — see BillingService's docblock)
        $membership = $this->request('GET', '/members/me/membership', $member);
        self::assertSame('active', $membership['body']['membership']['status']);

        // the Member is notified
        $notifications = $this->notificationsFor($member);
        self::assertCount(1, $notifications);
        self::assertSame('billing', $notifications[0]['type']);
        self::assertStringContainsString('Payment received', $notifications[0]['title']);
    }

    /** Explicit API-level rejection — not just hidden in the UI. */
    public function test_a_member_attempting_to_mark_their_own_invoice_paid_is_rejected_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/members/me/invoices', $member)['body']['invoices'][0]['id'];

        $result = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $member, ['paymentMethod' => 'cash']);

        self::assertSame(403, $result['status']);
        $stillPending = $this->request('GET', '/members/me/invoices', $member);
        self::assertSame('pending', $stillPending['body']['invoices'][0]['status']);
    }

    public function test_a_coach_cannot_mark_an_invoice_paid_403(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $coach = $this->createUser('Carlos Coach', 'coach@example.com', UserRole::COACH);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];

        $result = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $coach, ['paymentMethod' => 'cash']);

        self::assertSame(403, $result['status']);
    }

    public function test_mark_paid_rejects_an_invalid_payment_method(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];

        $result = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'venmo']);

        self::assertSame(400, $result['status']);
    }

    /** `gateway`/`referral_credit` are real enum values but never Owner-selectable via this endpoint. */
    public function test_mark_paid_rejects_gateway_and_referral_credit_as_client_supplied_methods(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];

        $gatewayAttempt = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'gateway']);
        $creditAttempt = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'referral_credit']);

        self::assertSame(400, $gatewayAttempt['status']);
        self::assertSame(400, $creditAttempt['status']);
    }

    public function test_marking_an_already_paid_invoice_paid_again_is_rejected_409(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];
        $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'cash']);

        $second = $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'bank_transfer']);

        self::assertSame(409, $second['status']);
    }

    // ---- audit log ---------------------------------------------------------

    /** "Confirm the audit log entry is created correctly... check the entry itself, not just that the invoice status changed." */
    public function test_marking_an_invoice_paid_creates_a_correct_audit_log_entry(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);
        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];

        $this->request('PATCH', "/invoices/{$invoiceId}/mark-paid", $owner, ['paymentMethod' => 'bank_transfer']);

        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('Invoice', \Symfony\Component\Uid\Uuid::fromString($invoiceId));

        self::assertCount(1, $entries);
        self::assertSame((string) $owner->getId(), (string) $entries[0]->getActor()->getId());
        self::assertSame('invoice.marked_paid', $entries[0]->getAction());
        self::assertSame('Invoice', $entries[0]->getEntityType());
        self::assertSame($invoiceId, (string) $entries[0]->getEntityId());
        self::assertSame('bank_transfer', $entries[0]->getMetadata()['paymentMethod']);
        self::assertSame('79.99', $entries[0]->getMetadata()['amount']);
    }

    // ---- referral credit (Phase 9.2 applied to real billing) --------------

    public function test_given_owner_has_a_referral_credit_when_a_member_enrolls_then_the_invoice_is_automatically_covered(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $code = $this->request('GET', '/referral-code', $owner)['body']['code'];
        $this->request('POST', '/referral-code/redeem', $owner, ['code' => $code]);

        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $invoice = $this->request('GET', '/invoices', $owner)['body']['invoices'][0];
        self::assertSame('paid', $invoice['status']);
        self::assertSame('referral_credit', $invoice['paymentMethod']);
        self::assertSame('Olivia Owner', $invoice['recordedByName']);
        self::assertNotNull($invoice['paidAt']);

        // the credit was consumed — a second enrollment pays normally
        $memberTwo = $this->createApprovedMember('Bob', 'bob@example.com');
        $this->enroll($owner, (string) $memberTwo->getId(), $plan['body']['id']);
        $invoices = $this->request('GET', '/invoices', $owner)['body']['invoices'];
        $pending = array_values(array_filter($invoices, fn (array $i) => $i['status'] === 'pending'));
        self::assertCount(1, $pending);
    }

    public function test_the_referral_credit_auto_apply_is_itself_audit_logged(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);
        $code = $this->request('GET', '/referral-code', $owner)['body']['code'];
        $this->request('POST', '/referral-code/redeem', $owner, ['code' => $code]);

        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $invoiceId = $this->request('GET', '/invoices', $owner)['body']['invoices'][0]['id'];
        $entries = static::getContainer()->get(AuditLogRepository::class)
            ->findForEntity('Invoice', \Symfony\Component\Uid\Uuid::fromString($invoiceId));
        self::assertCount(1, $entries);
        self::assertSame('referral_credit', $entries[0]->getMetadata()['paymentMethod']);
    }

    public function test_without_a_referral_credit_enrollment_creates_a_normal_pending_invoice(): void
    {
        $owner = $this->createUser('Olivia Owner', 'owner@example.com', UserRole::OWNER);
        $member = $this->createApprovedMember('Mia Member', 'mia@example.com');
        $plan = $this->createPlan($owner);

        $this->enroll($owner, (string) $member->getId(), $plan['body']['id']);

        $invoice = $this->request('GET', '/invoices', $owner)['body']['invoices'][0];
        self::assertSame('pending', $invoice['status']);
    }
}
