<?php

namespace App\Enum;

/**
 * Not in architecture doc §5.1's ANNOUNCEMENT entity diagram (which only
 * lists id/gym_id/body/created_at), but required by §9.1's AnnouncementVoter
 * (copied verbatim), which checks `$subject->getAudience() === Audience::OWN_CLIENTS`.
 * The Voter is the more authoritative source here since it's the explicit
 * "already written in full, don't rewrite it" artifact — this enum and the
 * corresponding Announcement field exist to make that check possible.
 */
enum Audience: string
{
    case GYM_WIDE = 'gym_wide';
    case OWN_CLIENTS = 'own_clients';
}
