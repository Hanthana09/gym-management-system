import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/AuthContext'
import { LoginPage } from './pages/LoginPage'
import { OtpVerifyPage } from './pages/OtpVerifyPage'
import { HomePage } from './pages/HomePage'
import { OwnerPlansPage } from './pages/OwnerPlansPage'
import { OwnerMembersPage } from './pages/OwnerMembersPage'
import { OwnerInvoicesPage } from './pages/OwnerInvoicesPage'
import { MemberInvoicesPage } from './pages/MemberInvoicesPage'
import { OwnerBulkImportPage } from './pages/OwnerBulkImportPage'
import { OwnerReferralsPage } from './pages/OwnerReferralsPage'
import { CoachReferralPage } from './pages/CoachReferralPage'
import { OwnerDashboardPage } from './pages/OwnerDashboardPage'
import { MemberCheckInPage } from './pages/MemberCheckInPage'
import { MemberSessionsPage } from './pages/MemberSessionsPage'
import { CoachSchedulePage } from './pages/CoachSchedulePage'
import { MemberTrackingPage } from './pages/MemberTrackingPage'
import { ComingSoonPage } from './pages/ComingSoonPage'
import { DevComponentsPage } from './pages/DevComponentsPage'

function RequireAuth({ children }: { children: ReactNode }) {
  const { status } = useAuth()

  if (status === 'loading') return null
  if (status === 'unauthenticated') return <Navigate to="/login" replace />

  return <>{children}</>
}

function RedirectIfAuthenticated({ children }: { children: ReactNode }) {
  const { status } = useAuth()

  if (status === 'loading') return null
  if (status === 'authenticated') return <Navigate to="/" replace />

  return <>{children}</>
}

function App() {
  return (
    <Routes>
      <Route
        path="/"
        element={
          <RequireAuth>
            <HomePage />
          </RequireAuth>
        }
      />
      <Route
        path="/login"
        element={
          <RedirectIfAuthenticated>
            <LoginPage />
          </RedirectIfAuthenticated>
        }
      />
      <Route
        path="/login/otp"
        element={
          <RedirectIfAuthenticated>
            <OtpVerifyPage />
          </RedirectIfAuthenticated>
        }
      />
      <Route
        path="/owner/plans"
        element={
          <RequireAuth>
            <OwnerPlansPage />
          </RequireAuth>
        }
      />
      <Route
        path="/owner/members"
        element={
          <RequireAuth>
            <OwnerMembersPage />
          </RequireAuth>
        }
      />
      <Route
        path="/owner/invoices"
        element={
          <RequireAuth>
            <OwnerInvoicesPage />
          </RequireAuth>
        }
      />
      <Route
        path="/member/invoices"
        element={
          <RequireAuth>
            <MemberInvoicesPage />
          </RequireAuth>
        }
      />
      <Route
        path="/owner/import"
        element={
          <RequireAuth>
            <OwnerBulkImportPage />
          </RequireAuth>
        }
      />
      <Route
        path="/owner/referrals"
        element={
          <RequireAuth>
            <OwnerReferralsPage />
          </RequireAuth>
        }
      />
      <Route
        path="/coach/refer"
        element={
          <RequireAuth>
            <CoachReferralPage />
          </RequireAuth>
        }
      />
      <Route
        path="/owner/dashboard"
        element={
          <RequireAuth>
            <OwnerDashboardPage />
          </RequireAuth>
        }
      />
      <Route
        path="/member/check-in"
        element={
          <RequireAuth>
            <MemberCheckInPage />
          </RequireAuth>
        }
      />
      <Route
        path="/member/sessions"
        element={
          <RequireAuth>
            <MemberSessionsPage />
          </RequireAuth>
        }
      />
      <Route
        path="/member/tracking"
        element={
          <RequireAuth>
            <MemberTrackingPage />
          </RequireAuth>
        }
      />
      <Route
        path="/member/notifications"
        element={
          <RequireAuth>
            <ComingSoonPage title="Notifications" activeHref="/member/notifications" />
          </RequireAuth>
        }
      />
      <Route
        path="/coach/sessions"
        element={
          <RequireAuth>
            <CoachSchedulePage />
          </RequireAuth>
        }
      />
      <Route
        path="/coach/dashboard"
        element={
          <RequireAuth>
            <ComingSoonPage title="Dashboard" activeHref="/coach/dashboard" role="coach" />
          </RequireAuth>
        }
      />
      <Route
        path="/coach/members"
        element={
          <RequireAuth>
            <ComingSoonPage title="Members" activeHref="/coach/members" role="coach" />
          </RequireAuth>
        }
      />
      <Route path="/dev/components" element={<DevComponentsPage />} />
    </Routes>
  )
}

export default App
