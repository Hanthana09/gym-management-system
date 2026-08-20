<?php

namespace App\Tests\Command;

use App\Entity\Gym;
use App\Entity\Invitation;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\InvitationRole;
use App\Enum\InvitationStatus;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\MemberProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * gym-management-member-profile-extension.md §3's backfill — idempotency
 * (run twice, second run is a no-op) and correct gym resolution via the
 * member's approved Invitation, mirroring ImportExercisesCommandTest's
 * KernelTestCase + CommandTester structure.
 */
final class BackfillMemberIdsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MemberProfileRepository $memberProfiles;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->memberProfiles = $container->get(MemberProfileRepository::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE member_sequence, invitation, member_profile, gym, "user" CASCADE',
        );

        $application = new Application(self::$kernel);
        $command = $application->find('app:member:backfill-ids');
        $this->commandTester = new CommandTester($command);
    }

    private function persistOwnerAndGym(string $gymName): Gym
    {
        $owner = new User("Owner of {$gymName}", strtolower(str_replace(' ', '', $gymName)) . '@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $gym = new Gym($gymName, '1 Main St', $owner);
        $this->em->persist($gym);
        $this->em->flush();

        return $gym;
    }

    private function persistApprovedMember(Gym $gym, string $name): MemberProfile
    {
        $inviter = $gym->getOwner();
        $user = new User($name, strtolower(str_replace(' ', '', $name)) . '@example.com', null, UserRole::MEMBER, UserStatus::ACTIVE);
        $this->em->persist($user);

        $invitation = new Invitation($gym, $inviter, $user, $user->getEmail(), null, InvitationRole::MEMBER, new \DateTimeImmutable('+7 days'));
        $invitation->approve();
        $this->em->persist($invitation);

        $profile = new MemberProfile($user);
        $this->em->persist($profile);
        $this->em->flush();

        return $profile;
    }

    public function test_backfill_assigns_gym_and_member_id_via_the_approved_invitation(): void
    {
        $gymA = $this->persistOwnerAndGym('Gym A');
        $profile = $this->persistApprovedMember($gymA, 'Mia Member');

        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Backfilled 1, skipped 0', $this->commandTester->getDisplay());

        $this->em->refresh($profile);
        self::assertNotNull($profile->getGym());
        self::assertSame((string) $gymA->getId(), (string) $profile->getGym()->getId());
        self::assertNotNull($profile->getMemberId());
    }

    public function test_running_the_backfill_twice_is_a_no_op_the_second_time(): void
    {
        $gymA = $this->persistOwnerAndGym('Gym A');
        $profile = $this->persistApprovedMember($gymA, 'Mia Member');
        $this->commandTester->execute([]);
        $this->em->refresh($profile);
        $firstMemberId = $profile->getMemberId();

        $this->commandTester->execute([]);

        self::assertStringContainsString('Backfilled 0, skipped 1', $this->commandTester->getDisplay());
        $this->em->refresh($profile);
        self::assertSame($firstMemberId, $profile->getMemberId());
    }

    /** Two gyms' pre-existing members each get their own gym's prefix and independent numbering. */
    public function test_members_of_different_gyms_get_correctly_scoped_member_ids(): void
    {
        $gymA = $this->persistOwnerAndGym('Alpha Gym');
        $gymB = $this->persistOwnerAndGym('Beta Gym');
        $memberA = $this->persistApprovedMember($gymA, 'Mia Member');
        $memberB = $this->persistApprovedMember($gymB, 'Bob Member');

        $this->commandTester->execute([]);

        $this->em->refresh($memberA);
        $this->em->refresh($memberB);
        self::assertStringStartsWith('ALPH-', (string) $memberA->getMemberId());
        self::assertStringStartsWith('BETA-', (string) $memberB->getMemberId());
    }
}
