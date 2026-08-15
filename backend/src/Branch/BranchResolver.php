<?php

namespace App\Branch;

use App\Entity\Branch;
use App\Entity\Gym;
use App\Repository\BranchRepository;

/**
 * roadmap Phase 16: the one place every branch-scoped endpoint
 * (membership plans, front-desk check-in, PT session booking, the
 * announcement composer, /reports/*) turns a request's optional
 * `branchId` into an actual Branch — defaulting to the gym's primary
 * branch when omitted, so a single-branch gym's existing callers need no
 * change at all (functional requirements §14.1). Named distinctly from
 * the frontend's BranchSwitcher component — this is the server-side
 * resolution counterpart, not the UI control.
 */
class BranchResolver
{
    public function __construct(private readonly BranchRepository $branches)
    {
    }

    /** Returns null when $branchId is given but doesn't belong to $gym — callers turn that into a 400, never silently fall back. */
    public function resolve(Gym $gym, ?string $branchId): ?Branch
    {
        if ($branchId === null || $branchId === '') {
            return $this->branches->findPrimaryForGym($gym);
        }

        $branch = $this->branches->find($branchId);

        return $branch !== null && $branch->getGym() === $gym ? $branch : null;
    }
}
