-- Metro Mail signatures and scheduled send (SQLite)

ALTER TABLE mail_users ADD COLUMN signature_on_new INTEGER NOT NULL DEFAULT 1;
ALTER TABLE mail_users ADD COLUMN signature_on_reply INTEGER NOT NULL DEFAULT 1;

ALTER TABLE mail_messages ADD COLUMN scheduled_at TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_mail_messages_scheduled ON mail_messages(user_id, folder, scheduled_at);

CREATE TABLE IF NOT EXISTS mail_signatures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    body_html TEXT NOT NULL DEFAULT '',
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES mail_users(id) ON DELETE CASCADE,
    UNIQUE (user_id, name)
);

CREATE INDEX IF NOT EXISTS idx_mail_signatures_user ON mail_signatures(user_id, is_default DESC, updated_at DESC);
