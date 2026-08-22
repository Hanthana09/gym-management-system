<?php

namespace App\PasswordReset;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Records which channel actually carried the code (PasswordResetToken::
 * $channel) at the point delivery is actually attempted, since that's
 * only known once this async handler runs — not at dispatch time.
 */
#[AsMessageHandler]
class SendPasswordResetCodeMessageHandler
{
    public function __construct(
        private readonly PasswordResetTokenRepository $tokens,
        private readonly PasswordResetCodeSender $sender,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SendPasswordResetCodeMessage $message): void
    {
        $token = $this->tokens->find($message->tokenId);
        if ($token === null) {
            return; // deleted/race between dispatch and consumption — nothing to record
        }

        $channel = $this->sender->send($message->destination, $message->rawToken);
        $token->setChannel($channel);
        $this->em->flush();
    }
}
