<?php

namespace App\Command;

use App\Entity\Branch;
use App\Entity\BranchAssignment;
use App\Entity\CoachProfile;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\BranchRepository;
use App\Repository\CoachProfileRepository;
use App\Repository\GymRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Deliberate, explicitly-scoped exception to CLAUDE.md's "invite ->
 * explicit approval" onboarding rule (architecture doc §6.7) — agreed
 * with the user for demo/sales/launch-showcase accounts only, never for
 * onboarding real coaches or members (that's `POST /api/invitations` /
 * `/api/invitations/bulk`, which still requires the invitee's own OTP
 * login + approval — use that for anyone real).
 *
 * Every account this command creates:
 *   - has an email on the RFC 2606 reserved `.test` TLD, so it can never
 *     collide with a real invitee's address
 *   - has "(Demo)" in its display name, so it's unmistakable anywhere the
 *     name is shown in the product (coach picker, member roster, ...)
 *   - logs in via password only (no OTP inbox exists for a `.test`
 *     address) — this command prints the password once per run
 *
 * Idempotent: re-running finds existing demo accounts by email and only
 * resets their password (so the printed credential is always current);
 * it never duplicates rows or creates a second BranchAssignment.
 *
 * Deliberately does NOT dispatch InvitationApprovedEvent or any other
 * domain event — these accounts should not trigger real notifications,
 * Mercure pushes, or WhatsApp/email sends.
 */
#[AsCommand(name: 'app:demo:seed-accounts', description: 'Create or refresh clearly-labeled demo Coach/Member accounts for sales/launch showcases — NOT for onboarding real people')]
class SeedDemoAccountsCommand extends Command
{
    private const EMAIL_DOMAIN = 'setly-demo.test';

    private const COACH_NAMES = ['Amara Perera', 'Kasun Fernando', 'Nadeesha Silva', 'Tharindu Bandara', 'Ishara Wickramasinghe'];
    private const MEMBER_NAMES = ['Dilani Jayasuriya', 'Ruwan Gunawardena', 'Sanduni Rathnayake', 'Chamara Dissanayake', 'Hasini Kodithuwakku', 'Lahiru Weerasinghe', 'Tharushi Ekanayake', 'Nimal Abeysekera'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly GymRepository $gyms,
        private readonly BranchRepository $branches,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('coaches', null, InputOption::VALUE_REQUIRED, 'Number of demo coach accounts', '3')
            ->addOption('members', null, InputOption::VALUE_REQUIRED, 'Number of demo member accounts', '5')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Shared password for every demo account (default: a freshly generated one, printed once)')
            ->setHelp(
                'Creates or refreshes clearly-labeled demo Coach/Member accounts (name suffixed "(Demo)", '
                . 'email on the reserved @' . self::EMAIL_DOMAIN . ' domain) for sales calls, launch demos, or '
                . 'screenshots — never for onboarding a real coach or member. For real people, use '
                . '`POST /api/invitations` (or the bulk CSV endpoint) so they go through their own OTP login and '
                . 'explicit approval, per architecture doc §6.7.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $coachCount = (int) $input->getOption('coaches');
        $memberCount = (int) $input->getOption('members');

        if ($coachCount < 0 || $memberCount < 0) {
            $io->error('--coaches and --members must be zero or positive.');

            return Command::FAILURE;
        }
        if ($coachCount > count(self::COACH_NAMES) || $memberCount > count(self::MEMBER_NAMES)) {
            $io->error(sprintf('At most %d coaches and %d members are supported (fixed name pool, to keep every demo account recognizably named).', count(self::COACH_NAMES), count(self::MEMBER_NAMES)));

            return Command::FAILURE;
        }

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null) {
            $io->error('No gym exists yet — run `app:create-owner` first.');

            return Command::FAILURE;
        }
        $branch = $this->branches->findPrimaryForGym($gym);
        if ($branch === null) {
            $io->error('Gym has no primary branch yet.');

            return Command::FAILURE;
        }

        $io->warning([
            'This creates REAL, ALWAYS-ACTIVE, password-login accounts in this database.',
            'Only for demo/sales/showcase use — never to onboard a real coach or member',
            '(use POST /api/invitations for that; it still requires the invitee\'s own OTP + approval).',
        ]);
        if ($input->isInteractive() && !$io->confirm(sprintf('Create/refresh %d demo coach(es) and %d demo member(s)?', $coachCount, $memberCount), false)) {
            $io->comment('Cancelled — no changes made.');

            return Command::SUCCESS;
        }

        $password = (string) ($input->getOption('password') ?: bin2hex(random_bytes(6)));
        $rows = [];

        for ($i = 0; $i < $coachCount; $i++) {
            $rows[] = ['Coach', ...$this->upsertAccount(self::COACH_NAMES[$i], UserRole::COACH, $password, $branch)];
        }
        for ($i = 0; $i < $memberCount; $i++) {
            $rows[] = ['Member', ...$this->upsertAccount(self::MEMBER_NAMES[$i], UserRole::MEMBER, $password, $branch)];
        }

        $this->em->flush();

        $io->success('Demo accounts ready. Password is shown only in this output — store it if you need it again.');
        $io->table(['Role', 'Name', 'Email', 'Password'], $rows);

        return Command::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string} [name, email, password] */
    private function upsertAccount(string $baseName, UserRole $role, string $password, Branch $branch): array
    {
        $slug = strtolower(str_replace(' ', '-', $baseName));
        $email = sprintf('demo-%s-%s@%s', $role->value, $slug, self::EMAIL_DOMAIN);
        $displayName = $baseName . ' (Demo)';

        $user = $this->users->findOneByEmail($email);
        if ($user === null) {
            $user = new User($displayName, $email, null, $role, UserStatus::ACTIVE);
            $this->em->persist($user);
        }
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        if ($role === UserRole::COACH) {
            if ($this->coachProfiles->findOneByUser($user) === null) {
                $this->em->persist(new CoachProfile($user));
            }
            $alreadyAssigned = $user->getBranchAssignments()->exists(fn ($k, BranchAssignment $a) => $a->getBranch() === $branch);
            if (!$alreadyAssigned) {
                $this->em->persist(new BranchAssignment($user, $branch));
            }
        } else {
            if ($this->memberProfiles->findOneByUser($user) === null) {
                $this->em->persist(new MemberProfile($user));
            }
        }

        return [$displayName, $email, $password];
    }
}
