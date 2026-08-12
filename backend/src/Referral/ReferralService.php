<?php

namespace App\Referral;

use App\Entity\ReferralCode;
use App\Entity\ReferralLead;
use App\Entity\User;
use App\Repository\ReferralCodeRepository;
use App\Repository\ReferralLeadRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * roadmap Phase 9.2: deliberately lightweight — capture leads and
 * referral-code usage, nothing here provisions a new gym/tenant or
 * touches billing. Both stay manual/sales-team steps, per the roadmap.
 */
class ReferralService
{
    public function __construct(
        private readonly ReferralLeadRepository $leads,
        private readonly ReferralCodeRepository $codes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function submitLead(User $referredBy, string $prospectGymName, ?string $contactName, ?string $contactEmail, ?string $contactPhone): ReferralLead
    {
        $lead = new ReferralLead($referredBy, $prospectGymName, $contactName, $contactEmail, $contactPhone);
        $this->em->persist($lead);
        $this->em->flush();

        return $lead;
    }

    /** @return ReferralLead[] */
    public function listLeadsForUser(User $user): array
    {
        return $this->leads->findForReferrer($user);
    }

    /** Lazily provisioned, same pattern as GymProvisioningService::ensureGymForOwner. */
    public function getOrCreateCodeForOwner(User $owner): ReferralCode
    {
        $existing = $this->codes->findOneByOwner($owner);
        if ($existing !== null) {
            return $existing;
        }

        do {
            $code = $this->generateCode($owner);
        } while ($this->codes->findOneByCode($code) !== null);

        $referralCode = new ReferralCode($owner, $code);
        $this->em->persist($referralCode);
        $this->em->flush();

        return $referralCode;
    }

    /**
     * Placeholder for the real "a new gym signed up using this code"
     * flow, which doesn't exist yet (no self-service gym registration is
     * built anywhere in this product) — this is the manual action a
     * team member would trigger once that happens, kept intentionally
     * simple per the roadmap's "don't over-build automated crediting
     * before Phase 10 (Billing) exists."
     */
    public function redeemCode(string $code): ?ReferralCode
    {
        $referralCode = $this->codes->findOneByCode($code);
        if ($referralCode === null) {
            return null;
        }

        $referralCode->incrementUsage();
        // Phase 10: the referring Owner's half of "both get a free
        // month" — see ReferralCode's docblock. (The referred Owner's
        // half stays unimplemented, same as before: no "new gym signed
        // up" identity exists in this single-gym product to grant it to.)
        $referralCode->grantCredit();
        $this->em->flush();

        return $referralCode;
    }

    /**
     * BillingService::issueInvoiceForMembership() calls this for every
     * new invoice — a no-op (returns null) on the common path where the
     * gym's Owner has no banked referral credit.
     */
    public function consumeCreditIfAvailable(User $owner): ?ReferralCode
    {
        $referralCode = $this->codes->findOneByOwner($owner);
        if ($referralCode === null || !$referralCode->hasCredit()) {
            return null;
        }

        $referralCode->consumeCredit();
        $this->em->flush();

        return $referralCode;
    }

    private function generateCode(User $owner): string
    {
        $base = strtoupper(preg_replace('/[^A-Za-z]/', '', explode(' ', $owner->getName())[0] ?? '') ?: 'GYM');
        $base = substr($base, 0, 8);
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

        return "{$base}-{$suffix}";
    }
}
