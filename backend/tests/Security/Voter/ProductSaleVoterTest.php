<?php

namespace App\Tests\Security\Voter;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\Gym;
use App\Entity\Product;
use App\Entity\ProductCategory;
use App\Entity\ProductSale;
use App\Entity\User;
use App\Enum\RetailPaymentMethod;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Security\Voter\ProductSaleVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * CLAUDE.md: every Voter needs at least a passing case and a 403 case.
 * ProductSaleVoter is copied from architecture doc §9.1, same shape as
 * ExpenseVoter. Named after functional requirements §15.3's
 * Given/When/Then criteria where practical.
 */
final class ProductSaleVoterTest extends TestCase
{
    private ProductSaleVoter $voter;
    private static int $counter = 0;

    protected function setUp(): void
    {
        $this->voter = new ProductSaleVoter();
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

    private function saleAt(Branch $branch, ?User $soldBy = null): ProductSale
    {
        $soldBy ??= $this->user(UserRole::OWNER);
        $category = new ProductCategory($branch->getGym(), 'Apparel');
        $product = new Product($branch->getGym(), $category, 'Gym T-Shirt', '15.00');

        return new ProductSale($branch, $product, 2, RetailPaymentMethod::CASH, $soldBy);
    }

    // ---- Owner ---------------------------------------------------------

    public function test_owner_can_create_view_and_manage_a_sale_on_any_branch(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $sale = $this->saleAt($branch, $owner);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $sale, [ProductSaleVoter::CREATE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $sale, [ProductSaleVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->tokenFor($owner), $sale, [ProductSaleVoter::MANAGE]));
    }

    // ---- Staff: create + view on own assigned branch -------------------

    /** functional requirements §15.3: "I am Staff, when I record a sale, then I can only do so for a branch I'm assigned to." */
    public function test_given_staff_is_assigned_to_a_branch_when_they_record_a_sale_there_then_it_succeeds(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);
        $sale = $this->saleAt($branch);

        $result = $this->voter->vote($this->tokenFor($staff), $sale, [ProductSaleVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function test_given_staff_is_not_assigned_to_the_branch_when_they_try_to_record_a_sale_there_then_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $staff = $this->user(UserRole::STAFF); // no assignment

        $result = $this->voter->vote($this->tokenFor($staff), $this->saleAt($branch), [ProductSaleVoter::CREATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_staff_cannot_view_a_different_branchs_sale_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branchA = new Branch($gym, 'Branch A', '1 Main St', isPrimary: true);
        $branchB = new Branch($gym, 'Branch B', '2 Side St');
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branchA);

        $result = $this->voter->vote($this->tokenFor($staff), $this->saleAt($branchB), [ProductSaleVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function test_staff_cannot_edit_or_delete_a_sale_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $staff = $this->user(UserRole::STAFF);
        new BranchAssignment($staff, $branch);

        $result = $this->voter->vote($this->tokenFor($staff), $this->saleAt($branch, $staff), [ProductSaleVoter::MANAGE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ---- Coach/Member: denied entirely ------------------------------------

    /** functional requirements §15.3: "I am a Coach or Member... via any route... permission error." */
    public function test_given_a_coach_when_they_attempt_to_record_or_view_a_sale_then_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $coach = $this->user(UserRole::COACH);
        $sale = $this->saleAt($branch);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $sale, [ProductSaleVoter::CREATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($coach), $sale, [ProductSaleVoter::VIEW]));
    }

    public function test_given_a_member_when_they_attempt_to_record_or_view_a_sale_then_403(): void
    {
        $owner = $this->user(UserRole::OWNER);
        $gym = new Gym('Test Gym', '123 Main St', $owner);
        $branch = new Branch($gym, 'Main', '123 Main St', isPrimary: true);
        $member = $this->user(UserRole::MEMBER);
        $sale = $this->saleAt($branch);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $sale, [ProductSaleVoter::CREATE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->tokenFor($member), $sale, [ProductSaleVoter::VIEW]));
    }
}
