import type { HTMLAttributes, ReactNode, TdHTMLAttributes } from 'react'
import { cn } from '../lib/utils'

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('rounded-lg border bg-card shadow-xs', className)} {...props} />
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b p-4">
      <div>
        <h2 className="font-semibold">{title}</h2>
        {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex gap-2">{actions}</div>}
    </div>
  )
}

const badgeTones = {
  neutral: 'bg-muted text-muted-foreground',
  success: 'bg-success/15 text-success',
  warning: 'bg-warning/15 text-warning',
  danger: 'bg-danger/15 text-danger',
  brand: 'bg-brand/15 text-brand',
}

export function Badge({ tone = 'neutral', className, ...props }: HTMLAttributes<HTMLSpanElement> & { tone?: keyof typeof badgeTones }) {
  return <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', badgeTones[tone], className)} {...props} />
}

export function Alert({ tone = 'danger', children }: { tone?: 'danger' | 'success' | 'warning'; children: ReactNode }) {
  const tones = {
    danger: 'border-danger/30 bg-danger/10 text-danger',
    success: 'border-success/30 bg-success/10 text-success',
    warning: 'border-warning/30 bg-warning/10 text-warning',
  }
  return <div role="alert" className={cn('rounded-md border px-3 py-2 text-sm', tones[tone])}>{children}</div>
}

export function EmptyState({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="px-4 py-10 text-center">
      <p className="font-medium">{title}</p>
      {children && <div className="mt-1 text-sm text-muted-foreground">{children}</div>}
    </div>
  )
}

/** Simple responsive table: scrolls sideways inside its card on narrow screens. */
export function Table({ head, children }: { head: ReactNode[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
            {head.map((h, i) => (
              <th key={i} className="px-4 py-2 font-medium">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y">{children}</tbody>
      </table>
    </div>
  )
}

export function Td({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn('px-4 py-2.5 align-middle', className)} {...props} />
}
