import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { NavShell } from '../components/NavShell'
import { OWNER_NAV_ITEMS } from '../components/nav-items'
import { Button, Card, Input, Modal, Pagination, Select } from '../components/ui'
import { ApiError } from '../lib/apiClient'
import { usePagination } from '../lib/usePagination'
import { useProductCategories } from '../retail/useProductCategories'
import { useProducts } from '../retail/useProducts'
import type { ProductDto, ProductInput } from '../retail/types'

const PAGE_SIZE = 20

function matchesSearch(product: ProductDto, query: string): boolean {
  if (query === '') return true
  const haystack = [product.name, product.sku, product.category.name].filter(Boolean).join(' ').toLowerCase()

  return haystack.includes(query)
}

function Pill({ label, styles }: { label: string; styles: string }) {
  return (
    <span className={`rounded-full px-2 py-0.5 font-mono text-xs tracking-wide uppercase ${styles}`}>{label}</span>
  )
}

/**
 * functional requirements §15.2 / roadmap Phase 17: Owner-only product
 * catalog CRUD (create/edit/deactivate) — reuses the Card/Modal shape
 * from OwnerPlansPage/OwnerBranchesPage, no new visual patterns. Staff
 * never reaches this screen at all (no nav entry, no route) — they only
 * ever see products read-only, inside the Sell picker on RetailSalePage.
 * No unit_cost/margin field anywhere here, on purpose (§6.13).
 */
export function OwnerProductsPage() {
  const { categories, createCategory, deleteCategory } = useProductCategories()
  const [categoryFilter, setCategoryFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all')
  const { products, loaded, createProduct, updateProduct } = useProducts({
    categoryId: categoryFilter || null,
    isActive: statusFilter === 'all' ? null : statusFilter === 'active',
  })
  const [search, setSearch] = useState('')
  const [editingProduct, setEditingProduct] = useState<ProductDto | 'new' | null>(null)
  const [categoryManagerOpen, setCategoryManagerOpen] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [busyId, setBusyId] = useState<string | null>(null)

  const visibleProducts = useMemo(() => {
    const query = search.trim().toLowerCase()

    return products.filter((product) => matchesSearch(product, query))
  }, [products, search])

  const { page, pageCount, paged: pagedProducts, rangeStart, rangeEnd, total, setPage } = usePagination(
    visibleProducts,
    PAGE_SIZE,
  )

  useEffect(() => {
    setPage(1)
  }, [search, categoryFilter, statusFilter, setPage])

  async function handleToggleActive(product: ProductDto) {
    setStatusError(null)
    setBusyId(product.id)
    try {
      await updateProduct(product.id, { isActive: !product.isActive })
    } catch (err) {
      setStatusError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setBusyId(null)
    }
  }

  return (
    <div className="h-dvh">
      <NavShell role="owner" title="Gym" navItems={OWNER_NAV_ITEMS} activeHref="/owner/products">
        <div className="mx-auto max-w-2xl">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 className="font-display text-lg font-semibold tracking-wide text-ink uppercase">Products</h1>
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setCategoryManagerOpen(true)}>
                Categories
              </Button>
              <Button onClick={() => setEditingProduct('new')}>New product</Button>
            </div>
          </div>

          <Card className="mb-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Input
                label="Search"
                placeholder="Name or SKU"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
              <Select
                label="Category"
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                options={[{ value: '', label: 'All categories' }, ...categories.map((c) => ({ value: c.id, label: c.name }))]}
              />
              <Select
                label="Status"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value as 'all' | 'active' | 'inactive')}
                options={[
                  { value: 'all', label: 'All' },
                  { value: 'active', label: 'Active' },
                  { value: 'inactive', label: 'Inactive' },
                ]}
              />
            </div>
          </Card>

          {statusError ? <p className="mb-3 text-sm text-red-600">{statusError}</p> : null}

          {loaded && products.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No products yet.</p>
            </Card>
          ) : loaded && visibleProducts.length === 0 ? (
            <Card>
              <p className="py-6 text-center text-sm text-ink-soft">No products match this filter.</p>
            </Card>
          ) : null}

          <div className="flex flex-col gap-3">
            {pagedProducts.map((product) => (
              <Card key={product.id}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-ink">{product.name}</p>
                    <p className="text-sm text-ink-soft">
                      {product.category.name}
                      {product.sku ? ` · SKU ${product.sku}` : ''}
                    </p>
                    <p className="mt-1 font-mono text-sm text-ink">${product.unitPrice}</p>
                  </div>
                  <Pill
                    label={product.isActive ? 'Active' : 'Inactive'}
                    styles={product.isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}
                  />
                </div>
                <div className="mt-3 flex gap-2">
                  <Button variant="secondary" fullWidth onClick={() => setEditingProduct(product)}>
                    Edit
                  </Button>
                  <Button
                    variant={product.isActive ? 'danger' : 'secondary'}
                    fullWidth
                    disabled={busyId === product.id}
                    onClick={() => handleToggleActive(product)}
                  >
                    {busyId === product.id ? 'Saving…' : product.isActive ? 'Deactivate' : 'Activate'}
                  </Button>
                </div>
              </Card>
            ))}
          </div>

          <div className="mt-4">
            <Pagination page={page} pageCount={pageCount} rangeStart={rangeStart} rangeEnd={rangeEnd} total={total} onChange={setPage} />
          </div>

          <ProductFormModal
            open={editingProduct !== null}
            product={editingProduct === 'new' ? null : editingProduct}
            categories={categories}
            onClose={() => setEditingProduct(null)}
            onCreate={createProduct}
            onUpdate={updateProduct}
          />

          <CategoryManagerModal
            open={categoryManagerOpen}
            categories={categories}
            onClose={() => setCategoryManagerOpen(false)}
            onCreate={createCategory}
            onDelete={deleteCategory}
          />
        </div>
      </NavShell>
    </div>
  )
}

