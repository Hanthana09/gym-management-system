<?php

namespace App\Tests\Security\Voter;

use App\Entity\Gym;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\ProductVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * ProductVoter is copied from architecture doc §9.1 and covers both
 * Product and ProductCategory. Named after functional requirements
 * §15.2's Given/When/Then criteria where practical.
 */
final class ProductVoterTest extends TestCase
{
    private ProductVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new ProductVoter();
    }

    private function user(UserRole $role): User
    {
        ++self::$counter;

        return new User("User " . self::$counter, "user" . self::$counter . '@example.com', '+1555000' . self::$counter, $role, UserStatus::ACTIVE);
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function product(): array
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $category = new ProductCategory($gym, 'Supplements');
        $product = new Product($gym, $category, 'Whey Protein 1kg', '25.00', 'SKU-1');

        return [$product, $category];
    }

    // ---- Owner: full manage ----------------------------------------------

    public function test_owner_can_manage_and_view_a_product(): void
    {
        [$product] = $this->product();
        $owner = $this->user(UserRole::OWNER);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $product, [ProductVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $product, [ProductVoter::VIEW]));
    }

    public function test_owner_can_manage_and_view_a_product_category(): void
    {
        [, $category] = $this->product();
        $owner = $this->user(UserRole::OWNER);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $category, [ProductVoter::MANAGE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $category, [ProductVoter::VIEW]));
    }

    // ---- Staff: read-only -------------------------------------------------

    /** functional requirements §15.2: "I am Staff, when I view the product catalog, then I can see it." */
    public function test_staff_can_view_the_product_catalog(): void
    {
        [$product] = $this->product();
        $staff = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($staff), $product, [ProductVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    /** functional requirements §15.2: Staff has "no create/edit/deactivate actions available — attempting one via a manipulated request is rejected." */
    public function test_given_staff_attempts_to_manage_a_product_via_a_manipulated_request_then_403(): void
    {
        [$product] = $this->product();
        $staff = $this->user(UserRole::STAFF);

        $result = $this->voter->vote($this->tokenFor($staff), $product, [ProductVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- Coach/Member: denied entirely ------------------------------------

    /** functional requirements §15.2: "I am a Coach or Member... any route... permission error." */
    public function test_given_a_coach_when_they_attempt_to_access_the_product_catalog_then_403(): void
    {
        [$product, $category] = $this->product();
        $coach = $this->user(UserRole::COACH);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $product, [ProductVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $category, [ProductVoter::VIEW]));
    }

    public function test_given_a_member_when_they_attempt_to_access_the_product_catalog_then_403(): void
    {
        [$product, $category] = $this->product();
        $member = $this->user(UserRole::MEMBER);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $product, [ProductVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $category, [ProductVoter::VIEW]));
    }
}
