// roadmap Phase 17 / architecture doc §5.1, §7 — Product & retail sale entities.
// No unit_cost/margin field anywhere in this module by design (§6.13's
// explicit exclusion) — never add one here even if it looks convenient.

export interface ProductCategoryDto {
  id: string
  name: string
}

export interface ProductDto {
  id: string
  category: { id: string; name: string }
  name: string
  sku: string | null
  unitPrice: string
  isActive: boolean
}

export interface ProductInput {
  categoryId: string
  name: string
  sku?: string
  unitPrice: string
}

export type PaymentMethod = 'cash' | 'card' | 'other'

export interface ProductSaleDto {
  id: string
  branchId: string
  product: { id: string; name: string }
  member: { id: string; name: string } | null
  quantity: number
  unitPriceAtSale: string
  totalAmount: string
  paymentMethod: PaymentMethod
  soldByName: string
  saleDate: string
}

export interface ProductSaleFilters {
  branchId?: string | null
  productId?: string | null
  memberId?: string | null
  from?: string
  to?: string
}

export interface ProductSaleInput {
  branchId: string
  productId: string
  quantity: number
  memberId?: string
  paymentMethod: PaymentMethod
}