interface ProductFormModalProps {
  open: boolean
  product: ProductDto | null
  categories: { id: string; name: string }[]
  onClose: () => void
  onCreate: (input: ProductInput) => Promise<ProductDto>
  onUpdate: (id: string, changes: Partial<ProductInput>) => Promise<ProductDto>
}

function ProductFormModal({ open, product, categories, onClose, onCreate, onUpdate }: ProductFormModalProps) {
  const [categoryId, setCategoryId] = useState(product?.category.id ?? '')
  const [name, setName] = useState(product?.name ?? '')
  const [sku, setSku] = useState(product?.sku ?? '')
  const [unitPrice, setUnitPrice] = useState(product?.unitPrice ?? '')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const [wasOpen, setWasOpen] = useState(false)
  if (open && !wasOpen) {
    setWasOpen(true)
    setCategoryId(product?.category.id ?? categories[0]?.id ?? '')
    setName(product?.name ?? '')
    setSku(product?.sku ?? '')
    setUnitPrice(product?.unitPrice ?? '')
    setError(null)
  } else if (!open && wasOpen) {
    setWasOpen(false)
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)

    if (!categoryId) {
      setError('Category is required.')
      return
    }
    const numericPrice = Number(unitPrice)
    if (!unitPrice || Number.isNaN(numericPrice) || numericPrice <= 0) {
      setError('Unit price must be a positive number.')
      return
    }

    setSubmitting(true)
    const input: ProductInput = { categoryId, name, sku: sku || undefined, unitPrice }

    try {
      if (product) {
        await onUpdate(product.id, input)
      } else {
        await onCreate(input)
      }
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={product ? 'Edit product' : 'New product'}>
      <form className="flex flex-col gap-4" onSubmit={handleSubmit}>
        <Select
          label="Category"
          value={categoryId}
          onChange={(e) => setCategoryId(e.target.value)}
          options={
            categories.length > 0
              ? categories.map((c) => ({ value: c.id, label: c.name }))
              : [{ value: '', label: 'Add a category first' }]
          }
          required
        />
        <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
        <Input label="SKU" hint="Optional" value={sku ?? ''} onChange={(e) => setSku(e.target.value)} />
        <Input
          label="Unit price"
          type="number"
          step="0.01"
          min="0.01"
          value={unitPrice}
          onChange={(e) => setUnitPrice(e.target.value)}
          required
        />
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button type="submit" fullWidth disabled={submitting}>
          {submitting ? 'Saving…' : 'Save'}
        </Button>
      </form>
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
 * Delete uses the same two-step confirm as ExpensesPage's category
 * manager. The server blocks (409 `category_has_products`) a category
 * still referenced by a Product; that error surfaces here per-row.
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
    <Modal open={open} onClose={onClose} title="Product categories">
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
            <Input
              label="New category"
              placeholder="e.g. Apparel"
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
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
