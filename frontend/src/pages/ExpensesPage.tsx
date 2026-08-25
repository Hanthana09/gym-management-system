import { useEffect, useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS, STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Select, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useAuth } from '../auth/AuthContext'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'
import { useExpenseCategories } from '../expenses/useExpenseCategories'
import { useExpenses } from '../expenses/useExpenses'
import type { ExpenseDto, ExpenseInput } from '../expenses/types'

// architecture doc §5.1: EXPENSE.currency "default LKR".
const DEFAULT_CURRENCY = 'LKR'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function daysAgo(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)

  return date.toISOString().slice(0, 10)
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' })
}

/**
 * functional requirements §15.1 / roadmap Phase 17: Owner and Staff share
 * this screen (both have EXPENSE_CREATE/VIEW) — Owner also gets
 * EXPENSE_MANAGE (edit/delete), Staff doesn't. Same "role-based UI
 * hiding at the component level, not a route guard" pattern as every
 * other page in this app (see StaffDashboardPage): the two routes
 * (/owner/expenses, /staff/expenses) both render this component, which
 * reads its own role from useAuth() to pick nav items and which actions
 * to show. Coach/Member never get a route to this component at all, and
 * the server 403s regardless if either bypasses the UI.
 */
export function ExpensesPage() {
  const { user } = useAuth()
  const isOwner = user?.role === 'owner'
  const { branches } = useBranches()
  const myBranches = isOwner ? branches : branches.filter((b) => b.assignments.some((a) => a.userId === user?.id))

  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)
  // Owner: null = gym-wide rollup (functional requirements §14.5's
  // default). Staff: always scoped to one of their own branches, same
  // default rule as StaffDashboardPage's check-in branch picker.
  const effectiveBranchId = isOwner ? selectedBranchId : (selectedBranchId ?? defaultBranchId(myBranches))

  const [categoryId, setCategoryId] = useState('')
  const [from, setFrom] = useState(daysAgo(30))
  const [to, setTo] = useState(today())

  const { categories, createCategory, deleteCategory } = useExpenseCategories()
  const { expenses, loaded, createExpense, updateExpense, deleteExpense } = useExpenses({
    branchId: effectiveBranchId,
    categoryId: categoryId || null,
    from,
    to,
  })

  const [formState, setFormState] = useState<'new' | ExpenseDto | null>(null)
  const [categoryManagerOpen, setCategoryManagerOpen] = useState(false)
  const [confirmingDeleteId, setConfirmingDeleteId] = useState<string | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)

  async function handleDelete(id: string) {
    setDeleteError(null)
    setBusyId(id)
    try {
      await deleteExpense(id)
      setConfirmingDeleteId(null)
    } catch (err) {
      setDeleteError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="h-dvh">
      <NavShell
        role={isOwner ? 'owner' : 'staff'}
        title="Gym"
        navItems={isOwner ? OWNER_NAV_ITEMS : STAFF_NAV_ITEMS}
        activeHref={isOwner ? '/owner/expenses' : '/staff/expenses'}
      >
        <div className="mx-auto flex max-w-6xl flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <h1 className="truncate font-display text-lg font-semibold tracking-wide text-ink uppercase">
                Expenses
              </h1>
              {/* Absent entirely for a single-branch gym / a Staff member assigned to just one branch (DESIGN-SYSTEM.md §4.2). Owner gets "All branches"; Staff never does. */}
              <BranchSwitcher
                branches={myBranches}
                value={effectiveBranchId}
                onChange={setSelectedBranchId}
                allowAll={isOwner}
              />
            </div>
            <div className="flex gap-2">
              {isOwner ? (
                <Button variant="secondary" onClick={() => setCategoryManagerOpen(true)}>
                  Categories
                </Button>
              ) : null}
              <Button onClick={() => setFormState('new')}>New expense</Button>
            </div>
          </div>

          <Card>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Select
                label="Category"
                value={categoryId}
                onChange={(e) => setCategoryId(e.target.value)}
                options={[{ value: '', label: 'All categories' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]}
              />
              <Input label="From" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
              <Input label="To" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
          </Card>

          {deleteError ? <p className="text-sm text-red-600">{deleteError}</p> : null}

          {loaded && expenses.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No expenses in this range.</p>
            </Card>
          ) : null}

          <ul className="grid grid-cols-1 gap-3 xl:grid-cols-2">
            {expenses.map((expense) => (
              <li key={expense.id}>
                <Ticket className="flex flex-col gap-2">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-ink">{expense.category.name}</p>
                      <p className="text-xs text-ink-soft">
                        {expense.branchName} · {formatDate(expense.expenseDate)}
                      </p>
                    </div>
                    <p className="shrink-0 font-mono text-base font-semibold text-ink">
                      {expense.currency} {expense.amount}
                    </p>
                  </div>
                  {expense.description ? <p className="text-sm text-ink-soft">{expense.description}</p> : null}
                  <div className="flex items-center justify-between gap-3">
                    <p className="text-xs text-ink-soft">
                      Recorded by {expense.recordedByName}
                      {expense.receiptUrl ? (
                        <>
                          {' · '}
                          <a href={expense.receiptUrl} target="_blank" rel="noreferrer" className="underline">
                            Receipt
                          </a>
                        </>
                      ) : null}
                    </p>
                    {isOwner ? (
                      confirmingDeleteId === expense.id ? (
                        <div className="flex items-center gap-2">
                          <Button variant="secondary" onClick={() => setConfirmingDeleteId(null)}>
                            Cancel
                          </Button>
                          <Button
                            variant="danger"
                            disabled={busyId === expense.id}
                            onClick={() => handleDelete(expense.id)}
                          >
                            {busyId === expense.id ? 'Deleting…' : 'Confirm delete'}
                          </Button>
                        </div>
                      ) : (
                        <div className="flex items-center gap-2">
                          <Button variant="secondary" onClick={() => setFormState(expense)}>
                            Edit
                          </Button>
                          <Button
                            variant="danger"
                            onClick={() => {
                              setDeleteError(null)
                              setConfirmingDeleteId(expense.id)
                            }}
                          >
                            Delete
                          </Button>
                        </div>
                      )
                    ) : null}
                  </div>
                </Ticket>
              </li>
            ))}
          </ul>
        </div>

        <ExpenseFormModal
          open={formState !== null}
          expense={formState === 'new' ? null : formState}
          branches={myBranches}
          defaultBranchId={effectiveBranchId ?? defaultBranchId(myBranches)}
          categories={categories}
          onClose={() => setFormState(null)}
          onCreate={createExpense}
          onUpdate={updateExpense}
        />

        {isOwner ? (
          <CategoryManagerModal
            open={categoryManagerOpen}
            categories={categories}
            onClose={() => setCategoryManagerOpen(false)}
            onCreate={createCategory}
            onDelete={deleteCategory}
          />
        ) : null}
      </NavShell>
    </div>
  )
}

