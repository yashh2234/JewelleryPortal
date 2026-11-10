import { api, Button, Card, cn, EmptyState, Input, Table, Td } from '@b2b/ui'
import { useQuery } from '@tanstack/react-query'
import { BarChart3, Download, FileSpreadsheet, FileText } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { PageTitle } from '../layout'

interface Report { title: string; subtitle: string | null; columns: { key: string; label: string; num?: boolean }[]; rows: Record<string, string>[]; totals?: Record<string, string> }

const fyStart = () => {
  const t = new Date()
  return `${t.getMonth() >= 3 ? t.getFullYear() : t.getFullYear() - 1}-04-01`
}
const todayIso = () => new Date().toISOString().slice(0, 10)

/** Spec §12: production, gold and business reports; on screen, Excel, PDF or CSV. */
export default function AnalyticsPage() {
  const { name } = useParams()
  const list = useQuery({ queryKey: ['reports'], queryFn: () => api.get<{ data: { group: string; reports: { name: string; title: string }[] }[] }>('/api/v1/reports') })
  const [from, setFrom] = useState(fyStart())
  const [to, setTo] = useState(todayIso())
  const active = name ?? list.data?.data[0]?.reports[0]?.name

  const report = useQuery({
    queryKey: ['report', active, from, to],
    queryFn: () => api.get<Report>(`/api/v1/reports/${active}?from=${from}&to=${to}`),
    enabled: !!active,
  })
  const r = report.data
  const download = (format: string) => `/api/v1/reports/${active}?from=${from}&to=${to}&format=${format}`

  return (
    <>
      <PageTitle title="Reports & analytics" description="Production, gold and business reports. Download any of them as Excel or PDF." />

      <div className="grid gap-4 lg:grid-cols-[15rem_1fr]">
        <Card className="h-fit p-2">
          {list.data?.data.map((g) => (
            <div key={g.group} className="mb-2 last:mb-0">
              <p className="px-3 pb-1 pt-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-muted-foreground">{g.group}</p>
              {g.reports.map((x) => (
                <Link key={x.name} to={`/staff/reports/${x.name}`}
                  className={cn('block rounded-lg px-3 py-1.5 text-sm transition-colors', x.name === active ? 'bg-brand/10 font-medium text-brand' : 'text-foreground/80 hover:bg-muted')}>
                  {x.title}
                </Link>
              ))}
            </div>
          ))}
        </Card>

        <div className="min-w-0">
          <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
            <label className="grid gap-1 text-xs text-muted-foreground">From<Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" /></label>
            <label className="grid gap-1 text-xs text-muted-foreground">To<Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" /></label>
            <div className="ml-auto flex flex-wrap gap-2">
              <a href={download('xlsx')}><Button variant="outline" size="sm"><FileSpreadsheet /> Excel</Button></a>
              <a href={download('pdf')}><Button variant="outline" size="sm"><FileText /> PDF</Button></a>
              <a href={download('csv')}><Button variant="ghost" size="sm"><Download /> CSV</Button></a>
            </div>
          </Card>

          <Card>
            <div className="border-b px-5 py-4">
              <h2 className="font-semibold tracking-tight">{r?.title ?? '…'}</h2>
              {r?.subtitle && <p className="text-sm text-muted-foreground">{r.subtitle}</p>}
            </div>
            {report.isLoading ? <p className="p-5 text-sm text-muted-foreground">Loading…</p> : !r || r.rows.length === 0 ? (
              <EmptyState title="Nothing for this period" icon={<BarChart3 />}>Try a wider date range.</EmptyState>
            ) : (
              <Table head={r.columns.map((c) => <span className={c.num ? 'block text-right' : ''}>{c.label}</span>)}>
                {r.rows.map((row, i) => (
                  <tr key={i}>
                    {r.columns.map((c) => <Td key={c.key} className={c.num ? 'tabular text-right' : ''}>{row[c.key] ?? ''}</Td>)}
                  </tr>
                ))}
                {r.totals && Object.keys(r.totals).length > 0 && (
                  <tr className="bg-muted/50 font-semibold">
                    {r.columns.map((c) => <Td key={c.key} className={c.num ? 'tabular text-right' : ''}>{r.totals?.[c.key] ?? ''}</Td>)}
                  </tr>
                )}
              </Table>
            )}
          </Card>
        </div>
      </div>
    </>
  )
}
