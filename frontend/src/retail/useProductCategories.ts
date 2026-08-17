import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ProductCategoryDto } from './types'

/**
 * architecture doc §7: GET /product-categories — Owner/Staff read
 * (ProductVoter::VIEW; Coach/Member get a 403, per the Voter body/FR
 * §15.2, which takes precedence over §7's more general "any authenticated
 * gym user" prose — see ProductCategoryController's own docblock). POST
 * is Owner only.
 */
export function useProductCategories() {
  const { authFetch } = useAuth()
  const [categories, setCategories] = useState<ProductCategoryDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ categories: ProductCategoryDto[] }>('/product-categories', {
      method: 'GET',
    })
    setCategories(data.categories)
    setLoaded(true)
  }, [authFetch])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createCategory = useCallback(
    async (name: string) => {
      const category = await authFetch<ProductCategoryDto>('/product-categories', {
        method: 'POST',
        body: { name },
      })
      setCategories((prev) => [...prev, category])

      return category
    },
    [authFetch],
  )

  /**
   * Owner only (ProductVoter::MANAGE) — the server blocks this with a 409
   * `category_has_products` if any Product still references it
   * (ProductCategoryController::delete()), same "block, don't orphan" rule
   * as membership plan deletion elsewhere in this app.
   */
  const deleteCategory = useCallback(
    async (id: string) => {
      await authFetch<null>(`/product-categories/${id}`, { method: 'DELETE' })
      setCategories((prev) => prev.filter((category) => category.id !== id))
    },
    [authFetch],
  )

  return { categories, loaded, refresh, createCategory, deleteCategory }
}