interface ExpenseFormModalProps {
  open: boolean
  expense: ExpenseDto | null
  branches: { id: string; name: string }[]
  defaultBranchId: string | null
  categories: { id: string; name: string }[]
  onClose: () => void
  onCreate: (input: ExpenseInput, receiptFile?: File | null) => Promise<ExpenseDto>
  onUpdate: (id: string, input: ExpenseInput) => Promise<ExpenseDto>
}

/**
 * Two-step "fill → confirm" shape, same as OwnerInvoicesPage's
 * MarkPaidModal — expense recording is a financial action worth one
 * deliberate confirm tap, not a plain single-submit form.
 */
function ExpenseFormModal({
  open,
  expense,
  branches,
  defaultBranchId,
  categories,
  onClose,
  onCreate,
  onUpdate,
}: ExpenseFormModalProps) {
  const [branchId, setBranchId] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [amount, setAmount] = useState('')
  const [currency, setCurrency] = useState(DEFAULT_CURRENCY)
  const [description, setDescription] = useState('')
  const [expenseDate, setExpenseDate] = useState(today())
  const [receiptFile, setReceiptFile] = useState<File | null>(null)
  const [step, setStep] = useState<'form' | 'confirm'>('form')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  // Re-seed every time the modal opens, not just when `expense` changes —
  // otherwise reopening "New expense" after typing something and closing
  // without saving would show the stale draft (same fix as OwnerPlansPage).
  const [wasOpen, setWasOpen] = useState(false)
  if (open && !wasOpen) {
    setWasOpen(true)
    setBranchId(expense?.branchId ?? defaultBranchId ?? '')
    setCategoryId(expense?.category.id ?? '')
    setAmount(expense?.amount ?? '')
    setCurrency(expense?.currency ?? DEFAULT_CURRENCY)
    setDescription(expense?.description ?? '')
    setExpenseDate(expense?.expenseDate.slice(0, 10) ?? today())
    setReceiptFile(null)
    setStep('form')
    setFieldError(null)
    setError(null)
  } else if (!open && wasOpen) {
    setWasOpen(false)
  }

  function handleContinue(event: FormEvent) {
    event.preventDefault()
    setFieldError(null)

    // functional requirements §15.1: positive amount, category + branch
    // required — validated client-side, still enforced server-side too.
    if (!branchId || !categoryId) {
      setFieldError('Branch and category are required.')
      return
    }
    const numericAmount = Number(amount)
    if (!amount || Number.isNaN(numericAmount) || numericAmount <= 0) {
      setFieldError('Amount must be a positive number.')
      return
    }
    if (!expenseDate) {
      setFieldError('Date is required.')
      return
    }

    setStep('confirm')
  }

  async function handleConfirm() {
    setSubmitting(true)
    setError(null)

    const input: ExpenseInput = { branchId, categoryId, amount, currency, description, expenseDate }

    try {
      if (expense) {
        await onUpdate(expense.id, input)
      } else {
        await onCreate(input, receiptFile)
      }
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  const categoryName = categories.find((c) => c.id === categoryId)?.name ?? ''
  const branchName = branches.find((b) => b.id === branchId)?.name ?? ''

  return (
    <Modal open={open} onClose={onClose} title={expense ? 'Edit expense' : 'New expense'}>
      {step === 'form' ? (
        <form className="flex flex-col gap-4" onSubmit={handleContinue}>
          <Select
            label="Branch"
            value={branchId}
            onChange={(e) => setBranchId(e.target.value)}
            options={branches.map((b) => ({ value: b.id, label: b.name }))}
            required
          />
          <Select
            label="Category"
            value={categoryId}
            onChange={(e) => setCategoryId(e.target.value)}
            options={[{ value: '', label: 'Select a category' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]}
            required
          />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Input
              label="Amount"
              type="number"
              step="0.01"
              min="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              required
            />
            <Input
              label="Currency"
              value={currency}
              maxLength={3}
              onChange={(e) => setCurrency(e.target.value.toUpperCase())}
              required
            />
          </div>
          <Input label="Description" value={description} onChange={(e) => setDescription(e.target.value)} required />
          <Input label="Date" type="date" value={expenseDate} onChange={(e) => setExpenseDate(e.target.value)} required />

          <div>
            <label htmlFor="receipt-upload" className="mb-1.5 block text-sm font-medium text-ink">
              Receipt
            </label>
            {expense ? (
              // ExpenseController::update() is JSON-only — there's no
              // receipt-replacement support on edit, so this is
              // view-only once an expense already exists.
              expense.receiptUrl ? (
                <p className="text-xs text-ink-soft">
                  <a href={expense.receiptUrl} target="_blank" rel="noreferrer" className="underline">
                    View attached receipt
                  </a>
                </p>
              ) : (
                <p className="text-xs text-ink-soft">No receipt attached.</p>
              )
            ) : (
              <input
                id="receipt-upload"
                type="file"
                accept="image/png,image/jpeg,image/webp,application/pdf"
                onChange={(e) => setReceiptFile(e.target.files?.[0] ?? null)}
                className="min-h-touch w-full text-sm text-ink file:mr-3 file:rounded-md file:border-0 file:bg-paper-dim file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink"
              />
            )}
          </div>

          {fieldError ? <p className="text-sm text-red-600">{fieldError}</p> : null}
          <Button type="submit" fullWidth>
            Continue
          </Button>
        </form>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink">
            Record{' '}
            <span className="font-mono font-semibold">
              {currency} {amount}
            </span>{' '}
            for <strong>{categoryName}</strong> at <strong>{branchName}</strong> on {expenseDate}?
          </p>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <div className="flex gap-2">
            <Button variant="secondary" fullWidth onClick={() => setStep('form')} disabled={submitting}>
              Back
            </Button>
            <Button fullWidth onClick={handleConfirm} disabled={submitting}>
              {submitting ? 'Saving…' : 'Confirm'}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

interface CategoryManagerModalProps {
  open: boolean
  categories: { id: string; name: string }[]
  onClose: () => void
  onCreate: (name: string) => Promise<unknown>
  onDelete: (id: string) => Promise<void>
}

/**
 * Delete mirrors the two-step confirm shown on each expense row in the
 * main list — a financial-config action, not a plain destructive click.
 * The server blocks (409 `category_has_expenses`) a category still
 * referenced by an Expense; that error surfaces here per-row rather than
 * as a generic failure, so "why can't I delete this" is self-explanatory.
 */
function CategoryManagerModal({ open, categories, onClose, onCreate, onDelete }: CategoryManagerModalProps) {
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [confirmingId, setConfirmingId] = useState<string | null>(null)
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setName('')
      setError(null)
      setConfirmingId(null)
      setDeleteError(null)
    }
  }, [open])

  async function handleAdd(event: FormEvent) {
    event.preventDefault()
    if (name.trim() === '') return
    setSubmitting(true)
    setError(null)
    try {
      await onCreate(name.trim())
      setName('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(id: string) {
    setDeleteError(null)
    setDeletingId(id)
    try {
      await onDelete(id)
      setConfirmingId(null)
    } catch (err) {
      setDeleteError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Expense categories">
      <div className="flex flex-col gap-4">
        {categories.length === 0 ? (
          <p className="text-sm text-ink-soft">No categories yet — add your first one below.</p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {categories.map((category) => (
              <li key={category.id} className="flex items-center justify-between gap-2 text-sm text-ink">
                <span className="min-w-0 truncate">{category.name}</span>
                {confirmingId === category.id ? (
                  <div className="flex shrink-0 items-center gap-2">
                    <Button variant="secondary" onClick={() => setConfirmingId(null)}>
                      Cancel
                    </Button>
                    <Button variant="danger" disabled={deletingId === category.id} onClick={() => handleDelete(category.id)}>
                      {deletingId === category.id ? 'Deleting…' : 'Confirm'}
                    </Button>
                  </div>
                ) : (
                  <Button variant="secondary" onClick={() => setConfirmingId(category.id)}>
                    Delete
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
        {deleteError ? <p className="text-sm text-red-600">{deleteError}</p> : null}
        <form className="flex items-end gap-2" onSubmit={handleAdd}>
          <div className="flex-1">
            <Input label="New category" placeholder="e.g. Rent" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <Button type="submit" disabled={submitting || name.trim() === ''}>
            Add
          </Button>
        </form>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
      </div>
    </Modal>
  )
}
