<?php

namespace App\Otp;

use App\Entity\OtpCode;
use App\Invitation\InvitationService;
use App\Repository\InvitationRepository;
use App\Repository\OtpCodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Architecture doc §6.1 / §8.5 / §9: 6-digit codes, hashed at rest,
 * 5-minute expiry, single-use, 5 failed attempts locks a code out.
 */
class OtpService
{
    public const CODE_TTL_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly OtpCodeRepository $otpCodes,
        private readonly UserRepository $users,
        private readonly InvitationRepository $invitations,
        private readonly InvitationService $invitationService,
        private readonly EntityManagerInterface $em,
        private readonly OtpDeliveryInterface $delivery,
    ) {
    }

    /**
     * Always behaves the same whether or not the destination is
     * registered — mirrors the password-login rule of never confirming
     * account existence (functional requirements §1.1), applied here to
     * avoid account enumeration via the OTP endpoint. The one exception
     * (still not leaking anything — same response either way) is that a
     * destination with a pending invitation but no account yet is still a
     * legitimate first-time login (architecture doc §6.7), so it still
     * gets a real code.
     */
    public function requestCode(string $destination): void
    {
        $user = $this->users->findOneByEmail($destination) ?? $this->users->findOneByPhone($destination);
        $isInvited = $user === null && $this->invitations->findAnyPendingForDestination($destination) !== null;

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::CODE_TTL_MINUTES . ' minutes');

        $otp = new OtpCode($user, $destination, $codeHash, $expiresAt);
        $this->em->persist($otp);
        $this->em->flush();

        if ($user !== null || $isInvited) {
            $this->delivery->send($destination, $code);
        }
    }

    public function verifyCode(string $destination, string $code): OtpVerificationResult
    {
        $otp = $this->otpCodes->findLatestForDestination($destination);

        if ($otp === null || $otp->isConsumed() || $otp->isExpired() || $otp->isLockedOut()) {
            return OtpVerificationResult::expiredOrUsed();
        }

        if (!password_verify($code, $otp->getCodeHash())) {
            $otp->incrementAttemptCount();
            $this->em->flush();

            return $otp->isLockedOut()
                ? OtpVerificationResult::lockedOut()
                : OtpVerificationResult::incorrect(self::MAX_ATTEMPTS - $otp->getAttemptCount());
        }

        if ($otp->getUser() === null) {
            // First-ever login for an invitee who had no account yet
            // (architecture doc §6.7: "the invitee registers ... via their
            // first OTP login"). Falls through to the generic failure if the
            // invitation that justified sending this code is gone (e.g. it
            // expired in the few minutes between request and verify).
            $newUser = $this->invitationService->provisionUserForDestination($destination);
            if ($newUser === null) {
                return OtpVerificationResult::expiredOrUsed();
            }

            $otp->markConsumed();
            $this->em->flush();

            return OtpVerificationResult::success($newUser);
        }

        $otp->markConsumed();
        $this->em->flush();

        return OtpVerificationResult::success($otp->getUser());
    }
}
