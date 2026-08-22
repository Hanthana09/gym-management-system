<?php

namespace App\PasswordReset;

use App\Audit\AuditLogger;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * gym-management-password-auth.md §3.1: an Owner creating/resetting a
 * password on someone else's behalf. Always forces a mandatory
 * "set a new password" prompt on that user's next login
 * (requiresPasswordChange = true) — never the Owner's own concern to
 * remember to do that separately.
 */
class AdminSetPasswordService
{
    private const GENERATED_LENGTH = 10;
    // No ambiguous-looking characters (0/O, 1/l/I) — this is displayed once, by hand, to an Owner who may transcribe it verbally.
    private const GENERATED_CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function setPassword(User $target, User $owner, ?string $plainPassword): string
    {
        $plainPassword ??= $this->generatePassword();

        $target->setPasswordHash($this->passwordHasher->hashPassword($target, $plainPassword));
        $target->setRequiresPasswordChange(true);
        $target->setPasswordSetBy($owner);
        $target->setPasswordSetAt(new \DateTimeImmutable());
        $this->em->flush();

        // architecture doc §9 / CLAUDE.md: audit log for any Owner action
        // that touches another user's account. The plaintext password is
        // never included — only the fact that it was (re)set.
        $this->auditLogger->log($owner, 'user.password_set', 'User', $target->getId());

        return $plainPassword;
    }

    private function generatePassword(): string
    {
        $password = '';
        for ($i = 0; $i < self::GENERATED_LENGTH; ++$i) {
            $password .= self::GENERATED_CHARSET[random_int(0, strlen(self::GENERATED_CHARSET) - 1)];
        }

        return $password;
    }
}
