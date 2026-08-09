import { useState, type ReactNode } from 'react'
import { Badge, Button, Card, Input, Modal, Select, Ticket } from '../components/ui'
import { NavShell } from '../components/NavShell'
import { MEMBER_NAV_ITEMS, OWNER_NAV_ITEMS } from '../components/nav-items'

/**
 * Component-library preview (roadmap Phase 1 Definition of Done):
 * renders every shared primitive so it can be checked at 375px, 768px,
 * and 1280px without needing Storybook. Retrofitted to DESIGN-SYSTEM.md's
 * tokens/patterns.
 */
export function DevComponentsPage() {
  const [modalOpen, setModalOpen] = useState(false)

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
      <header className="mb-8">
        <h1 className="font-display text-2xl font-semibold tracking-wide text-ink uppercase">
          Component Library
        </h1>
        <p className="mt-1 text-sm text-ink-soft">
          Resize the viewport to 375px / 768px / 1280px to check each primitive.
        </p>
      </header>

      <Section title="Button">
        <div className="flex flex-wrap gap-3">
          <Button variant="primary">Primary</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="ghost">Ghost</Button>
          <Button variant="danger">Danger</Button>
          <Button variant="hivis">Hivis</Button>
          <Button variant="primary" disabled>
            Disabled
          </Button>
          <Button variant="secondary" iconOnly aria-label="Icon only example">
            +
          </Button>
        </div>
        <Button className="mt-3" fullWidth>
          Full width
        </Button>
      </Section>

      <Section title="Input">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Input label="Email" type="email" placeholder="you@example.com" />
          <Input label="With hint" hint="We'll never share this." placeholder="Hint example" />
          <Input label="With error" error="This field is required." placeholder="Error example" />
          <Input label="Disabled" placeholder="Disabled example" disabled />
        </div>
      </Section>

      <Section title="Select">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Select
            label="Role"
            options={[
              { value: 'member', label: 'Member' },
              { value: 'coach', label: 'Coach' },
              { value: 'owner', label: 'Owner' },
            ]}
          />
          <Select
            label="With error"
            error="Please choose an option."
            options={[{ value: '', label: 'Select…' }]}
          />
        </div>
      </Section>

      <Section title="Card">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Card title="Card with title">
            <p className="text-sm text-ink-soft">Basic card content goes here.</p>
          </Card>
          <Card>
            <p className="text-sm text-ink-soft">Card without a title prop.</p>
          </Card>
        </div>
      </Section>

      <Section title="Ticket">
        <p className="mb-3 text-sm text-ink-soft">
          Discrete, scannable "events" — attendance rows, invitation rows, PT session rows.
        </p>
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
          <Ticket>
            <p className="text-sm font-semibold text-ink">Mia Member</p>
            <p className="font-mono text-xs text-ink-soft">Aug 8, 11:24 PM · manual</p>
          </Ticket>
          <Ticket>
            <p className="text-sm font-semibold text-ink">invitee@example.com</p>
            <p className="text-xs text-ink-soft capitalize">Coach · pending</p>
          </Ticket>
        </div>
      </Section>

      <Section title="Badge (ID card)">
        <p className="mb-3 text-sm text-ink-soft">
          Reserved for exactly one screen — Member's "My membership" (Phase 4).
        </p>
        <div className="max-w-sm">
          <Badge role="member" name="Mia Member" badgeNumber="#A17F-2291">
            <p className="text-sm font-semibold text-ink">Gold</p>
            <p className="text-sm text-ink-soft">$79.99 / 30 days</p>
          </Badge>
        </div>
      </Section>

      <Section title="Modal / BottomSheet">
        <p className="mb-3 text-sm text-ink-soft">
          Bottom sheet below <code>md:</code>, centered dialog from <code>md:</code> up.
        </p>
        <Button onClick={() => setModalOpen(true)}>Open modal</Button>
        <Modal
          open={modalOpen}
          onClose={() => setModalOpen(false)}
          title="Example Modal"
          footer={
            <>
              <Button variant="secondary" onClick={() => setModalOpen(false)}>
                Cancel
              </Button>
              <Button onClick={() => setModalOpen(false)}>Confirm</Button>
            </>
          }
        >
          <p className="text-sm text-ink-soft">
            This is a bottom sheet on mobile and a centered dialog at <code>md:</code> and up.
          </p>
        </Modal>
      </Section>

      <Section title="NavShell — Member">
        <p className="mb-3 text-sm text-ink-soft">
          Bottom tab bar below <code>lg:</code>, sidebar from <code>lg:</code> up.
        </p>
        <div className="h-[560px] overflow-hidden rounded-lg border border-line">
          <NavShell
            role="member"
            title="Gym"
            navItems={MEMBER_NAV_ITEMS}
            activeHref="/member/check-in"
          >
            <p className="text-sm text-ink-soft">Page content area.</p>
          </NavShell>
        </div>
      </Section>

      <Section title="NavShell — Owner / Coach">
        <p className="mb-3 text-sm text-ink-soft">
          Sidebar from <code>md:</code> up; hamburger-triggered drawer below <code>md:</code>.
        </p>
        <div className="h-[560px] overflow-hidden rounded-lg border border-line">
          <NavShell
            role="owner"
            title="Gym"
            navItems={OWNER_NAV_ITEMS}
            activeHref="/owner/dashboard"
          >
            <p className="text-sm text-ink-soft">Page content area.</p>
          </NavShell>
        </div>
      </Section>
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="mb-10">
      <h2 className="mb-3 text-sm font-semibold tracking-wide text-ink-soft uppercase">{title}</h2>
      {children}
    </section>
  )
}
