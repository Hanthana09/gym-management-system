import { useRef, useState } from 'react'
import html2canvas from 'html2canvas'
import { Button, Modal } from '../components/ui'
import { MilestoneShareCard } from './MilestoneShareCard'

interface MilestoneCelebrationModalProps {
  memberName: string
  streakDays: number
  onClose: () => void
}

/**
 * roadmap Phase 9.3: celebration moment + Share, using the native mobile
 * share sheet where available (Web Share API with a file attachment),
 * falling back to a plain download for browsers that don't support
 * sharing files.
 */
export function MilestoneCelebrationModal({ memberName, streakDays, onClose }: MilestoneCelebrationModalProps) {
  const cardRef = useRef<HTMLDivElement>(null)
  const [sharing, setSharing] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleShare() {
    if (!cardRef.current) return
    setError(null)
    setSharing(true)

    try {
      const canvas = await html2canvas(cardRef.current, { backgroundColor: null, scale: 2 })
      const blob: Blob | null = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'))
      if (!blob) throw new Error('Could not generate image.')

      const file = new File([blob], `streak-${streakDays}-days.png`, { type: 'image/png' })
      const shareData = { files: [file], title: `${streakDays}-day check-in streak!` }

      if (navigator.canShare?.(shareData)) {
        await navigator.share(shareData)
      } else {
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = file.name
        link.click()
        URL.revokeObjectURL(url)
      }
    } catch (err) {
      // AbortError just means the user closed the native share sheet — not a real error.
      if (err instanceof DOMException && err.name === 'AbortError') return
      setError('Could not share the image. Please try again.')
    } finally {
      setSharing(false)
    }
  }

  return (
    <Modal open onClose={onClose} title="🎉 Milestone reached!">
      <div className="flex flex-col items-center gap-4">
        <div ref={cardRef}>
          <MilestoneShareCard memberName={memberName} streakDays={streakDays} />
        </div>
        <p className="text-center text-sm text-ink-soft">
          You've checked in {streakDays} days in a row. Keep it going!
        </p>
        {error ? <p className="text-sm text-red-600">{error}</p> : null}
        <Button onClick={handleShare} disabled={sharing} fullWidth>
          {sharing ? 'Preparing…' : 'Share'}
        </Button>
      </div>
    </Modal>
  )
}
