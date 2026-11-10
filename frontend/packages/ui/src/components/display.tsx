import { AlertCircle, CheckCircle2, Inbox, TriangleAlert } from 'lucide-react'
import type { HTMLAttributes, ReactNode, TdHTMLAttributes } from 'react'
import { cn } from '../lib/utils'

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('rounded-xl border bg-card shadow-card', className)} {...props} />
}

export function CardHeader({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
      <div className="min-w-0">
        <h2 className="text-[0.9375rem] font-semibold tracking-tight">{title}</h2>
        {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

/** Page heading: title, optional one-line description, actions on the right. */
export function PageHeader({ title, description, children, className }: { title: ReactNode; description?: ReactNode; children?: ReactNode; className?: string }) {
  return (
    <div className={cn('mb-6 flex flex-wrap items-end justify-between gap-x-4 gap-y-3', className)}>
      <div className="min-w-0">
        <h1 className="font-display text-2xl font-semibold leading-tight sm:text-[1.75rem]">{title}</h1>
        {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
      </div>
      {children && <div className="flex flex-wrap items-center gap-2">{children}</div>}
    </div>
  )
}

const badgeTones = {
  neutral: 'bg-muted text-muted-foreground ring-border',
  success: 'bg-success/10 text-success ring-success/20',
  warning: 'bg-warning/10 text-warning ring-warning/20',
  danger: 'bg-danger/10 text-danger ring-danger/20',
  brand: 'bg-brand/10 text-brand ring-brand/20',
}

export function Badge({ tone = 'neutral', className, ...props }: HTMLAttributes<HTMLSpanElement> & { tone?: keyof typeof badgeTones }) {
  return (
    <span
      className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', badgeTones[tone], className)}
      {...props}
    />
  )
}

export function Alert({ tone = 'danger', children }: { tone?: 'danger' | 'success' | 'warning'; children: ReactNode }) {
  const tones = {
    danger: { cls: 'border-danger/25 bg-danger/[0.07] text-danger', Icon: AlertCircle },
    success: { cls: 'border-success/25 bg-success/[0.07] text-success', Icon: CheckCircle2 },
    warning: { cls: 'border-warning/25 bg-warning/[0.07] text-warning', Icon: TriangleAlert },
  }
  const { cls, Icon } = tones[tone]
  return (
    <div role="alert" className={cn('flex items-start gap-2.5 rounded-lg border px-3.5 py-2.5 text-sm', cls)}>
      <Icon className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0">{children}</div>
    </div>
  )
}

export function EmptyState({ title, children, icon }: { title: string; children?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex flex-col items-center px-4 py-12 text-center">
      <div className="mb-3 grid size-11 place-items-center rounded-full bg-muted text-muted-foreground [&_svg]:size-5">{icon ?? <Inbox />}</div>
      <p className="font-medium">{title}</p>
      {children && <div className="mt-1 max-w-sm text-sm text-muted-foreground">{children}</div>}
    </div>
  )
}

/** Simple responsive table: scrolls sideways inside its card on narrow screens. */
export function Table({ head, children }: { head: ReactNode[]; children: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/50 text-left text-[0.6875rem] uppercase tracking-wider text-muted-foreground">
            {head.map((h, i) => (
              <th key={i} className="whitespace-nowrap px-4 py-2.5 font-semibold first:pl-5 last:pr-5">{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y [&>tr]:transition-colors [&>tr:hover]:bg-muted/40">{children}</tbody>
      </table>
    </div>
  )
}

export function Td({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn('px-4 py-3 align-middle first:pl-5 last:pr-5', className)} {...props} />
}

/** Initials (or logo) in a brand-tinted circle. */
export function Avatar({ name, src, className }: { name: string; src?: string | null; className?: string }) {
  const initials = name.trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?'
  return src ? (
    <img src={src} alt="" className={cn('size-8 shrink-0 rounded-full object-cover', className)} />
  ) : (
    <span className={cn('grid size-8 shrink-0 place-items-center rounded-full bg-brand/12 text-xs font-semibold text-brand ring-1 ring-inset ring-brand/20', className)}>
      {initials}
    </span>
  )
}
