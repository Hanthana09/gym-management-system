<?php

namespace App\Gym;

use App\Entity\Gym;
use App\Repository\GymRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * gym-management-member-profile-extension.md §3/§6.1: memberId's
 * `{GymCode}-{0001}` prefix. Derived from the gym's name rather than
 * asked of the Owner up front — GymProvisioningService already
 * lazily provisions a gym with no separate "setup" step, and this
 * keeps that property (a code exists the moment it's needed, never
 * blocking on an Owner filling in a form field first). Owner-editable
 * afterwards via GymBrandingController, same as name/brandColor.
 */
class GymCodeGenerator
{
    public function __construct(
        private readonly GymRepository $gyms,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function ensureCodeFor(Gym $gym): string
    {
        $existing = $gym->getGymCode();
        if ($existing !== null) {
            return $existing;
        }

        $base = $this->baseCodeFrom($gym->getName());
        $candidate = $base;
        $suffix = 1;
        while ($this->gyms->findOneBy(['gymCode' => $candidate]) !== null) {
            ++$suffix;
            $candidate = $base . $suffix;
        }

        $gym->setGymCode($candidate);
        $this->em->flush();

        return $candidate;
    }

    private function baseCodeFrom(string $name): string
    {
        $alnum = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $name) ?? '');
        $base = substr($alnum, 0, 4);

        return $base !== '' ? $base : 'GYM';
    }
}
