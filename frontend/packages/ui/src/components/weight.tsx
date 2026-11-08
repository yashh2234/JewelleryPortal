import { Scale } from 'lucide-react'
import { forwardRef, useState, type InputHTMLAttributes } from 'react'
import { readScale, scaleSupported } from '../lib/scale'
import { cn } from '../lib/utils'
import { Input } from './form'

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> & {
  value: string
  onValueChange: (value: string) => void
  /** Show the "read scale" button (store PC). */
  scale?: boolean
}

/** Gram input: decimals only, 3 places, optional "read from scale" button. */
export const WeightInput = forwardRef<HTMLInputElement, Props>(({ value, onValueChange, scale = true, className, ...props }, ref) => {
  const [reading, setReading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const read = async () => {
    setReading(true)
    setError(null)
    try {
      onValueChange(await readScale())
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not read the scale')
    } finally {
      setReading(false)
    }
  }

  return (
    <div>
      <div className="flex">
        <Input
          ref={ref}
          inputMode="decimal"
          autoComplete="off"
          className={cn('tabular', scale && scaleSupported() && 'rounded-r-none', className)}
          value={value}
          onChange={(e) => onValueChange(e.target.value.replace(/[^\d.]/g, '').replace(/^(\d*\.\d{0,3}).*$/, '$1'))}
          {...props}
        />
        {scale && scaleSupported() && (
          <button
            type="button"
            title="Read from scale"
            onClick={read}
            disabled={reading}
            className="flex items-center rounded-r-md border border-l-0 border-input bg-muted px-2.5 text-muted-foreground hover:text-foreground disabled:opacity-50"
          >
            <Scale className={cn('size-4', reading && 'animate-pulse')} />
          </button>
        )}
      </div>
      {error && <p className="mt-1 text-xs text-danger">{error}</p>}
    </div>
  )
})
WeightInput.displayName = 'WeightInput'
