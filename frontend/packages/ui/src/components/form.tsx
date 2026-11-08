import { forwardRef, type InputHTMLAttributes, type LabelHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { cn } from '../lib/utils'

const fieldBase =
  'w-full rounded-md border border-input bg-card px-3 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50 aria-[invalid=true]:border-danger'

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className, ...props }, ref) => (
  <input ref={ref} className={cn(fieldBase, 'h-10', className)} {...props} />
))
Input.displayName = 'Input'

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, ...props }, ref) => (
  <textarea ref={ref} className={cn(fieldBase, 'min-h-20 py-2', className)} {...props} />
))
Textarea.displayName = 'Textarea'

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...props }, ref) => (
  <select ref={ref} className={cn(fieldBase, 'h-10', className)} {...props} />
))
Select.displayName = 'Select'

export function Label({ className, ...props }: LabelHTMLAttributes<HTMLLabelElement>) {
  return <label className={cn('text-sm font-medium', className)} {...props} />
}

/** Label + control + error/hint, stacked. */
export function Field({ label, error, hint, children, className }: {
  label: string
  error?: string
  hint?: string
  children: ReactNode
  className?: string
}) {
  return (
    <div className={cn('grid gap-1.5', className)}>
      <Label>{label}</Label>
      {children}
      {error ? <p className="text-xs text-danger">{error}</p> : hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  )
}

export function Checkbox({ label, className, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className={cn('inline-flex items-center gap-2 text-sm', className)}>
      <input type="checkbox" className="size-4 accent-[var(--brand)]" {...props} />
      {label}
    </label>
  )
}
