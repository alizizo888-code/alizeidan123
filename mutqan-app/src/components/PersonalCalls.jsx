import { useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import IncomingCalls from './IncomingCalls'
import { currentUser, onUser } from '../lib/_api'
import { hasOrdered } from '../lib/db'

// Customer app: a technician or operations may ring this phone about an order. Listened for only by people who can be
// rung (signed in, or this device placed an order) and never on staff screens, which have their own IncomingCalls.
export default function PersonalCalls() {
  const { pathname } = useLocation()
  const [, bump] = useState(0)
  useEffect(() => onUser(() => bump((n) => n + 1)), [])
  useEffect(() => { const i = setInterval(() => bump((n) => n + 1), 30000); return () => clearInterval(i) }, [])
  if (pathname.startsWith('/staff')) return null
  if (!currentUser() && !hasOrdered()) return null
  return <IncomingCalls personal />
}
