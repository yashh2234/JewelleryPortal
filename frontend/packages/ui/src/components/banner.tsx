import { useTenant } from '../lib/tenant'

/** Shown on every screen of a workspace whose trial has ended or is about to. */
export function WorkspaceBanner() {
  const t = useTenant()

  if (t.read_only) {
    return (
      <div className="bg-warning/15 px-4 py-2 text-center text-sm text-warning">
        The free trial has ended. You can view everything, but changes are paused until the plan is activated.
      </div>
    )
  }

  if (t.status === 'trial' && t.trial_ends_at) {
    const days = Math.ceil((new Date(t.trial_ends_at).getTime() - Date.now()) / 86_400_000)
    if (days <= 3) {
      return (
        <div className="bg-warning/10 px-4 py-1.5 text-center text-xs text-warning">
          Trial ends in {days} day{days === 1 ? '' : 's'}.
        </div>
      )
    }
  }

  return null
}
