-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships;
-- it cannot be backfilled. Every row is shipped to the tenant's one MaluDB as an `activity`
-- episode by mcp/activity_ingest.py (payload key "application": "txtschedules" first).
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only; never a wage or an amount of pay
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,
    scope_id        bigint,                        -- the site the event happened at (scoped-applications.md §4.6); no FK — history outlives a site
    kernel_request_id text,                        -- the kernel's request id for a directory write
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_scope_idx   ON activity_log (scope_id, occurred_at DESC);

GRANT INSERT, SELECT ON activity_log TO txtschedules_rw;
REVOKE ALL ON activity_log FROM txtschedules_records_ro, txtschedules_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO txtschedules_rw;

COMMIT;
