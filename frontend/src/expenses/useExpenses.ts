import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ExpenseDto, ExpenseFilters, ExpenseInput } from './types'

function buildQuery(filters: ExpenseFilters): string {
  const params = new URLSearchParams()
  if (filters.branchId) params.set('branch_id', filters.branchId)
  if (filters.categoryId) params.set('category_id', filters.categoryId)
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  const query = params.toString()

  return query ? `?${query}` : ''
}

/**
 * architecture doc §7 / §9.1 ExpenseVoter: Owner sees any branch, Staff
 * only their own assigned branch(es) — enforced server-side regardless of
 * what `branchId` filter is passed, same defense-in-depth note as every
 * other Phase 17 endpoint. Owner-only mutations (`updateExpense`,
 * `deleteExpense`) still exist here so the hook is shared by both
 * ExpensesPage's Owner and Staff renders; the page itself hides the
 * buttons that call them for Staff, and the server 403s regardless.
 */
export function useExpenses(filters: ExpenseFilters) {
  const { authFetch, authFetchForm } = useAuth()
  const [expenses, setExpenses] = useState<ExpenseDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ expenses: ExpenseDto[] }>(`/expenses${buildQuery(filters)}`, {
      method: 'GET',
    })
    setExpenses(data.expenses)
    setLoaded(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [authFetch, filters.branchId, filters.categoryId, filters.from, filters.to])

  useEffect(() => {
    void refresh()
  }, [refresh])

  /**
   * ExpenseController::create() reads `$request->request` (form fields),
   * never a JSON body — so POST /expenses is ALWAYS multipart/form-data,
   * with or without a receipt file attached (unlike the logo-upload
   * pattern this was originally modeled on, which is JSON-optional).
   */
  const createExpense = useCallback(
    async (input: ExpenseInput, receiptFile?: File | null) => {
      const formData = new FormData()
      formData.append('branchId', input.branchId)
      formData.append('categoryId', input.categoryId)
      formData.append('amount', input.amount)
      formData.append('currency', input.currency)
      formData.append('description', input.description)
      formData.append('expenseDate', input.expenseDate)
      if (receiptFile) formData.append('receipt', receiptFile)
      const created = await authFetchForm<ExpenseDto>('/expenses', formData, 'POST')
      setExpenses((prev) => [created, ...prev])

      return created
    },
    [authFetchForm],
  )

  /**
   * Owner only (ExpenseVoter::MANAGE) — Staff never reaches this from the
   * UI, and the server 403s if they did. ExpenseController::update() is
   * JSON-only (decodes the body as JSON, no multipart handling) and has
   * no receipt-replacement support at all — this never sends a file.
   */
  const updateExpense = useCallback(
    async (id: string, input: ExpenseInput) => {
      const updated = await authFetch<ExpenseDto>(`/expenses/${id}`, { method: 'PATCH', body: input })
      setExpenses((prev) => prev.map((expense) => (expense.id === id ? updated : expense)))

      return updated
    },
    [authFetch],
  )

  const deleteExpense = useCallback(
    async (id: string) => {
      await authFetch<null>(`/expenses/${id}`, { method: 'DELETE' })
      setExpenses((prev) => prev.filter((expense) => expense.id !== id))
    },
    [authFetch],
  )

  return { expenses, loaded, refresh, createExpense, updateExpense, deleteExpense }
}
