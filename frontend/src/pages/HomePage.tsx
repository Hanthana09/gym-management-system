import { Link } from 'react-router-dom'
import { Button, Card } from '../components/ui'
import { CheckInIcon } from '../components/ui/icons'
import { useAuth } from '../auth/AuthContext'
import { OwnerInvitationsPanel } from '../invitations/OwnerInvitationsPanel'
import { MyInvitationsPanel } from '../invitations/MyInvitationsPanel'
import { MyMembershipCard } from '../membership/MyMembershipCard'

/**
 * Still a placeholder landing page (a full per-role dashboard is a later
 * phase), but now carries Phase 3's invitation UI, Phase 4's membership
 * UI, and Phase 5's entry points: Owner gets a link to the live
 * attendance dashboard, Member gets a prominent Check-in link (the actual
 * check-in screen has its own bottom nav from there on).
 */
export function HomePage() {
  const { user, logout } = useAuth()
  if (!user) return null

  return (
    <div className="min-h-dvh bg-paper px-4 py-6">
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
            <Link to="/owner/plans">
              <Card className="transition-colors hover:bg-paper-dim">
                <div className="flex items-center justify-between">
                  <span className="text-sm font-medium text-ink">Membership Plans</span>
                  <span className="text-sm text-ink-soft">Manage →</span>
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
              </>
            ) : null}
            {user.role === 'coach' ? (
              <Link to="/coach/sessions">
                <Card className="border-2 border-ink transition-colors hover:bg-paper-dim">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-ink">My schedule</span>
                    <span className="text-sm text-ink-soft">Session requests →</span>
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
          <Button className="mt-4" fullWidth variant="secondary" onClick={logout}>
            Log out
          </Button>
        </Card>
      </div>
    </div>
  )
}
