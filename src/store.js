// Everything the hub remembers, in one SQLite file on the VPS volume (Node's built-in sqlite: nothing to install).
import { DatabaseSync } from 'node:sqlite'
import fs from 'node:fs'
import path from 'node:path'

export function openStore(file) {
  if (file !== ':memory:') fs.mkdirSync(path.dirname(file), { recursive: true })
  const db = new DatabaseSync(file)
  db.exec(`
    PRAGMA journal_mode=WAL;
    CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS lines (id TEXT PRIMARY KEY, label TEXT, phone TEXT, role TEXT DEFAULT 'customers', brand TEXT DEFAULT '', created INTEGER);
    CREATE TABLE IF NOT EXISTS chats (pk TEXT PRIMARY KEY, phone TEXT, line TEXT, name TEXT, kind TEXT DEFAULT 'customer',
      lastAt INTEGER, lastText TEXT, unread INTEGER DEFAULT 0, agent TEXT DEFAULT '', botOff INTEGER DEFAULT 0,
      stage TEXT DEFAULT '', orderId TEXT DEFAULT '', stageAt INTEGER DEFAULT 0, lead TEXT DEFAULT '', welcomedAt INTEGER DEFAULT 0, needsHuman INTEGER DEFAULT 0);
    CREATE INDEX IF NOT EXISTS chats_last ON chats(lastAt);
    CREATE TABLE IF NOT EXISTS msgs (id INTEGER PRIMARY KEY AUTOINCREMENT, pk TEXT, line TEXT, dir TEXT, text TEXT, kind TEXT, at INTEGER, by TEXT, status TEXT, waId TEXT);
    CREATE INDEX IF NOT EXISTS msgs_pk ON msgs(pk, id);
    CREATE TABLE IF NOT EXISTS outbox (id INTEGER PRIMARY KEY AUTOINCREMENT, line TEXT, phone TEXT, text TEXT, purpose TEXT, at INTEGER, tries INTEGER DEFAULT 0, state TEXT DEFAULT 'wait', err TEXT DEFAULT '', dedup TEXT UNIQUE);
    CREATE INDEX IF NOT EXISTS outbox_state ON outbox(state, id);
    CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER, kind TEXT, text TEXT);
  `)
  // Columns added after the first release: added in place, old rows keep working.
  for (const col of ['humanAt INTEGER DEFAULT 0', "contact TEXT DEFAULT ''", "greet TEXT DEFAULT ''"]) { try { db.exec('ALTER TABLE chats ADD COLUMN ' + col) } catch {} }
  const q = (sql) => db.prepare(sql)
  const now = () => Date.now()
  const s = {
    db,
    get(k, d = null) { const r = q('SELECT v FROM kv WHERE k=?').get(k); if (!r) return d; try { return JSON.parse(r.v) } catch { return d } },
    set(k, v) { q('INSERT INTO kv(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v').run(k, JSON.stringify(v)) },
    log(kind, text) {
      q('INSERT INTO log(at,kind,text) VALUES(?,?,?)').run(now(), kind, String(text).slice(0, 2000))
      q('DELETE FROM log WHERE id < (SELECT MAX(id) - 3000 FROM log)').run()
    },
    logs(limit = 200) { return q('SELECT * FROM log ORDER BY id DESC LIMIT ?').all(limit) },

    lines() { return q('SELECT * FROM lines ORDER BY created').all() },
    line(id) { return q('SELECT * FROM lines WHERE id=?').get(id) || null },
    putLine(l) {
      q(`INSERT INTO lines(id,label,phone,role,brand,created) VALUES(?,?,?,?,?,?)
         ON CONFLICT(id) DO UPDATE SET label=excluded.label, phone=excluded.phone, role=excluded.role, brand=excluded.brand`)
        .run(l.id, l.label || l.id, l.phone || '', l.role || 'customers', l.brand || '', l.created || now())
    },
    dropLine(id) { q('DELETE FROM lines WHERE id=?').run(id) },

    chat(pk) { return q('SELECT * FROM chats WHERE pk=?').get(pk) || null },
    touchChat(pk, f) {
      const c = s.chat(pk)
      if (!c) {
        q('INSERT INTO chats(pk,phone,line,name,kind,lastAt,lastText,unread) VALUES(?,?,?,?,?,?,?,?)')
          .run(pk, f.phone || '', f.line || '', f.name || '', f.kind || 'customer', now(), f.lastText || '', f.unread || 0)
      }
      const set = []; const vals = []
      for (const [k, v] of Object.entries(f)) { if (v === undefined) continue; set.push(k + '=?'); vals.push(v) }
      if (set.length) q('UPDATE chats SET ' + set.join(',') + ' WHERE pk=?').run(...vals, pk)
      return s.chat(pk)
    },
    chats({ limit = 100, filter = '' } = {}) {
      const where = filter === 'unread' ? "WHERE unread>0 AND kind<>'personal'" : filter === 'human' ? 'WHERE needsHuman=1' : filter === 'team' ? "WHERE kind IN ('tech','staff','team')" : filter === 'personal' ? "WHERE kind='personal'" : ''
      return q('SELECT * FROM chats ' + where + ' ORDER BY lastAt DESC LIMIT ?').all(limit)
    },

    addMsg(m) {
      const r = q('INSERT INTO msgs(pk,line,dir,text,kind,at,by,status,waId) VALUES(?,?,?,?,?,?,?,?,?)')
        .run(m.pk, m.line || '', m.dir, String(m.text || '').slice(0, 5000), m.kind || 'text', m.at || now(), m.by || '', m.status || '', m.waId || '')
      return Number(r.lastInsertRowid)
    },
    msgs(pk, limit = 80) { return q('SELECT * FROM (SELECT * FROM msgs WHERE pk=? ORDER BY id DESC LIMIT ?) ORDER BY id').all(pk, limit) },
    setMsg(id, f) { q('UPDATE msgs SET status=?, waId=? WHERE id=?').run(f.status || '', f.waId || '', id) },

    queue(o) {
      try {
        const r = q('INSERT INTO outbox(line,phone,text,purpose,at,dedup) VALUES(?,?,?,?,?,?)').run(o.line || '', o.phone, o.text, o.purpose || '', now(), o.dedup || null)
        return Number(r.lastInsertRowid)
      } catch (e) { if (/UNIQUE/.test(String(e))) return 0; throw e }
    },
    nextOut(limit = 20) { return q("SELECT * FROM outbox WHERE state='wait' ORDER BY id LIMIT ?").all(limit) },
    outDone(id, state, err = '') { q('UPDATE outbox SET state=?, err=?, tries=tries+1 WHERE id=?').run(state, String(err).slice(0, 200), id) },
    outRetry(id, err) { q('UPDATE outbox SET tries=tries+1, err=? WHERE id=?').run(String(err).slice(0, 200), id) },
    outbox(limit = 100) { return q('SELECT * FROM outbox ORDER BY id DESC LIMIT ?').all(limit) },
    sentToday(line) { const d = new Date(); d.setHours(0, 0, 0, 0); return q("SELECT COUNT(*) n FROM outbox WHERE line=? AND state='sent' AND at>=?").get(line, d.getTime()).n },
  }
  return s
}
