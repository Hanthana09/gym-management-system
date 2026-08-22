<?php

namespace App\Controller;

use App\Entity\User;
use App\Otp\OtpService;
use App\Otp\OtpVerifyOutcome;
use App\PasswordReset\PasswordResetOutcome;
use App\PasswordReset\PasswordResetService;
use App\Repository\UserRepository;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenIssuer $tokenIssuer,
        private readonly OtpService $otpService,
        private readonly PasswordResetService $passwordResetService,
        private readonly RateLimiterFactory $loginAttemptsLimiter,
        private readonly RateLimiterFactory $otpRequestLimiter,
        private readonly RateLimiterFactory $forgotPasswordIdentifierLimiter,
        private readonly RateLimiterFactory $forgotPasswordIpLimiter,
        private readonly RateLimiterFactory $resetPasswordLimiter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Email and password are required.'], 400);
        }

        $limiter = $this->loginAttemptsLimiter->create($email);
        // consume(0) never reports rejected (Symfony hardcodes accepted=true for
        // zero-token requests) — check remaining tokens directly to peek without
        // recording a hit for an attempt we haven't evaluated yet.
        if ($limiter->consume(0)->getRemainingTokens() <= 0) {
            return $this->rateLimitedResponse('Too many failed attempts. Please try again later.');
        }

        $user = $this->users->findOneByEmail($email);

        if ($user === null || $user->getPasswordHash() === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $limiter->consume(1);

            // Never confirm whether the email exists (functional requirements §1.1).
            return new JsonResponse(['error' => 'invalid_credentials', 'message' => 'Invalid email or password.'], 401);
        }

        return $this->issueTokenResponse($user);
    }

    #[Route('/otp/request', name: 'auth_otp_request', methods: ['POST'])]
    public function otpRequest(Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $destination = trim((string) ($data['destination'] ?? ''));

        if ($destination === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Destination is required.'], 400);
        }

        $key = hash('sha256', strtolower($destination)) . '|' . $request->getClientIp();
        $limiter = $this->otpRequestLimiter->create($key);

        if (!$limiter->consume(1)->isAccepted()) {
            return $this->rateLimitedResponse('Too many code requests. Please try again later.');
        }

        $this->otpService->requestCode($destination);

        return new JsonResponse([
            'message' => 'If that destination is registered, a code has been sent.',
            'expiresInSeconds' => OtpService::CODE_TTL_MINUTES * 60,
        ]);
    }

    #[Route('/otp/verify', name: 'auth_otp_verify', methods: ['POST'])]
    public function otpVerify(Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $destination = trim((string) ($data['destination'] ?? ''));
        $code = trim((string) ($data['code'] ?? ''));

        if ($destination === '' || $code === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Destination and code are required.'], 400);
        }

        $result = $this->otpService->verifyCode($destination, $code);

        return match ($result->outcome) {
            OtpVerifyOutcome::SUCCESS => $this->issueTokenResponse($result->user),
            OtpVerifyOutcome::INCORRECT => new JsonResponse([
                'error' => 'otp_incorrect',
                'message' => 'Incorrect code.',
                'remainingAttempts' => $result->remainingAttempts,
            ], 401),
            OtpVerifyOutcome::LOCKED_OUT => new JsonResponse([
                'error' => 'otp_locked_out',
                'message' => 'Too many incorrect attempts. Please request a new code.',
            ], 401),
            OtpVerifyOutcome::EXPIRED_OR_USED => new JsonResponse([
                'error' => 'otp_expired_or_used',
                'message' => 'This code has expired or already been used. Please request a new code.',
            ], 401),
        };
    }

    /**
     * Revokes the current refresh token server-side and clears the
     * cookie — without this, logout only ever cleared the frontend's
     * in-memory access token; the still-valid, still-live httpOnly
     * refresh cookie would silently re-authenticate the same user via
     * /auth/refresh on the very next page load.
     */
    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $raw = $request->cookies->get(TokenIssuer::REFRESH_COOKIE_NAME);
        $token = $raw !== null ? $this->tokenIssuer->resolveValidRefreshToken($raw) : null;
        if ($token !== null) {
            $token->revoke();
            $this->em->flush();
        }

        $response = new JsonResponse(['message' => 'Logged out.']);
        $response->headers->setCookie($this->tokenIssuer->expiredCookie());

        return $response;
    }

    #[Route('/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $raw = $request->cookies->get(TokenIssuer::REFRESH_COOKIE_NAME);
        $token = $raw !== null ? $this->tokenIssuer->resolveValidRefreshToken($raw) : null;

        if ($token === null) {
            $response = new JsonResponse(['error' => 'invalid_refresh_token', 'message' => 'Session expired. Please log in again.'], 401);
            $response->headers->setCookie($this->tokenIssuer->expiredCookie());

            return $response;
        }

        $user = $token->getUser();
        $accessToken = $this->tokenIssuer->createAccessToken($user);
        $cookie = $this->tokenIssuer->rotateRefreshCookie($token);

        $response = new JsonResponse([
            'accessToken' => $accessToken,
            'user' => $this->serializeUser($user),
            'password_change_required' => $this->passwordChangeRequired($user),
        ]);
        $response->headers->setCookie($cookie);

        return $response;
    }

    /**
     * gym-management-password-auth.md §4: self-service password change.
     * `currentPassword` is required unless this is the mandatory
     * first-change after an Owner assigned a password
     * (requiresPasswordChange true) or the account never had one
     * (passwordHash null, e.g. a pure OTP-only account setting its first
     * password outside the forgot-password flow).
     */
    #[Route('/change-password', name: 'auth_change_password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->unauthenticatedResponse();
        }

        $data = $this->decode($request);
        $currentPassword = $data['currentPassword'] ?? null;
        $newPassword = (string) ($data['newPassword'] ?? '');

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'newPassword must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
            ], 400);
        }

        $currentPasswordRequired = $user->getPasswordHash() !== null && !$user->isRequiresPasswordChange();
        if ($currentPasswordRequired) {
            if (!is_string($currentPassword) || $currentPassword === '' || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
                return new JsonResponse([
                    'error' => 'invalid_request',
                    'message' => 'currentPassword is required and must be correct.',
                ], 400);
            }
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPassword));
        $user->setRequiresPasswordChange(false);
        $this->em->flush();

        return new JsonResponse(['message' => 'Password updated.']);
    }

    /** gym-management-password-auth.md §4: public, always a generic response — never confirms whether the identifier exists. */
    #[Route('/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $identifier = trim((string) ($data['identifier'] ?? ''));

        if ($identifier === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'Identifier is required.'], 400);
        }

        $ipLimiter = $this->forgotPasswordIpLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$ipLimiter->consume(1)->isAccepted()) {
            return $this->rateLimitedResponse('Too many requests. Please try again later.');
        }

        $identifierLimiter = $this->forgotPasswordIdentifierLimiter->create(hash('sha256', strtolower($identifier)));
        if (!$identifierLimiter->consume(1)->isAccepted()) {
            return $this->rateLimitedResponse('Too many requests. Please try again later.');
        }

        $this->passwordResetService->requestReset($identifier, $request->getClientIp());

        return new JsonResponse(['message' => 'If an account exists for that identifier, a code has been sent.']);
    }

    /** gym-management-password-auth.md §4: public; generic error on any failure — never distinguishes the cause. */
    #[Route('/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $this->decode($request);
        $identifier = trim((string) ($data['identifier'] ?? ''));
        $token = trim((string) ($data['token'] ?? ''));
        $newPassword = (string) ($data['newPassword'] ?? '');

        if ($identifier === '' || $token === '' || $newPassword === '') {
            return new JsonResponse(['error' => 'invalid_request', 'message' => 'identifier, token, and newPassword are required.'], 400);
        }

        if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'message' => 'newPassword must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
            ], 400);
        }

        $limiter = $this->resetPasswordLimiter->create(hash('sha256', strtolower($identifier)));
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->rateLimitedResponse('Too many attempts. Please request a new code.');
        }

        $outcome = $this->passwordResetService->redeemReset($identifier, $token, $newPassword);

        if ($outcome === PasswordResetOutcome::INVALID) {
            return new JsonResponse(['error' => 'invalid_or_expired', 'message' => 'This code is invalid or has expired.'], 400);
        }

        return new JsonResponse(['message' => 'Password has been reset. Please log in.']);
    }

    private function issueTokenResponse(User $user): JsonResponse
    {
        $accessToken = $this->tokenIssuer->createAccessToken($user);
        $cookie = $this->tokenIssuer->issueRefreshCookie($user);

        $response = new JsonResponse([
            'accessToken' => $accessToken,
            'user' => $this->serializeUser($user),
            'password_change_required' => $this->passwordChangeRequired($user),
        ]);
        $response->headers->setCookie($cookie);

        return $response;
    }

    /** Irrelevant while passwordHash is null — an OTP-only account has no forced-change prompt to show. */
    private function passwordChangeRequired(User $user): bool
    {
        return $user->getPasswordHash() !== null && $user->isRequiresPasswordChange();
    }

    private function unauthenticatedResponse(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated', 'message' => 'Login required.'], 401);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => (string) $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'role' => $user->getRole()->value,
            'status' => $user->getStatus()->value,
            'whatsappOptIn' => $user->isWhatsappOptIn(),
        ];
    }

    private function rateLimitedResponse(string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'rate_limited', 'message' => $message], 429);
    }

    private function decode(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (JsonException) {
            return [];
        }
    }
}
