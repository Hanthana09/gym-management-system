import { useEffect, useMemo, useState } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS, STAFF_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Pagination, Select, Ticket } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { useAuth } from '../auth/AuthContext'
import { useBranches } from '../branches/useBranches'
import { BranchSwitcher, defaultBranchId } from '../branches/BranchSwitcher'
import { useMembers } from '../members/useMembers'
import { useProducts } from '../retail/useProducts'
import { useProductSales } from '../retail/useProductSales'
import { usePagination } from '../lib/usePagination'
import type { PaymentMethod, ProductSaleDto } from '../retail/types'

// Explicitly requested default (other list pages default to 20) — recent
// sales is a denser, more frequently-refreshed feed, so a shorter page
// reads better here.
const PAGE_SIZE = 10

const PAYMENT_METHOD_OPTIONS: { value: PaymentMethod; label: string }[] = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'other', label: 'Other' },
]

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

function matchesSearch(sale: ProductSaleDto, query: string): boolean {
  if (query === '') return true
  const haystack = [sale.product.name, sale.soldByName, sale.paymentMethod].join(' ').toLowerCase()

  return haystack.includes(query)
}

/**
 * functional requirements §15.3 / roadmap Phase 17: Owner and Staff share
 * this screen (both have PRODUCT_SALE_CREATE/VIEW), same shared-component
 * pattern as ExpensesPage — role read from useAuth(), two routes
 * (/owner/sell, /staff/sell) render the same component. Recent sales:
 * card list on mobile/tablet, real table at lg: and up with
 * usePagination/Pagination — same card/table split as the other Phase 17
 * list pages, but a 10-row default page (PAGE_SIZE above) rather than
 * the usual 20, since this is a denser, more frequently-refreshed feed.
 * Search matches product name/sold-by name (matchesSearch above) — no
 * Member column/field is shown here at all, by request.
 */
