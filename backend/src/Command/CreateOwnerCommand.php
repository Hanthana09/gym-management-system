<?php

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Gym\GymProvisioningService;
use App\Repository\GymRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One-time bootstrap: creates the first — and, by design, only — Owner
 * account. The Gym and its primary Branch are then created via
 * GymProvisioningService::ensureGymForOwner()/ensurePrimaryBranch(), the
 * exact same lazy-provisioning path every other part of this app already
 * relies on (first invitation sent, first membership plan created,
 * GymBrandingController, etc.) — not a second, hand-rolled creation path
 * that could quietly drift from it. This entity's own constructor/setters
 * are used directly (User/Gym/Branch are constructor-populated, no
 * setId()/setRole()/setGym()-style setters exist anywhere in this
 * codebase — a deliberate immutable-after-construction pattern).
 *
 * Single-gym product (CLAUDE.md): GymRepository::findTheOnlyGym() is
 * trusted everywhere in this codebase to mean exactly one Gym row exists
 * system-wide, not one per Owner. Running this command a second time
 * would create a second row and make that assumption silently wrong
 * everywhere it's relied on — refused outright below, not just
 * discouraged in a comment.
 */
#[AsCommand(name: 'app:create-owner', description: 'One-time bootstrap: creates the first Owner account, Gym, and primary Branch.')]
class CreateOwnerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GymRepository $gyms,
        private readonly UserRepository $users,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('This command must be run interactively (no --no-interaction) — it asks for a password and account details it never accepts as flags.');

            return Command::FAILURE;
        }

        if ($this->gyms->findTheOnlyGym() !== null) {
            $io->error(
                'A gym already exists. This command bootstraps the very first Owner for a '
                . 'single-gym deployment and refuses to run again — use the app\'s invite flow '
                . 'for any account after that.',
            );

            return Command::FAILURE;
        }

        $name = $io->ask('Your name', null, self::requireNonEmpty(...));
        $email = $io->ask('Email (used for password login)', null, self::requireNonEmpty(...));

        if ($this->users->findOneByEmail($email) !== null) {
            $io->error("A user with email \"{$email}\" already exists.");

            return Command::FAILURE;
        }

        $phone = $io->ask('Phone (optional, with country code, e.g. +947...)');

        $passwordQuestion = new Question('Password');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setValidator(self::requireNonEmpty(...));
        $password = $this->getHelper('question')->ask($input, $output, $passwordQuestion);

        $gymName = $io->ask('Gym / business name', "{$name}'s Gym");
        $branchName = $io->ask('Primary branch name', $gymName);
        $branchAddress = $io->ask('Primary branch address', '');

        $user = new User($name, $email, $phone !== null && $phone !== '' ? $phone : null, UserRole::OWNER, UserStatus::ACTIVE);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
        // gym-management-password-auth.md's requiresPasswordChange defaults
        // true on every new User (forces a mandatory change after an
        // *Owner* assigns someone else's password) — doesn't apply here,
        // the Owner is choosing their own password at first-run bootstrap.
        $user->setRequiresPasswordChange(false);
        $this->em->persist($user);
        $this->em->flush();

        // Same lazy-provisioning path every other feature in this app
        // uses, not a second Gym+Branch creation flow — then customize
        // past its defaults ("{Name}'s Gym", primary branch same name +
        // blank address) using the entities' own real setters.
        $gym = $this->gymProvisioning->ensureGymForOwner($user);
        $branch = $this->gymProvisioning->ensurePrimaryBranch($gym);
        $gym->setName($gymName);
        $branch->setName($branchName);
        $branch->setAddress($branchAddress);
        $this->em->flush();

        $io->success("Owner created: {$email} — Gym: {$gymName} — Branch: {$branchName}");

        return Command::SUCCESS;
    }

    private static function requireNonEmpty(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            throw new \RuntimeException('This field is required.');
        }

        return $value;
    }
}
