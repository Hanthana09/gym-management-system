import { Link } from 'react-router-dom'
import { NavShell } from '../components/NavShell'
import { COACH_NAV_ITEMS, MEMBER_NAV_ITEMS, OWNER_NAV_ITEMS, STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card } from '../components/ui'
import { CheckInIcon, MembersIcon } from '../components/ui/icons'
import { useAuth } from '../auth/AuthContext'
import { OwnerInvitationsPanel } from '../invitations/OwnerInvitationsPanel'
import { MyInvitationsPanel } from '../invitations/MyInvitationsPanel'
import { MyMembershipCard } from '../membership/MyMembershipCard'
import { NotificationPreferences } from '../notifications/NotificationPreferences'

const NAV_ITEMS = { owner: OWNER_NAV_ITEMS, coach: COACH_NAV_ITEMS, member: MEMBER_NAV_ITEMS, staff: STAFF_NAV_ITEMS }

/**
 * Still a placeholder landing page (a full per-role dashboard is a later
 * phase), but now carries Phase 3's invitation UI, Phase 4's membership
 * UI, and Phase 5's entry points: Owner gets a link to the live
 * attendance dashboard, Member gets a prominent Check-in link (the actual
 * check-in screen has its own bottom nav from there on). Wrapped in
 * NavShell like every other authenticated page — this is the first
 * screen after login, so skipping it here meant the sidebar/bottom-nav
 * appeared to vanish the moment you signed in.
 */
export function HomePage() {
  const { user, logout } = useAuth()
  if (!user) return null

  return (
    <div className="h-dvh">
      <NavShell role={user.role} title="Gym" navItems={NAV_ITEMS[user.role]} activeHref="/">
        <div className="mx-auto flex max-w-2xl flex-col gap-4">
          {user.role === 'owner' ? (
            <>
              <Link to="/owner/dashboard">
                <Card className="border-2 border-ink transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-ink">Dashboard</span>
                    <span className="text-sm text-ink-soft">Live attendance →</span>
                  </div>
                </Card>
              </Link>
              <OwnerInvitationsPanel />
              <Link to="/owner/members">
                <Card className="transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">Members</span>
                    <span className="text-sm text-ink-soft">View roster →</span>
                  </div>
                </Card>
              </Link>
              <Link to="/owner/import">
                <Card className="transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">Bulk Import Members</span>
                    <span className="text-sm text-ink-soft">Upload CSV →</span>
                  </div>
                </Card>
              </Link>
              <Link to="/owner/plans">
                <Card className="transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">Membership Plans</span>
                    <span className="text-sm text-ink-soft">Manage →</span>
                  </div>
                </Card>
              </Link>
              <Link to="/owner/referrals">
                <Card className="transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium text-ink">Referrals</span>
                    <span className="text-sm text-ink-soft">Your code →</span>
                  </div>
                </Card>
              </Link>
            </>
          ) : (
            <>
              <MyInvitationsPanel />
              {user.role === 'member' ? (
                <>
                  <Link to="/member/check-in">
                    <Button fullWidth className="!min-h-16 !text-base">
                      <CheckInIcon />
                      Check In
                    </Button>
                  </Link>
                  <MyMembershipCard />
                  <Link to="/member/invoices">
                    <Card className="transition-colors hover:bg-paper-dim">
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-medium text-ink">Billing</span>
                        <span className="text-sm text-ink-soft">Invoice history →</span>
                      </div>
                    </Card>
                  </Link>
                </>
              ) : null}
              {user.role === 'coach' ? (
                <>
                  <Link to="/coach/sessions">
                    <Card className="border-2 border-ink transition-colors hover:bg-paper-dim">
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-semibold text-ink">My schedule</span>
                        <span className="text-sm text-ink-soft">Session requests →</span>
                      </div>
                    </Card>
                  </Link>
                  <Link to="/coach/refer">
                    <Card className="transition-colors hover:bg-paper-dim">
                      <div className="flex items-center justify-between">
                        <span className="text-sm font-medium text-ink">Recommend this to another gym</span>
                        <span className="text-sm text-ink-soft">→</span>
                      </div>
                    </Card>
                  </Link>
                </>
              ) : null}
              {user.role === 'staff' ? (
                <Link to="/staff/members">
                  <Card className="border-2 border-ink transition-colors hover:bg-paper-dim">
                    <div className="flex items-center justify-between">
                      <span className="flex items-center gap-2 text-sm font-semibold text-ink">
                        <MembersIcon />
                        Members
                      </span>
                      <span className="text-sm text-ink-soft">View & check in →</span>
                    </div>
                  </Card>
                </Link>
              ) : null}
            </>
          )}

          <Card>
            <div className="text-center">
              <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Signed in</h1>
              <p className="mt-1 text-sm text-ink-soft">
                {user.name} · {user.email ?? user.phone} · <span className="capitalize">{user.role}</span>
              </p>
            </div>
            <div className="mt-4">
              <NotificationPreferences />
            </div>
            <Button className="mt-4" fullWidth variant="secondary" onClick={logout}>
              Log out
            </Button>
          </Card>
        </div>
      </NavShell>
    </div>
  )
}
