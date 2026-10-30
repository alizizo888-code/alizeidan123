import { useEffect, useMemo, useState, useCallback } from 'react'
import { useLiveShared } from '../lib/useLive'
import { post } from '../lib/_api'
import { db } from '../lib/db'
import { team } from '../lib/team'
import { normalizeSchedule, bucketLoad } from '../lib/schedule'

// Owner schedule rules (public `schedule_cfg` row "main") + the booked appointment times (server, no personal data).
export function useSchedule({ poll = 60000 } = {}) {
  const { data } = useLiveShared('schedule_cfg', { limit: 5 })
  const cfg = useMemo(() => normalizeSchedule((data || []).find((r) => r.id === 'main')), [data])
  const [times, setTimes] = useState([])
  const [now, setNow] = useState(Date.now())
  useEffect(() => {
    let live = true
    const run = () => post('/shop', { op: 'load' }).then((r) => { if (live && r?.times) { setTimes(r.times); setNow(Date.now()) } }).catch(() => {})
    run()
    const id = setInterval(run, poll)
    return () => { live = false; clearInterval(id) }
  }, [poll])
  const load = useMemo(() => bucketLoad(times), [times])
  const save = useCallback(async (next) => {
    const gid = await team.groupId()
    await db.upsertShared('schedule_cfg', normalizeSchedule(next), 'main', { groupId: gid, writableBy: 'admins' })
  }, [])
  return { cfg, times, load, now, save }
}
