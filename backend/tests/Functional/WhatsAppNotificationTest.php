<?php

namespace App\Tests\Functional;

use App\Entity\Gym;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Notification\NotificationService;
use App\Notification\SendNotificationWhatsAppMessage;
use App\Notification\SendNotificationWhatsAppMessageHandler;
use App\Notification\WhatsAppSenderInterface;
use App\Security\TokenIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * roadmap Phase 15.3. `notify()` is the single fan-out point every other
 * module already calls (Membership, Attendance, PT sessions — untouched
 * here, see the zero-diff check in the phase report) — these tests call
 * it directly rather than re-driving one of those flows through HTTP,
 * since the thing actually under test is the WhatsApp channel itself,
 * not any particular event.
 */
final class WhatsAppNotificationTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private FakeWhatsAppSender $sender;

    protected function setUp(): void
    {
        static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE notification, gym, "user" CASCADE',
        );

        $this->sender = new FakeWhatsAppSender();
        static::getContainer()->set(WhatsAppSenderInterface::class, $this->sender);
    }

    private function createUser(string $name, ?string $phone, bool $whatsappOptIn): User
    {
        $user = new User($name, $name . '@example.com', $phone, UserRole::MEMBER, UserStatus::ACTIVE);
        $user->setWhatsappOptIn($whatsappOptIn);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createOwner(string $name): User
    {
        $owner = new User($name, $name . '@example.com', null, UserRole::OWNER, UserStatus::ACTIVE);
        $this->em->persist($owner);
        $this->em->flush();

        return $owner;
    }

    /** Master switch on + credentials configured — the "everything's set up" baseline most delivery tests build on. */
    private function createEnabledGym(User $owner): Gym
    {
        $gym = new Gym($owner->getName() . "'s Gym", '', $owner);
        $gym->setWhatsappEnabled(true);
        $gym->setWhatsappAccessToken('test-token');
        $gym->setWhatsappPhoneNumberId('test-phone-id');
        $this->em->persist($gym);
        $this->em->flush();

        return $gym;
    }

    /** Simulates the async transport's consumer, same as a real `messenger:consume async` worker would. */
    private function consumeQueuedWhatsAppMessage(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $envelope = current(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof SendNotificationWhatsAppMessage,
        ));
        self::assertNotFalse($envelope, 'No SendNotificationWhatsAppMessage was queued.');

        static::getContainer()->get(SendNotificationWhatsAppMessageHandler::class)($envelope->getMessage());
    }

    public function test_opted_in_user_with_a_phone_receives_a_whatsapp_message(): void
    {
        $owner = $this->createOwner('Olivia Owner');
        $this->createEnabledGym($owner);
        $user = $this->createUser('Mia Member', '+15550001234', true);

        static::getContainer()->get(NotificationService::class)
            ->notify($user, NotificationType::BOOKING, 'Session confirmed', 'Your PT session is confirmed for 5pm.');

        $this->consumeQueuedWhatsAppMessage();

        self::assertCount(1, $this->sender->sent);
        self::assertSame('+15550001234', $this->sender->sent[0]['to']);
        self::assertStringContainsString('Session confirmed', $this->sender->sent[0]['message']);
    }

    public function test_opted_out_user_receives_no_whatsapp_message(): void
    {
        $owner = $this->createOwner('Olivia Owner');
        $this->createEnabledGym($owner);
        $user = $this->createUser('Mia Member', '+15550001234', false);

        static::getContainer()->get(NotificationService::class)
            ->notify($user, NotificationType::BOOKING, 'Session confirmed', 'Your PT session is confirmed for 5pm.');

        $this->consumeQueuedWhatsAppMessage();

        self::assertCount(0, $this->sender->sent);
    }

    /** Opted in but no phone on file — nothing to send to, same "skip, don't error" shape as the email handler's no-email branch. */
    public function test_opted_in_user_with_no_phone_receives_no_whatsapp_message(): void
    {
        $owner = $this->createOwner('Olivia Owner');
        $this->createEnabledGym($owner);
        $user = $this->createUser('Mia Member', null, true);

        static::getContainer()->get(NotificationService::class)
            ->notify($user, NotificationType::BOOKING, 'Session confirmed', 'Your PT session is confirmed for 5pm.');

        $this->consumeQueuedWhatsAppMessage();

        self::assertCount(0, $this->sender->sent);
    }

    /** The Owner's gym-wide master switch overrides individual opt-in — this is the whole point of adding it. */
    public function test_opted_in_user_receives_nothing_when_the_gym_master_switch_is_off(): void
    {
        $owner = $this->createOwner('Olivia Owner');
        $gym = $this->createEnabledGym($owner);
        $gym->setWhatsappEnabled(false);
        $this->em->flush();
        $user = $this->createUser('Mia Member', '+15550001234', true);

        static::getContainer()->get(NotificationService::class)
            ->notify($user, NotificationType::BOOKING, 'Session confirmed', 'Your PT session is confirmed for 5pm.');

        $this->consumeQueuedWhatsAppMessage();

        self::assertCount(0, $this->sender->sent);
    }

    /** No gym provisioned at all yet — same "nothing to send to" shape, not an error. */
    public function test_opted_in_user_receives_nothing_when_no_gym_exists_yet(): void
    {
        $user = $this->createUser('Mia Member', '+15550001234', true);

        static::getContainer()->get(NotificationService::class)
            ->notify($user, NotificationType::BOOKING, 'Session confirmed', 'Your PT session is confirmed for 5pm.');

        $this->consumeQueuedWhatsAppMessage();

        self::assertCount(0, $this->sender->sent);
    }

    /** notify() always queues the WhatsApp message regardless of opt-in — eligibility is the handler's job (mirrors the email path exactly). */
    public function test_notify_always_queues_a_whatsapp_message_even_when_opted_out(): void
    {
        $user = $this->createUser('Mia Member', '+15550001234', false);

        static::getContainer()->get(NotificationService::class)
            ->notify($user, NotificationType::BOOKING, 'Session confirmed', 'Your PT session is confirmed for 5pm.');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $whatsAppMessages = array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof SendNotificationWhatsAppMessage,
        );

        self::assertCount(1, $whatsAppMessages);
    }

    public function test_owner_can_opt_in_via_the_preferences_endpoint(): void
    {
        $user = $this->createUser('Olivia Owner', '+15550001234', false);
        $token = static::getContainer()->get(TokenIssuer::class)->createAccessToken($user);

        static::getClient()->request(
            'PATCH',
            '/users/me/notification-preferences',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode(['whatsappOptIn' => true], \JSON_THROW_ON_ERROR),
        );

        $result = json_decode(static::getClient()->getResponse()->getContent(), true);

        self::assertSame(200, static::getClient()->getResponse()->getStatusCode());
        self::assertTrue($result['whatsappOptIn']);
    }

    public function test_notification_preferences_endpoint_requires_authentication(): void
    {
        static::getClient()->request(
            'PATCH',
            '/users/me/notification-preferences',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTPS' => 'on'],
            content: json_encode(['whatsappOptIn' => true], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(401, static::getClient()->getResponse()->getStatusCode());
    }
}
