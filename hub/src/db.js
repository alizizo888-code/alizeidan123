// SQLite (node:sqlite, built in). One file: data/hub.db. Accounts are platform-neutral so Blogger, WordPress and landing pages share one registry.
const { DatabaseSync } = require('node:sqlite'), path = require('path'), fs = require('fs')
const file = process.env.HUB_DB || path.join(__dirname, '..', 'data', 'hub.db')
fs.mkdirSync(path.dirname(file), { recursive: true })
const db = new DatabaseSync(file)
db.exec(`
PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS accounts(            -- one row per login the owner opened himself
  id INTEGER PRIMARY KEY, label TEXT UNIQUE NOT NULL, kind TEXT NOT NULL,   -- google | wordpress | host
  email TEXT, auth TEXT NOT NULL DEFAULT 'oauth',                           -- oauth | session | app_password
  secret_enc TEXT, profile_dir TEXT,                                        -- encrypted token, or Chrome profile folder
  status TEXT NOT NULL DEFAULT 'active',                                    -- active | needs_login | limited | blocked | paused
  note TEXT, last_ok_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS sites(               -- a destination: a Blogger blog, a WP site or a landing page
  id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES accounts(id), platform TEXT NOT NULL,   -- blogger | wordpress | landing
  slug TEXT UNIQUE NOT NULL, title TEXT, url TEXT, remote_id TEXT,         -- blog_id / WP site id
  persona TEXT, trade TEXT, city_focus TEXT, daily_cap INTEGER DEFAULT 2, -- persona = style profile id (see generators/personas.js)
  status TEXT NOT NULL DEFAULT 'setup',                                    -- setup | live | paused
  gsc_property TEXT, sitemap_ok INTEGER DEFAULT 0, last_post_at TEXT);
CREATE TABLE IF NOT EXISTS articles(
  id INTEGER PRIMARY KEY, site_id INTEGER REFERENCES sites(id), title TEXT, labels TEXT, html TEXT, words INTEGER, fp TEXT,   -- fp = shingle fingerprint (JSON)
  topic TEXT, kind TEXT, city TEXT, persona TEXT, source TEXT,             -- source: commercial | guide | ai | manual
  status TEXT NOT NULL DEFAULT 'queued',                                   -- queued | published | rejected | failed
  reject_reason TEXT, url TEXT, remote_id TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, published_at TEXT);
CREATE TABLE IF NOT EXISTS writes(id INTEGER PRIMARY KEY, account_id INTEGER, site_id INTEGER, kind TEXT, at INTEGER, status INTEGER);   -- quota ledger (15/hour/account)
CREATE TABLE IF NOT EXISTS runs(id INTEGER PRIMARY KEY, day TEXT, win TEXT, started TEXT, ended TEXT, published INTEGER DEFAULT 0, cooling INTEGER DEFAULT 0, makkah INTEGER DEFAULT 0, errors TEXT, blocked INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS gsc_issues(id INTEGER PRIMARY KEY, site_id INTEGER, kind TEXT, detail TEXT, first_seen TEXT DEFAULT CURRENT_TIMESTAMP, resolved INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY, at TEXT DEFAULT CURRENT_TIMESTAMP, level TEXT, account_id INTEGER, site_id INTEGER, msg TEXT);
CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS lines(id INTEGER PRIMARY KEY, number TEXT UNIQUE NOT NULL, display_name TEXT, role TEXT DEFAULT 'customers', status TEXT DEFAULT 'active', daily_cap INTEGER DEFAULT 200, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS names(id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL);
CREATE INDEX IF NOT EXISTS ix_art_site ON articles(site_id,status);
`)
const q = {
  all: (s, ...a) => db.prepare(s).all(...a), get: (s, ...a) => db.prepare(s).get(...a),
  run: (s, ...a) => db.prepare(s).run(...a),
  ev: (level, msg, account_id = null, site_id = null) => db.prepare('INSERT INTO events(level,msg,account_id,site_id) VALUES(?,?,?,?)').run(level, msg, account_id, site_id),
  setting: (k, d) => { const r = db.prepare('SELECT v FROM settings WHERE k=?').get(k); return r ? JSON.parse(r.v) : d },
  setSetting: (k, v) => db.prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v').run(k, JSON.stringify(v)),
}
for (const m of ['ALTER TABLE sites ADD COLUMN line_id INTEGER', 'ALTER TABLE articles ADD COLUMN ref TEXT', 'ALTER TABLE articles ADD COLUMN recipe TEXT', 'ALTER TABLE articles ADD COLUMN kw TEXT', 'ALTER TABLE articles ADD COLUMN kind2 TEXT DEFAULT \'article\'']) { try { db.exec(m) } catch (e) { /* already there */ } }
module.exports = { db, q }
