import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ExpenseCategoryDto } from './types'

/**
 * architecture doc §7: GET /expense-categories — Owner (manage) / Staff
 * (read only, to pick a category when recording an expense). Owner-only
 * `createCategory` is a no-op call from Staff's perspective (the "New
 * category" affordance simply isn't rendered for them — same pattern as
 * every other Owner-only action in this codebase, backed by the server's
 * own 403 if bypassed).
 */
export function useExpenseCategories() {
  const { authFetch } = useAuth()
  const [categories, setCategories] = useState<ExpenseCategoryDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ categories: ExpenseCategoryDto[] }>('/expense-categories', {
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
      const category = await authFetch<ExpenseCategoryDto>('/expense-categories', {
        method: 'POST',
        body: { name },
      })
      setCategories((prev) => [...prev, category])

      return category
    },
    [authFetch],
  )

  /**
   * Owner only — the server blocks this with a 409 `category_has_expenses`
   * if any Expense still references it (ExpenseCategoryController::delete()),
   * same "block, don't orphan" rule as membership plan deletion elsewhere
   * in this app.
   */
  const deleteCategory = useCallback(
    async (id: string) => {
      await authFetch<null>(`/expense-categories/${id}`, { method: 'DELETE' })
      setCategories((prev) => prev.filter((category) => category.id !== id))
    },
    [authFetch],
  )

  return { categories, loaded, refresh, createCategory, deleteCategory }
}
