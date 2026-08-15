<?php

namespace App\Entity;

use App\Repository\GymRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's GYM entity exactly. Not named in
 * Phase 3's own scope, but required infrastructure: INVITATION.gym_id and
 * InvitationVoter::SEND (§9.1) both depend on it. This is a single-gym
 * product (CLAUDE.md), so in practice there's exactly one row — lazily
 * created for an Owner the first time they send an invitation rather than
 * needing a separate "gym setup" screen not yet in the roadmap.
 */
#[ORM\Entity(repositoryClass: GymRepository::class)]
class Gym
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false)]
    private User $owner;

    /** roadmap Phase 15.2 / architecture doc §5.1, §6.11: nullable — a gym with no branding set shows product defaults (functional requirements §12.1). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoUrl = null;

    /** nullable hex — DESIGN-SYSTEM.md §4.1's white-label boundary rule: this is the ONLY field that carries a gym's brand color, never threaded elsewhere. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $brandColor = null;

    /**
     * roadmap Phase 15.3's admin config follow-up: a gym-wide master
     * switch, separate from each User's own `whatsappOptIn`. Defaults
     * false ("opt-in, not opt-out" applied at the gym level too) — a
     * newly provisioned gym sends no WhatsApp messages until an Owner
     * both configures credentials AND flips this on.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $whatsappEnabled = false;

    /**
     * Stored in plaintext, matching this codebase's current security
     * posture — no encryption-at-rest infrastructure exists anywhere yet
     * (not even for MEMBER_PROFILE.health_notes, which architecture doc
     * §9 already flags as needing it). Never round-tripped back to the
     * browser once set — GymWhatsAppSettingsController's GET returns
     * only whether it's set, not the value itself.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $whatsappAccessToken = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $whatsappPhoneNumberId = null;

    public function __construct(string $name, string $address, User $owner)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->address = $address;
        $this->owner = $owner;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): void
    {
        $this->logoUrl = $logoUrl;
    }

    public function getBrandColor(): ?string
    {
        return $this->brandColor;
    }

    public function setBrandColor(?string $brandColor): void
    {
        $this->brandColor = $brandColor;
    }

    public function isWhatsappEnabled(): bool
    {
        return $this->whatsappEnabled;
    }

    public function setWhatsappEnabled(bool $whatsappEnabled): void
    {
        $this->whatsappEnabled = $whatsappEnabled;
    }

    public function getWhatsappAccessToken(): ?string
    {
        return $this->whatsappAccessToken;
    }

    public function setWhatsappAccessToken(?string $whatsappAccessToken): void
    {
        $this->whatsappAccessToken = $whatsappAccessToken;
    }

    public function getWhatsappPhoneNumberId(): ?string
    {
        return $this->whatsappPhoneNumberId;
    }

    public function setWhatsappPhoneNumberId(?string $whatsappPhoneNumberId): void
    {
        $this->whatsappPhoneNumberId = $whatsappPhoneNumberId;
    }

    public function isWhatsappConfigured(): bool
    {
        return $this->whatsappAccessToken !== null && $this->whatsappPhoneNumberId !== null;
    }
}
