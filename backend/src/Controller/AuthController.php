<?php

namespace App\Controller;

use App\Entity\User;
use App\Otp\OtpService;
use App\Otp\OtpVerifyOutcome;
use App\Repository\UserRepository;
use App\Security\TokenIssuer;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TokenIssuer $tokenIssuer,
        private readonly OtpService $otpService,
        private readonly RateLimiterFactory $loginAttemptsLimiter,
        private readonly RateLimiterFactory $otpRequestLimiter,
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

        $response = new JsonResponse(['accessToken' => $accessToken, 'user' => $this->serializeUser($user)]);
        $response->headers->setCookie($cookie);

        return $response;
    }

    private function issueTokenResponse(User $user): JsonResponse
    {
        $accessToken = $this->tokenIssuer->createAccessToken($user);
        $cookie = $this->tokenIssuer->issueRefreshCookie($user);

        $response = new JsonResponse(['accessToken' => $accessToken, 'user' => $this->serializeUser($user)]);
        $response->headers->setCookie($cookie);

        return $response;
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
