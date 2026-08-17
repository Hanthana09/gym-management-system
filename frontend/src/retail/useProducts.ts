import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ProductDto, ProductInput } from './types'

interface ProductFilters {
  categoryId?: string | null
  isActive?: boolean | null
}

/**
 * architecture doc §7: GET /products — Owner (manage) / Staff (read-only,
 * for the sale picker). POST/PATCH are Owner only (create/edit/deactivate)
 * — functional requirements §15.2: deactivating a product just flips
 * `isActive` via PATCH, past sales referencing it stay intact (ProductSale
 * snapshots unitPriceAtSale independently).
 *
 * ProductController::list() only supports a single `active_only` boolean
 * flag server-side (no `category_id` filter) — category filtering here is
 * done client-side after fetch.
 */
export function useProducts(filters: ProductFilters = {}) {
  const { authFetch } = useAuth()
  const [products, setProducts] = useState<ProductDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const params = new URLSearchParams()
    if (filters.isActive) params.set('active_only', '1')
    const query = params.toString()
    const data = await authFetch<{ products: ProductDto[] }>(`/products${query ? `?${query}` : ''}`, {
      method: 'GET',
    })
    const filtered = filters.categoryId
      ? data.products.filter((p) => p.category.id === filters.categoryId)
      : data.products
    setProducts(filtered)
    setLoaded(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [authFetch, filters.categoryId, filters.isActive])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createProduct = useCallback(
    async (input: ProductInput) => {
      const product = await authFetch<ProductDto>('/products', { method: 'POST', body: input })
      setProducts((prev) => [...prev, product])

      return product
    },
    [authFetch],
  )

  const updateProduct = useCallback(
    async (id: string, changes: Partial<ProductInput> & { isActive?: boolean }) => {
      const product = await authFetch<ProductDto>(`/products/${id}`, { method: 'PATCH', body: changes })
      setProducts((prev) => prev.map((p) => (p.id === id ? product : p)))

      return product
    },
    [authFetch],
  )

  return { products, loaded, refresh, createProduct, updateProduct }
}