export function RetailSalePage() {
  const { user } = useAuth()
  const isOwner = user?.role === 'owner'
  const { branches } = useBranches()
  const myBranches = isOwner ? branches : branches.filter((b) => b.assignments.some((a) => a.userId === user?.id))

  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(null)
  const effectiveBranchId = isOwner ? selectedBranchId : (selectedBranchId ?? defaultBranchId(myBranches))

  // Only active products belong in the sale picker (functional
  // requirements §15.2 — a deactivated product "stops appearing in the
  // sale quick-entry picker but past sales referencing it remain intact").
  const { products, loaded: productsLoaded } = useProducts({ isActive: true })
  const { members } = useMembers()
  // No date-range restriction — "Recent sales" shows the gym's full sale
  // history, paginated (below), not just a fixed recent window.
  const { sales, loaded: salesLoaded, createSale } = useProductSales({
    branchId: effectiveBranchId,
    from: null,
    to: null,
  })

  const saleBranchId = effectiveBranchId ?? defaultBranchId(myBranches)

  const [search, setSearch] = useState('')

  const visibleSales = useMemo(() => {
    const query = search.trim().toLowerCase()

    return sales.filter((sale) => matchesSearch(sale, query))
  }, [sales, search])

  const { page, pageCount, paged: pagedSales, rangeStart, rangeEnd, total, setPage } = usePagination(
    visibleSales,
    PAGE_SIZE,
  )

  // A branch-filter change, a search, or a newly-confirmed sale landing
  // at the top of the list, can shift what page a given row is on —
  // always land back on page 1 so a just-recorded sale is immediately
  // visible, rather than risk stranding on a now-empty page (same rule
  // as OwnerMembersPage/OwnerInvoicesPage/ExpensesPage).
  useEffect(() => {
    setPage(1)
  }, [effectiveBranchId, search, sales.length, setPage])

  return (
    <div className="h-dvh">
      <NavShell
        role={isOwner ? 'owner' : 'staff'}
        title="Gym"
        navItems={isOwner ? OWNER_NAV_ITEMS : STAFF_NAV_ITEMS}
        activeHref={isOwner ? '/owner/sell' : '/staff/sell'}
      >
        <div className="mx-auto max-w-6xl">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Sell</h1>
            {/* Absent for a single-branch gym / single-branch Staff (DESIGN-SYSTEM.md §4.2). */}
            <BranchSwitcher branches={myBranches} value={effectiveBranchId} onChange={setSelectedBranchId} allowAll={isOwner} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
            <SaleForm
              branchId={saleBranchId}
              products={products}
              productsLoaded={productsLoaded}
              members={members}
              onCreate={createSale}
            />

            <div className="lg:col-span-2">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-base font-semibold text-ink">Recent sales</h2>
                <div className="w-full sm:w-64">
                  <Input
                    label="Search"
                    placeholder="Product or sold by"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                </div>
              </div>

              {salesLoaded && sales.length === 0 ? (
                <Card>
                  <p className="py-6 text-center text-sm text-ink-soft">No sales yet.</p>
                </Card>
              ) : salesLoaded && sales.length > 0 && visibleSales.length === 0 ? (
                <Card>
                  <p className="py-6 text-center text-sm text-ink-soft">No sales match this search.</p>
                </Card>
              ) : null}

              {/* Card list — default (mobile/tablet), same pattern as OwnerMembersPage/OwnerInvoicesPage/ExpensesPage/OwnerProductsPage */}
              <ul className="flex flex-col gap-3 lg:hidden">
                {pagedSales.map((sale) => (
                  <li key={sale.id}>
                    <SaleTicket sale={sale} branches={myBranches} />
                  </li>
                ))}
              </ul>

              {/* Table — lg: and up */}
              {pagedSales.length > 0 ? (
                <table className="hidden w-full table-fixed border-separate border-spacing-0 overflow-hidden rounded-lg border border-line bg-card lg:table">
                  <thead>
                    <tr className="text-left text-sm text-ink-soft">
                      <th className="w-[20%] border-b border-line px-4 py-3">Date</th>
                      <th className="w-[28%] border-b border-line px-4 py-3">Product</th>
                      <th className="w-[12%] border-b border-line px-4 py-3">Payment</th>
                      <th className="w-[12%] border-b border-line px-4 py-3">Total</th>
                      <th className="w-[28%] border-b border-line px-4 py-3">Sold by</th>
                    </tr>
                  </thead>
                  <tbody>
                    {pagedSales.map((sale) => {
                      const branchName = myBranches.find((b) => b.id === sale.branchId)?.name ?? null

                      return (
                        <tr key={sale.id} className="text-sm text-ink">
                          <td className="border-b border-line/60 px-4 py-3 break-words text-ink-soft">
                            {formatDateTime(sale.saleDate)}
                          </td>
                          <td className="border-b border-line/60 px-4 py-3 font-medium break-words">
                            {sale.product.name} × {sale.quantity}
                          </td>
                          <td className="border-b border-line/60 px-4 py-3 text-ink-soft capitalize">{sale.paymentMethod}</td>
                          <td className="border-b border-line/60 px-4 py-3 font-mono whitespace-nowrap">${sale.totalAmount}</td>
                          <td className="border-b border-line/60 px-4 py-3 break-words text-ink-soft">
                            <p>{sale.soldByName}</p>
                            {branchName ? <p className="text-xs">{branchName}</p> : null}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              ) : null}

              <div className="mt-4">
                <Pagination
                  page={page}
                  pageCount={pageCount}
                  rangeStart={rangeStart}
                  rangeEnd={rangeEnd}
                  total={total}
                  onChange={setPage}
                />
              </div>
            </div>
          </div>
        </div>
      </NavShell>
    </div>
  )
}

function SaleTicket({ sale, branches }: { sale: ProductSaleDto; branches: { id: string; name: string }[] }) {
  // ProductSaleController::serialize() only returns branchId, not a
  // nested branch name — look it up against the page's own branch list.
  const branchName = branches.find((b) => b.id === sale.branchId)?.name ?? null

  return (
    <Ticket className="flex items-center justify-between gap-3">
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">
          {sale.product.name} × {sale.quantity}
        </p>
        <p className="text-xs text-ink-soft">
          {branchName ? `${branchName} · ` : ''}
          {sale.paymentMethod} · sold by {sale.soldByName}
        </p>
        <p className="text-xs text-ink-soft">{formatDateTime(sale.saleDate)}</p>
      </div>
      <p className="shrink-0 font-mono text-base font-semibold text-ink">${sale.totalAmount}</p>
    </Ticket>
  )
}

interface SaleFormProps {
  branchId: string | null
  products: { id: string; name: string; unitPrice: string }[]
  productsLoaded: boolean
  members: { id: string; name: string; role: string }[]
  onCreate: (input: {
    branchId: string
    productId: string
    quantity: number
    memberId?: string
    paymentMethod: PaymentMethod
  }) => Promise<ProductSaleDto>
}

/**
 * Two-step "pick → confirm" shape, same as OwnerInvoicesPage's
 * MarkPaidModal — but inline on the page rather than in a Modal, since
 * this is the screen's single primary action, not a secondary action on
 * a list row. The preview total is computed client-side from the picked
 * product's current price (`unitPrice × quantity`) purely for UX; the
 * server recomputes and records `unitPriceAtSale`/`totalAmount`
 * independently (functional requirements §15.3), and the confirmed
 * result replaces the preview once the request returns.
 */
function SaleForm({ branchId, products, productsLoaded, members, onCreate }: SaleFormProps) {
  const [productId, setProductId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [memberQuery, setMemberQuery] = useState('')
  const [memberId, setMemberId] = useState<string | null>(null)
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>('cash')
  const [step, setStep] = useState<'form' | 'confirm'>('form')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [lastSale, setLastSale] = useState<ProductSaleDto | null>(null)

  const memberMatches = useMemo(() => {
    const query = memberQuery.trim().toLowerCase()
    if (query === '') return []

    return members.filter((m) => m.role === 'member' && m.name.toLowerCase().includes(query)).slice(0, 8)
  }, [members, memberQuery])

  const selectedMember = members.find((m) => m.id === memberId) ?? null
  const selectedProduct = products.find((p) => p.id === productId) ?? null
  const numericQuantity = Number(quantity)
  const previewTotal =
    selectedProduct && numericQuantity > 0 ? (Number(selectedProduct.unitPrice) * numericQuantity).toFixed(2) : null

  function handleContinue() {
    setFieldError(null)
    setLastSale(null)

    if (!branchId) {
      setFieldError('No branch available to record this sale against.')
      return
    }
    if (!productId) {
      setFieldError('Pick a product.')
      return
    }
    if (!numericQuantity || numericQuantity <= 0 || !Number.isInteger(numericQuantity)) {
      setFieldError('Quantity must be a positive whole number.')
      return
    }

    setStep('confirm')
  }

  async function handleConfirm() {
    if (!branchId) return
    setSubmitting(true)
    setError(null)

    try {
      const sale = await onCreate({
        branchId,
        productId,
        quantity: numericQuantity,
        memberId: memberId ?? undefined,
        paymentMethod,
      })
      setLastSale(sale)
      // Reset for the next sale — front-desk quick entry, one after another.
      setProductId('')
      setQuantity('1')
      setMemberQuery('')
      setMemberId(null)
      setPaymentMethod('cash')
      setStep('form')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card>
      {step === 'form' ? (
        <div className="flex flex-col gap-4">
          <Select
            label="Product"
            value={productId}
            onChange={(e) => setProductId(e.target.value)}
            options={[
              { value: '', label: productsLoaded ? 'Select a product' : 'Loading…' },
              ...products.map((p) => ({ value: p.id, label: `${p.name} — $${p.unitPrice}` })),
            ]}
          />
          <Input
            label="Quantity"
            type="number"
            min="1"
            step="1"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
          />

          <div>
            <Input
              label="Member (optional)"
              placeholder="Search existing members — leave blank for a walk-in sale"
              value={selectedMember ? selectedMember.name : memberQuery}
              onChange={(e) => {
                setMemberId(null)
                setMemberQuery(e.target.value)
              }}
            />
            {/* functional requirements §15.3: search existing members only — no "create member" affordance here, ever. */}
            {memberQuery !== '' && !selectedMember ? (
              memberMatches.length > 0 ? (
                <ul className="mt-1.5 flex flex-col gap-1 rounded-md border border-line bg-card p-1">
                  {memberMatches.map((m) => (
                    <li key={m.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setMemberId(m.id)
                          setMemberQuery('')
                        }}
                        className="min-h-touch w-full rounded px-2 text-left text-sm text-ink hover:bg-paper-dim"
                      >
                        {m.name}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-1.5 text-xs text-ink-soft">No matching member — this will be recorded as a walk-in sale.</p>
              )
            ) : null}
            {selectedMember ? (
              <button
                type="button"
                onClick={() => setMemberId(null)}
                className="mt-1.5 text-xs font-medium text-ink-soft underline hover:text-ink"
              >
                Clear (walk-in sale)
              </button>
            ) : null}
          </div>

          <Select
            label="Payment method"
            value={paymentMethod}
            onChange={(e) => setPaymentMethod(e.target.value as PaymentMethod)}
            options={PAYMENT_METHOD_OPTIONS}
          />

          {previewTotal !== null ? (
            <p className="text-sm text-ink-soft">
              Preview total: <span className="font-mono font-semibold text-ink">${previewTotal}</span>
            </p>
          ) : null}

          {fieldError ? <p className="text-sm text-red-600">{fieldError}</p> : null}
          {lastSale ? (
            <p className="text-sm text-green-700">
              Recorded {lastSale.product.name} × {lastSale.quantity} for{' '}
              <span className="font-mono">${lastSale.totalAmount}</span>.
            </p>
          ) : null}

          <Button fullWidth onClick={handleContinue}>
            Continue
          </Button>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-sm text-ink">
            Sell <strong>{selectedProduct?.name}</strong> × {numericQuantity} to{' '}
            <strong>{selectedMember ? selectedMember.name : 'a walk-in customer'}</strong> for{' '}
            <span className="font-mono font-semibold">${previewTotal}</span> ({paymentMethod})?
          </p>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <div className="flex gap-2">
            <Button variant="secondary" fullWidth onClick={() => setStep('form')} disabled={submitting}>
              Back
            </Button>
            <Button fullWidth onClick={handleConfirm} disabled={submitting}>
              {submitting ? 'Recording…' : 'Confirm sale'}
            </Button>
          </div>
        </div>
      )}
    </Card>
  )
}
