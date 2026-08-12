<?php

namespace App\Enum;

/**
 * Not in the original architecture doc (Phase 9 is new, GTM-driven scope
 * — see gym-management-system-development-roadmap.md Phase 9.2). A
 * minimal sales-pipeline status set; nothing here mutates it yet
 * ("credit application can be a manual/admin action for now" — roadmap).
 */
enum ReferralLeadStatus: string
{
    case NEW = 'new';
    case CONTACTED = 'contacted';
    case CONVERTED = 'converted';
    case DECLINED = 'declined';
}
