import { useEffect, useState } from 'react'
import { Clock } from 'lucide-react'
import { ago, exact } from '../lib/ago'

// A live «من 5 دقايق» that keeps itself up to date (every 30 s) and shows the exact time on hover / long-press.
let subs = new Set(); let timer = null
const tick = () => subs.forEach((f) => f(Date.now()))
function useNow() {
  const [now, setNow] = useState(Date.now())
  useEffect(() => {
    subs.add(setNow)
    if (!timer) timer = setInterval(tick, 30000)
    return () => { subs.delete(setNow); if (!subs.size && timer) { clearInterval(timer); timer = null } }
  }, [])
  return now
}

export default function Ago({ at, icon = false, className = '', prefix = '' }) {
  const now = useNow()
  const w = ago(at, now)
  if (!w) return null
  return (
    <time dateTime={typeof at === 'string' ? at : undefined} title={exact(at)} className={`inline-flex items-center gap-1 whitespace-nowrap ${className}`}>
      {icon && <Clock size={11} className="opacity-70" />}{prefix}{w}
    </time>
  )
}
