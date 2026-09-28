-- 003: the application's own bearer tokens for its two read MCP servers and its token API
-- (mcp-and-api.md §1). Raw form `mcp_` + 48 hex chars, shown once; only the hash is stored.
-- A kernel agent never holds one of these: it presents the tenant's signed run token, which the
-- servers verify with ACTION_TOKEN_KEY (the same key, copied into config/.env by the installer).
BEGIN;

CREATE TABLE mcp_access_tokens (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    label         text NOT NULL,
    token_hash    text NOT NULL UNIQUE,            -- sha256(raw)
    scope         text NOT NULL DEFAULT 'mcp' CHECK (scope IN ('mcp', 'api')),
    last_used_at  timestamptz,
    expires_at    timestamptz,
    revoked_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mcp_access_tokens_member_idx ON mcp_access_tokens (member_id);
CREATE TRIGGER mcp_access_tokens_touch BEFORE UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
GRANT SELECT, INSERT, UPDATE ON mcp_access_tokens TO txtschedules_rw;

-- The read roles have no grant on the table: this function is their only way to resolve a token.
CREATE OR REPLACE FUNCTION mcp_resolve_token(p_hash text, p_scope text DEFAULT 'mcp')
    RETURNS TABLE (member_id bigint, member_role text, member_kind text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t
       SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = p_scope
       AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.member_id, m.business_role, m.member_kind
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = p_scope
           AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;
GRANT EXECUTE ON FUNCTION mcp_resolve_token(text, text) TO txtschedules_rw, txtschedules_records_ro, txtschedules_activity_ro;

COMMIT;
