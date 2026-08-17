import { useCallback, useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ProductSaleDto, ProductSaleFilters, ProductSaleInput } from './types'

function buildQuery(filters: ProductSaleFilters): string {
  const params = new URLSearchParams()
  if (filters.branchId) params.set('branch_id', filters.branchId)
  if (filters.productId) params.set('product_id', filters.productId)
  if (filters.memberId) params.set('member_id', filters.memberId)
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  const query = params.toString()

  return query ? `?${query}` : ''
}

/**
 * architecture doc §7 / §9.1 ProductSaleVoter: Owner any branch, Staff own
 * assigned branch(es) only — server-enforced regardless of filters.
 * functional requirements §15.3: the server computes `unitPriceAtSale`/
 * `totalAmount` from the product's current price at save time — the
 * client never sends either, it only ever sends `productId`/`quantity`
 * and displays the server's recorded total back once the sale returns.
 */
export function useProductSales(filters: ProductSaleFilters) {
  const { authFetch } = useAuth()
  const [sales, setSales] = useState<ProductSaleDto[]>([])
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    const data = await authFetch<{ sales: ProductSaleDto[] }>(`/product-sales${buildQuery(filters)}`, {
      method: 'GET',
    })
    setSales(data.sales)
    setLoaded(true)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [authFetch, filters.branchId, filters.productId, filters.memberId, filters.from, filters.to])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const createSale = useCallback(
    async (input: ProductSaleInput) => {
      const sale = await authFetch<ProductSaleDto>('/product-sales', { method: 'POST', body: input })
      setSales((prev) => [sale, ...prev])

      return sale
    },
    [authFetch],
  )

  return { sales, loaded, refresh, createSale }
}
