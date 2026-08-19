<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Gym\GymProvisioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A deliberate, explicitly-scoped exception to the invite+approval
 * onboarding rule (see the command's own docblock) — tests just need to
 * confirm it stays idempotent and produces a genuinely functional login,
 * not re-litigate the architecture decision.
 */
final class SeedDemoAccountsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE branch_assignment, coach_profile, member_profile, invitation, branch, gym, otp_code, refresh_token, "user" CASCADE',
        );

        $owner = new User('Olivia Owner', 'owner@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $this->em->flush();
        $container->get(GymProvisioningService::class)->ensureGymForOwner($owner);

        $application = new Application(self::$kernel);
        $this->commandTester = new CommandTester($application->find('app:demo:seed-accounts'));
    }

    public function test_creates_the_requested_number_of_clearly_labeled_demo_accounts(): void
    {
        $this->commandTester->execute(['--coaches' => '2', '--members' => '3'], ['interactive' => false]);

        self::assertSame(0, $this->commandTester->getStatusCode());

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE email LIKE \'%setly-demo.test\'');
        self::assertSame(5, $count);

        $coach = $this->em->getRepository(User::class)->findOneBy(['email' => 'demo-coach-amara-perera@setly-demo.test']);
        self::assertNotNull($coach);
        self::assertStringContainsString('(Demo)', $coach->getName());
        self::assertSame(UserStatus::ACTIVE, $coach->getStatus());
    }

    /** claude-code-prompt-style idempotency expectation: re-running must not duplicate rows. */
    public function test_running_it_twice_does_not_duplicate_accounts_or_branch_assignments(): void
    {
        $this->commandTester->execute(['--coaches' => '2', '--members' => '2'], ['interactive' => false]);
        $second = new CommandTester((new Application(self::$kernel))->find('app:demo:seed-accounts'));
        $second->execute(['--coaches' => '2', '--members' => '2'], ['interactive' => false]);

        self::assertSame(0, $second->getStatusCode());
        $userCount = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM "user" WHERE email LIKE \'%setly-demo.test\'');
        self::assertSame(4, $userCount);

        $coach = $this->em->getRepository(User::class)->findOneBy(['email' => 'demo-coach-amara-perera@setly-demo.test']);
        $assignmentCount = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM branch_assignment WHERE user_id = ?',
            [(string) $coach->getId()],
        );
        self::assertSame(1, $assignmentCount);
    }

    public function test_the_printed_password_actually_authenticates(): void
    {
        $this->commandTester->execute(['--coaches' => '1', '--members' => '0', '--password' => 'demoPassword123'], ['interactive' => false]);

        $coach = $this->em->getRepository(User::class)->findOneBy(['email' => 'demo-coach-amara-perera@setly-demo.test']);
        self::assertNotNull($coach);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($coach, 'demoPassword123'));
    }

    public function test_a_second_run_with_a_new_password_replaces_the_old_one(): void
    {
        $this->commandTester->execute(['--coaches' => '1', '--members' => '0', '--password' => 'firstPassword1'], ['interactive' => false]);
        $second = new CommandTester((new Application(self::$kernel))->find('app:demo:seed-accounts'));
        $second->execute(['--coaches' => '1', '--members' => '0', '--password' => 'secondPassword2'], ['interactive' => false]);

        $coach = $this->em->getRepository(User::class)->findOneBy(['email' => 'demo-coach-amara-perera@setly-demo.test']);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertFalse($hasher->isPasswordValid($coach, 'firstPassword1'));
        self::assertTrue($hasher->isPasswordValid($coach, 'secondPassword2'));
    }

    public function test_it_refuses_more_accounts_than_the_name_pool_supports(): void
    {
        $this->commandTester->execute(['--coaches' => '99', '--members' => '0'], ['interactive' => false]);

        self::assertSame(1, $this->commandTester->getStatusCode());
    }

    public function test_it_refuses_to_run_with_no_gym_provisioned_yet(): void
    {
        $this->em->getConnection()->executeStatement('TRUNCATE branch_assignment, coach_profile, member_profile, invitation, branch, gym, otp_code, refresh_token, "user" CASCADE');

        $result = new CommandTester((new Application(self::$kernel))->find('app:demo:seed-accounts'));
        $result->execute(['--coaches' => '1', '--members' => '0'], ['interactive' => false]);

        self::assertSame(1, $result->getStatusCode());
    }
}
