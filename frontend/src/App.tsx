import { useEffect, useState } from 'react'

type HealthState =
  | { status: 'loading' }
  | { status: 'ok'; body: string }
  | { status: 'error'; message: string }

function App() {
  const [health, setHealth] = useState<HealthState>({ status: 'loading' })

  useEffect(() => {
    const apiUrl = import.meta.env.VITE_API_URL as string

    fetch(`${apiUrl}/health`)
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        return res.text()
      })
      .then((body) => setHealth({ status: 'ok', body }))
      .catch((err) => setHealth({ status: 'error', message: String(err) }))
  }, [])

  return (
    <div className="flex min-h-svh items-center justify-center bg-white px-4">
      <div className="w-full max-w-sm rounded-lg border border-gray-200 p-6 text-center">
        <h1 className="text-xl font-semibold text-gray-900">
          Gym Management System
        </h1>
        <p className="mt-1 text-sm text-gray-500">Backend connectivity check</p>

        <div className="mt-4 rounded-md bg-gray-50 p-3 text-sm">
          {health.status === 'loading' && (
            <span className="text-gray-500">Checking backend…</span>
          )}
          {health.status === 'ok' && (
            <span className="font-medium text-green-600">
              Backend reachable: {health.body}
            </span>
          )}
          {health.status === 'error' && (
            <span className="font-medium text-red-600">
              Backend unreachable: {health.message}
            </span>
          )}
        </div>
      </div>
    </div>
  )
}

export default App
