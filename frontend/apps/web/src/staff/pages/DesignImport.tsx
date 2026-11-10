import { Alert, api, ApiError, Button, Card, CardHeader } from '@b2b/ui'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Download, Upload } from 'lucide-react'

const SAMPLE = 'code,name,category,karats,weight_min,weight_max,sizes,making_charge,making_type,collections,published\nKD-201,Temple kada,Kada,22K,18,22,2.4|2.6|2.8,500,per_gram,Temple|New arrivals,yes\n'

/** Bulk upload: a CSV of designs, then photos named by design code (KD-201.jpg, KD-201_2.jpg). */
export default function DesignImport({ onDone }: { onDone: () => void }) {
  const qc = useQueryClient()
  const csv = useMutation({
    mutationFn: (file: File) => { const fd = new FormData(); fd.append('file', file); return api.post<{ created: number; updated: number; errors: string[] }>('/api/v1/designs/import', fd) },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['designs'] }),
  })
  const photos = useMutation({
    mutationFn: (files: FileList) => { const fd = new FormData(); Array.from(files).forEach((f) => fd.append('images[]', f)); return api.post<{ matched: number; unmatched: string[] }>('/api/v1/designs/images/bulk', fd) },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['designs'] }),
  })
  const err = [csv.error, photos.error].find((e) => e instanceof ApiError) as ApiError | undefined
  const sample = URL.createObjectURL(new Blob([SAMPLE], { type: 'text/csv' }))

  return (
    <Card className="mb-4">
      <CardHeader title="Bulk upload" description="1. Upload a CSV (existing codes are updated). 2. Upload photos named by design code." actions={<Button size="sm" variant="ghost" onClick={onDone}>Close</Button>} />
      <div className="grid gap-3 p-4">
        {err && <Alert>{Object.values(err.errors)[0]?.[0] ?? err.message}</Alert>}
        {csv.data && (
          <Alert tone={csv.data.errors.length ? 'warning' : 'success'}>
            {csv.data.created} created, {csv.data.updated} updated.{csv.data.errors.length > 0 && <> Skipped: {csv.data.errors.join(' ')}</>}
          </Alert>
        )}
        {photos.data && (
          <Alert tone={photos.data.unmatched.length ? 'warning' : 'success'}>
            {photos.data.matched} photos added.{photos.data.unmatched.length > 0 && <> No design code for: {photos.data.unmatched.join(', ')}</>}
          </Alert>
        )}
        <div className="flex flex-wrap gap-2">
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border px-4 py-2 text-sm hover:bg-muted">
            <Upload className="size-4" /> {csv.isPending ? 'Importing…' : 'Upload designs CSV'}
            <input type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => e.target.files?.[0] && csv.mutate(e.target.files[0])} />
          </label>
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border px-4 py-2 text-sm hover:bg-muted">
            <Upload className="size-4" /> {photos.isPending ? 'Uploading…' : 'Upload photos (many at once)'}
            <input type="file" accept="image/*" multiple className="hidden" onChange={(e) => e.target.files?.length && photos.mutate(e.target.files)} />
          </label>
          <a href={sample} download="designs-sample.csv" className="inline-flex items-center gap-2 px-2 py-2 text-sm text-brand hover:underline"><Download className="size-4" /> Sample CSV</a>
        </div>
        <p className="text-xs text-muted-foreground">Columns: code, name, category, karats (22K|18K), weight_min, weight_max, sizes (2.4|2.6), making_charge, making_type (per_gram / per_piece / percent), collections (Temple|Bridal), published (yes/no).</p>
      </div>
    </Card>
  )
}
