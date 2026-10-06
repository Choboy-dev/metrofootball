-- Metro Mail core schema (SQLite)

CREATE TABLE IF NOT EXISTS mail_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    display_name TEXT NOT NULL DEFAULT '',
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    is_active INTEGER NOT NULL DEFAULT 1,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    avatar_color TEXT NOT NULL DEFAULT '#dc2626',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS mail_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    folder TEXT NOT NULL DEFAULT 'inbox' CHECK (folder IN ('inbox', 'sent', 'drafts', 'scheduled', 'archive', 'spam', 'trash')),
    category TEXT NOT NULL DEFAULT 'primary' CHECK (category IN ('primary', 'promotions', 'social', 'updates')),
    direction TEXT NOT NULL CHECK (direction IN ('inbound', 'outbound')),
    from_email TEXT NOT NULL,
    from_name TEXT NOT NULL DEFAULT '',
    to_emails TEXT NOT NULL DEFAULT '[]',
    cc_emails TEXT NOT NULL DEFAULT '[]',
    bcc_emails TEXT NOT NULL DEFAULT '[]',
    subject TEXT NOT NULL DEFAULT '',
    body_html TEXT NOT NULL DEFAULT '',
    body_text TEXT NOT NULL DEFAULT '',
    snippet TEXT NOT NULL DEFAULT '',
    is_read INTEGER NOT NULL DEFAULT 0,
    is_starred INTEGER NOT NULL DEFAULT 0,
    thread_id TEXT NULL,
    message_id TEXT NULL,
    in_reply_to TEXT NULL,
    resend_email_id TEXT NULL UNIQUE,
    sent_at TEXT NULL,
    received_at TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES mail_users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mail_messages_user_folder ON mail_messages(user_id, folder, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_mail_messages_user_category ON mail_messages(user_id, category, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_mail_messages_resend ON mail_messages(resend_email_id);
CREATE INDEX IF NOT EXISTS idx_mail_messages_starred ON mail_messages(user_id, is_starred) WHERE is_starred = 1;

CREATE TABLE IF NOT EXISTS mail_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    content_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    storage_path TEXT NOT NULL,
    resend_attachment_id TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (message_id) REFERENCES mail_messages(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_mail_attachments_message ON mail_attachments(message_id);

CREATE TABLE IF NOT EXISTS mail_labels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#5f6368',
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES mail_users(id) ON DELETE CASCADE,
    UNIQUE (user_id, name)
);

CREATE TABLE IF NOT EXISTS mail_message_labels (
    message_id INTEGER NOT NULL,
    label_id INTEGER NOT NULL,
    PRIMARY KEY (message_id, label_id),
    FOREIGN KEY (message_id) REFERENCES mail_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (label_id) REFERENCES mail_labels(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS mail_login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    attempted_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_mail_login_attempts_ip ON mail_login_attempts(ip_address, attempted_at DESC);
