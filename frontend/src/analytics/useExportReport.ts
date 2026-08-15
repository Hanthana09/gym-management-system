import { useCallback, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import type { ExportFormat, ExportReportType } from './types'

/** functional requirements §10.5: pick a report + date range + format, download the file. `branchId` omitted/null means the gym-wide rollup (functional requirements §14.5). */
export function useExportReport() {
  const { authFetchBlob } = useAuth()
  const [exporting, setExporting] = useState(false)

  const exportReport = useCallback(
    async (report: ExportReportType, format: ExportFormat, from: string, to: string, branchId?: string | null) => {
      setExporting(true)
      try {
        const params = new URLSearchParams({ report, format, from, to })
        if (branchId) params.set('branch_id', branchId)
        const { blob, filename } = await authFetchBlob(`/reports/export?${params.toString()}`)

        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = filename
        document.body.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(url)
      } finally {
        setExporting(false)
      }
    },
    [authFetchBlob],
  )

  return { exportReport, exporting }
}
