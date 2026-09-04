import type { NavItem } from './NavShell'
import {
  BellIcon,
  BillingIcon,
  BranchIcon,
  CheckInIcon,
  DashboardIcon,
  ExpenseIcon,
  FinanceIcon,
  HomeIcon,
  MembersIcon,
  PlansIcon,
  ProductIcon,
  SellIcon,
  SessionsIcon,
  SettingsIcon,
  TrackingIcon,
  WorkoutIcon,
} from './ui/icons'

// Per roadmap Phase 1: Member's four bottom-tab items. Check-in is
// already the first tab and doubles as Member's "home" — no separate
// Home entry needed here (this is the sidebar-menu request's scope,
// not the bottom tab bar).
export const MEMBER_NAV_ITEMS: NavItem[] = [
  { label: 'Check-in', href: '/member/check-in', icon: <CheckInIcon /> },
  { label: 'Sessions', href: '/member/sessions', icon: <SessionsIcon /> },
  { label: 'Workouts', href: '/member/workouts', icon: <WorkoutIcon /> },
  { label: 'Tracking', href: '/member/tracking', icon: <TrackingIcon /> },
  { label: 'Notifications', href: '/member/notifications', icon: <BellIcon /> },
]

// "/" (HomePage) was previously unreachable from the sidebar once an
// Owner/Coach/Staff navigated away from it — NavShell's header title
// isn't a link, so this was the only way back short of the browser's
// own back button.
export const OWNER_NAV_ITEMS: NavItem[] = [
  { label: 'Home', href: '/', icon: <HomeIcon /> },
  { label: 'Branches', href: '/owner/branches', icon: <BranchIcon /> },
  { label: 'Members', href: '/owner/members', icon: <MembersIcon /> },
  { label: 'Plans', href: '/owner/plans', icon: <PlansIcon /> },
  { label: 'Billing', href: '/owner/invoices', icon: <BillingIcon /> },
  // roadmap Phase 17: Finance (the financial-summary dashboard) sits
  // alongside Billing/Dashboard rather than nested under either — it's
  // its own ReportVoter::VIEW-gated Owner-only screen, not a Billing
  // sub-view (Billing stays Invoice-only, per §6.13's exclusion note).
  { label: 'Finance', href: '/owner/finance', icon: <FinanceIcon /> },
  { label: 'Expenses', href: '/owner/expenses', icon: <ExpenseIcon /> },
  { label: 'Products', href: '/owner/products', icon: <ProductIcon /> },
  { label: 'Sell', href: '/owner/sell', icon: <SellIcon /> },
  { label: 'Settings', href: '/owner/settings', icon: <SettingsIcon /> },
]

export const COACH_NAV_ITEMS: NavItem[] = [
  { label: 'Home', href: '/', icon: <HomeIcon /> },
  { label: 'Dashboard', href: '/coach/dashboard', icon: <DashboardIcon /> },
  { label: 'Sessions', href: '/coach/sessions', icon: <SessionsIcon /> },
  { label: 'Workouts', href: '/coach/workout-schedules', icon: <WorkoutIcon /> },
  { label: 'Members', href: '/coach/members', icon: <MembersIcon /> },
]

// roadmap Phase 15.1: Staff's whole app is this one screen — a
// scoped-down Owner view (read-only member list + check-in action),
// not a full Owner-style multi-section nav. Still gets Home for the
// same "was previously unreachable" reason as Owner/Coach above.
// roadmap Phase 17 adds exactly two more entries, matching Staff's
// actual new permissions (EXPENSE_CREATE/VIEW, PRODUCT_SALE_CREATE/VIEW,
// own branch(es) only) and nothing wider — no Products (catalog writes
// are Owner-only; Staff only sees products inside the Sell picker) and
// no Finance (ReportVoter::VIEW excludes Staff entirely).
export const STAFF_NAV_ITEMS: NavItem[] = [
  { label: 'Home', href: '/', icon: <HomeIcon /> },
  { label: 'Members', href: '/staff/members', icon: <MembersIcon /> },
  { label: 'Expenses', href: '/staff/expenses', icon: <ExpenseIcon /> },
  { label: 'Sell', href: '/staff/sell', icon: <SellIcon /> },
]
