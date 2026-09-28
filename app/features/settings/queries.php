<?php
declare(strict_types=1);

/** A person's own access tokens for the two MCP servers (screen `tokens`, actions token_mint / token_revoke). */

function find_my_tokens(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT id, label, scope, created_at, last_used_at, revoked_at, expires_at FROM mcp_access_tokens WHERE member_id = :m ORDER BY revoked_at NULLS FIRST, created_at DESC');
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

/** Mint an mcp token: `mcp_` + 48 hex, stored hashed. Returns ['id', 'raw', 'label', 'scope'] — the raw value's only appearance. */
function mint_token(PDO $pdo, int $memberId, string $label): array
{
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, 'mcp') RETURNING id");
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw)]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label, 'scope' => 'mcp'];
}

/** Revoke one of the member's own live tokens. False when there is none such. */
function revoke_token(PDO $pdo, int $id, int $memberId): bool
{
    $st = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND revoked_at IS NULL RETURNING id');
    $st->execute(['id' => $id, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}

function find_my_token(PDO $pdo, int $id, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT id, label, scope, revoked_at FROM mcp_access_tokens WHERE id = :id AND member_id = :m');
    $st->execute(['id' => $id, 'm' => $memberId]);
    return $st->fetch() ?: null;
}

/** A token as the JSON answer shows it: never the hash, never the value. */
function present_token(array $t): array
{
    return ['id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at']), 'revoked' => $t['revoked_at'] !== null];
}
