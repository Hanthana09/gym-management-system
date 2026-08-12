import { CheckInIcon } from '../components/ui/icons'

interface MilestoneShareCardProps {
  memberName: string
  streakDays: number
}

/**
 * roadmap Phase 9.3: "must use the Badge/Ticket visual patterns... look
 * like it belongs to the same product as the membership badge, not a
 * generic achievement graphic." DESIGN-SYSTEM.md §4 otherwise reserves
 * the Badge pattern for the Member's membership screen "and nowhere
 * else" — this card is the one deliberate, task-directed exception,
 * so it borrows the pattern's visual language (role-color top stripe,
 * font-mono for the headline number, rounded-xl) rather than literally
 * re-mounting the shared <Badge> component, combined with Ticket's
 * dashed-border/punch-hole framing (a share card is also a keepsake of
 * a single event, the same way a Ticket is).
 */
export function MilestoneShareCard({ memberName, streakDays }: MilestoneShareCardProps) {
  return (
    <div className="relative w-80 overflow-hidden rounded-xl border-2 border-dashed border-line bg-card">
      <span
        aria-hidden="true"
        className="absolute top-1/2 -left-2.5 h-5 w-5 -translate-y-1/2 rounded-full border-2 border-dashed border-line bg-paper"
      />
      <span
        aria-hidden="true"
        className="absolute top-1/2 -right-2.5 h-5 w-5 -translate-y-1/2 rounded-full border-2 border-dashed border-line bg-paper"
      />
      <div className="h-2.5 w-full bg-member" aria-hidden="true" />
      <div className="flex flex-col items-center gap-1 p-6 text-center">
        <p className="font-display text-xs font-semibold tracking-wide text-ink-soft uppercase">Gym</p>
        <CheckInIcon className="mt-2 h-8 w-8 text-member" />
        <p className="font-mono text-6xl font-bold text-ink">{streakDays}</p>
        <p className="font-display text-sm font-semibold tracking-wide text-ink uppercase">Day Check-in Streak</p>
        <p className="mt-3 text-sm text-ink-soft">{memberName}</p>
      </div>
    </div>
  )
}
