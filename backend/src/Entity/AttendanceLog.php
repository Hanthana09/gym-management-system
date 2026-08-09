<?php

namespace App\Entity;

use App\Enum\CheckInMethod;
use App\Repository\AttendanceLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's ATTENDANCE_LOG entity exactly.
 * `checkOut` stays null for now — check-out isn't in Phase 5's scope
 * (roadmap Phase 5 / functional requirements §4 only cover check-in).
 */
#[ORM\Entity(repositoryClass: AttendanceLogRepository::class)]
class AttendanceLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\Column]
    private \DateTimeImmutable $checkIn;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkOut = null;

    #[ORM\Column(length: 20, enumType: CheckInMethod::class)]
    private CheckInMethod $method;

    public function __construct(MemberProfile $member, \DateTimeImmutable $checkIn, CheckInMethod $method)
    {
        $this->id = Uuid::v7();
        $this->member = $member;
        $this->checkIn = $checkIn;
        $this->method = $method;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMember(): MemberProfile
    {
        return $this->member;
    }

    public function getCheckIn(): \DateTimeImmutable
    {
        return $this->checkIn;
    }

    public function getCheckOut(): ?\DateTimeImmutable
    {
        return $this->checkOut;
    }

    public function getMethod(): CheckInMethod
    {
        return $this->method;
    }
}
