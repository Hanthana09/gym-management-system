import { useEffect, useRef, type ClipboardEvent, type KeyboardEvent } from 'react'

const DIGIT_COUNT = 6

interface OtpDigitInputsProps {
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  error?: boolean
}

/**
 * Six individual digit boxes (roadmap Phase 2), not the shared `Input`
 * primitive — a labeled text field doesn't fit this auto-advancing,
 * paste-distributing interaction, and no existing primitive covers it.
 * Lives outside components/ui/ so Phase 1's library stays untouched.
 */
export function OtpDigitInputs({ value, onChange, disabled, error }: OtpDigitInputsProps) {
  const inputRefs = useRef<Array<HTMLInputElement | null>>([])
  const digits = value.padEnd(DIGIT_COUNT, ' ').split('').slice(0, DIGIT_COUNT)

  useEffect(() => {
    if (value === '') inputRefs.current[0]?.focus()
  }, [value])

  function setDigitAt(index: number, digit: string) {
    const next = digits.slice()
    next[index] = digit
    onChange(next.join('').trimEnd())
  }

  function distribute(startIndex: number, raw: string) {
    const cleaned = raw.replace(/\D/g, '')
    if (cleaned === '') return

    const next = digits.slice()
    for (let i = 0; i < cleaned.length && startIndex + i < DIGIT_COUNT; i++) {
      next[startIndex + i] = cleaned[i]
    }
    onChange(next.join('').trimEnd())

    const lastFilled = Math.min(startIndex + cleaned.length, DIGIT_COUNT) - 1
    inputRefs.current[Math.min(lastFilled + 1, DIGIT_COUNT - 1)]?.focus()
  }

  function handleChange(index: number, rawValue: string) {
    // A single real keystroke, or an OS one-time-code autofill landing
    // its full value in whichever box has autocomplete="one-time-code".
    if (rawValue.length > 1) {
      distribute(index, rawValue)
      return
    }

    if (/^\d?$/.test(rawValue)) {
      setDigitAt(index, rawValue)
      if (rawValue !== '' && index < DIGIT_COUNT - 1) {
        inputRefs.current[index + 1]?.focus()
      }
    }
  }

  function handleKeyDown(index: number, event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Backspace' && digits[index].trim() === '' && index > 0) {
      inputRefs.current[index - 1]?.focus()
    }
  }

  function handlePaste(index: number, event: ClipboardEvent<HTMLInputElement>) {
    event.preventDefault()
    distribute(index, event.clipboardData.getData('text'))
  }

  return (
    <div className="flex justify-center gap-2" role="group" aria-label="6-digit code">
      {digits.map((digit, index) => (
        <input
          key={index}
          ref={(el) => {
            inputRefs.current[index] = el
          }}
          type="text"
          inputMode="numeric"
          autoComplete={index === 0 ? 'one-time-code' : 'off'}
          maxLength={6} /* allows autofill/paste to land the full code in one box */
          value={digit.trim()}
          onChange={(event) => handleChange(index, event.target.value)}
          onKeyDown={(event) => handleKeyDown(index, event)}
          onPaste={(event) => handlePaste(index, event)}
          disabled={disabled}
          aria-label={`Digit ${index + 1}`}
          className={`min-h-touch min-w-touch w-12 rounded-md border bg-card text-center text-xl font-semibold text-ink focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-1 sm:w-14 ${
            error ? 'border-red-500' : 'border-line'
          }`}
        />
      ))}
    </div>
  )
}
