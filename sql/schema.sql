-- Oxygen master schema. Portable between MySQL/MariaDB and SQLite (used by the test suite).
CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
    token_hash CHAR(64) PRIMARY KEY,
    csrf CHAR(64) NOT NULL,
    created INT NOT NULL,
    expires INT NOT NULL
);

CREATE TABLE IF NOT EXISTS sites (
    id VARCHAR(40) PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    host VARCHAR(190) NOT NULL,
    secret_enc TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'unpaired',
    last_checked INT NULL,
    last_info TEXT NULL,
    last_error VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS jobs (
    id VARCHAR(40) PRIMARY KEY,
    type VARCHAR(32) NOT NULL,
    site_id VARCHAR(40) NULL,
    status VARCHAR(16) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    run_after INT NOT NULL,
    created INT NOT NULL,
    finished INT NULL,
    result TEXT NULL
);

CREATE TABLE IF NOT EXISTS audit (
    id VARCHAR(40) PRIMARY KEY,
    ts INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    target VARCHAR(64) NULL,
    detail TEXT NULL
);

-- Management registry (schema v2): supervisors now, technicians later. Deactivate, never delete.
CREATE TABLE IF NOT EXISTS people (
    id VARCHAR(40) PRIMARY KEY,
    role VARCHAR(16) NOT NULL,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NULL,
    area VARCHAR(16) NULL,
    active INT NOT NULL DEFAULT 1,
    created INT NOT NULL,
    updated INT NOT NULL
);

-- Last known position of a technician (schema v3). One row per technician, replaced on each report.
CREATE TABLE IF NOT EXISTS technician_locations (
    person_id VARCHAR(40) PRIMARY KEY,
    lat DECIMAL(9,6) NOT NULL,
    lng DECIMAL(9,6) NOT NULL,
    updated INT NOT NULL
);

-- Customers (schema v4): contact, location, service history flags, tier and last agreed price.
CREATE TABLE IF NOT EXISTS customers (
    id VARCHAR(40) PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    phone VARCHAR(20) NULL,
    address VARCHAR(200) NULL,
    lat DECIMAL(9,6) NULL,
    lng DECIMAL(9,6) NULL,
    tier VARCHAR(16) NOT NULL DEFAULT 'medium',
    rating INT NULL,
    rating_note VARCHAR(200) NULL,
    last_price INT NULL,
    created INT NOT NULL,
    updated INT NOT NULL
);

-- Orders (schema v4): one job for one customer. Active statuses: new, assigned, in_progress.
CREATE TABLE IF NOT EXISTS orders (
    id VARCHAR(40) PRIMARY KEY,
    customer_id VARCHAR(40) NOT NULL,
    technician_id VARCHAR(40) NULL,
    service VARCHAR(80) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    price INT NULL,
    warranty_until INT NULL,
    created INT NOT NULL,
    updated INT NOT NULL
);

-- Orders received from the face websites (schema v5). One row per site order. The code is site id plus site reference.
CREATE TABLE IF NOT EXISTS site_orders (
    id VARCHAR(40) PRIMARY KEY,
    site_id VARCHAR(40) NOT NULL,
    site_ref VARCHAR(40) NOT NULL,
    code VARCHAR(80) NOT NULL,
    order_id VARCHAR(40) NOT NULL,
    source VARCHAR(16) NOT NULL,
    payload TEXT NOT NULL,
    received INT NOT NULL
);

-- Messages on an order (schema v6): between the customer, the supervisor and the technician. Kept with the order.
CREATE TABLE IF NOT EXISTS order_messages (
    id VARCHAR(40) PRIMARY KEY,
    order_id VARCHAR(40) NOT NULL,
    sender VARCHAR(16) NOT NULL,
    body VARCHAR(500) NOT NULL,
    created INT NOT NULL,
    seq INT NOT NULL DEFAULT 0
);

-- Technician share of a job's price (schema v7). Missing row means the default share from Dispatch applies.
CREATE TABLE IF NOT EXISTS technician_shares (
    person_id VARCHAR(40) PRIMARY KEY,
    share_pct INT NOT NULL
);
