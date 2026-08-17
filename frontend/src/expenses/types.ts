// roadmap Phase 17 / architecture doc §5.1, §7 — Expense entities.

export interface ExpenseCategoryDto {
  id: string
  name: string
}

export interface ExpenseDto {
  id: string
  branchId: string
  branchName: string
  category: { id: string; name: string }
  amount: string
  currency: string
  description: string
  expenseDate: string
  recordedByName: string
  receiptUrl: string | null
  createdAt: string
  updatedAt: string
}

export interface ExpenseFilters {
  branchId?: string | null
  categoryId?: string | null
  from?: string
  to?: string
}

export interface ExpenseInput {
  branchId: string
  categoryId: string
  amount: string
  currency: string
  description: string
  expenseDate: string
}

// functional requirements §15.4 — membership + PT + retail revenue, total
// expenses, net, for a date range and optional branch. All money fields
// are decimal-strings (e.g. "1234.56"), same shape as InvoiceDto.amount.
export interface FinancialSummaryDto {
  gymId: string
  branchId: string | null
  from: string
  to: string
  membershipRevenue: string
  ptRevenue: string
  retailRevenue: string
  totalExpenses: string
  net: string
}
