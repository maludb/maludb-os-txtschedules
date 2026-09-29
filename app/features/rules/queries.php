<?php
declare(strict_types=1);

/**
 * The rules a restaurant runs (slice 7): each of the engine's twelve kinds (db/009) is hard (refused), soft (a warning a manager overrides with a reason) or off, and a few carry values. The engine
 * (`ts_assignment_warnings()`) reads `site_rules` on every question, so a change here reaches the next builder check, publish, claim and tool answer — nothing is cached. Functions take the
 * restaurant from the handler (settings.manage already asked), open no transaction, log nothing, refuse with a DomainException.
 */

const RULE_SEVERITIES = ['hard' => 'Hard (refuse)', 'soft' => 'Soft (warn)', 'off' => 'Off'];

/**
 * The values a kind carries: name => [label, unit, type (int|dec|time), min, max, default]. min_rest and break's minutes are WHOLE numbers because the engine casts them to integer.
 * A kind not listed has no values.
 */
const RULE_PARAMS = [
    'min_rest'         => ['hours' => ['Hours of rest', 'hours', 'int', 1, 48, 10]],
    'max_hours_day'    => ['hours' => ['Most hours in a day', 'hours', 'dec', 1, 24, 12]],
    'break_required'   => ['after_hours' => ['A shift longer than', 'hours', 'dec', 1, 24, 6], 'minutes' => ['needs a break of', 'minutes', 'int', 5, 180, 30]],
    'minor_hours_day'  => ['hours' => ['A minor: most hours in a day', 'hours', 'dec', 1, 24, 8]],
    'minor_hours_week' => ['hours' => ['A minor: most hours in a week', 'hours', 'dec', 1, 80, 40]],
    'minor_latest_end' => ['time' => ['A minor may work until', 'a time of day', 'time', null, null, '22:00']],
];

/** Every kind of rule the engine knows with what this restaurant runs: key, name, explains, severity, params (the values, defaults filled), help (the value fields), updated_at. In the engine's order. */
function find_site_rules(PDO $pdo, int $siteId): array
{
    $st = $pdo->prepare("SELECT k.key AS rule_key, k.name, k.explains, k.params_help, COALESCE(r.severity, 'off') AS severity, COALESCE(r.params, '{}'::jsonb) AS params, r.updated_at
                           FROM rule_kinds k LEFT JOIN mcp_site_rules r ON r.rule_key = k.key AND r.site_id = :s ORDER BY k.sort_order, k.key");
    $st->execute(['s' => $siteId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $stored = json_decode((string) $r['params'], true) ?: [];
        $r['params'] = [];
        foreach (RULE_PARAMS[$r['rule_key']] ?? [] as $name => $spec) {
            $r['params'][$name] = $stored[$name] ?? $spec[5];
        }
        $r['help'] = RULE_PARAMS[$r['rule_key']] ?? [];
    }
    unset($r);
    return $rows;
}

/** One rule's current row (severity, stored params exactly), or null when the restaurant has none for it. */
function site_rule_row(PDO $pdo, int $siteId, string $ruleKey): ?array
{
    $st = $pdo->prepare('SELECT severity, params FROM site_rules WHERE scope_id = :s AND rule_key = :k');
    $st->execute(['s' => $siteId, 'k' => $ruleKey]);
    $r = $st->fetch();
    return $r === false ? null : ['severity' => $r['severity'], 'params' => json_decode((string) $r['params'], true) ?: []];
}

/** Does the engine know this kind? */
function rule_kind_name(PDO $pdo, string $ruleKey): ?string
{
    $st = $pdo->prepare('SELECT name FROM rule_kinds WHERE key = :k');
    $st->execute(['k' => $ruleKey]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

/**
 * The values of one kind, checked: $given are the names sent (a value left out keeps what $current holds, else the starting value). Returns the params to store — whole numbers as integers,
 * a time as "HH:MM". A name the kind does not have, a number out of its range, a time like 25:99 are refused in words.
 */
function normalise_rule_params(string $ruleKey, array $given, array $current): array
{
    $spec = RULE_PARAMS[$ruleKey] ?? [];
    $extra = array_diff(array_keys($given), array_keys($spec));
    if ($extra !== []) {
        throw new DomainException($spec === [] ? 'This rule has no values to set.' : 'This rule has no value called ' . implode(', ', array_map('strval', $extra)) . '.');
    }
    $out = [];
    foreach ($spec as $name => [$label, $unit, $type, $min, $max, $default]) {
        if (!array_key_exists($name, $given)) {
            if (array_key_exists($name, $current)) {
                $out[$name] = $current[$name];
            }
            continue;
        }
        $v = is_string($given[$name]) ? trim($given[$name]) : $given[$name];
        if ($type === 'time') {
            $t = is_string($v) ? clock_text($v) : null;
            if ($t === null) {
                throw new DomainException($label . ' is a time of day like 22:00.');
            }
            $out[$name] = $t;
            continue;
        }
        $words = $label . ' is ' . ($type === 'int' ? 'a whole number' : 'a number') . ' of ' . $unit . ' from ' . $min . ' to ' . $max . '.';
        if (!is_scalar($v) || is_bool($v) || !is_numeric((string) $v)) {
            throw new DomainException($words);
        }
        $n = (float) $v;
        if ($n < $min || $n > $max || ($type === 'int' && $n !== floor($n))) {
            throw new DomainException($words);
        }
        $out[$name] = $type === 'int' || $n === floor($n) ? (int) $n : round($n, 2);
    }
    return $out;
}

/**
 * Set one rule: its severity and its values. $severity null keeps the current one; $params are the values sent. Answers ['before' => ?['severity', 'params'], 'after' => [...], 'changed' => bool];
 * an unchanged rule is not written. The engine reads the row on its next question.
 */
function save_rule(PDO $pdo, int $siteId, string $ruleKey, ?string $severity, array $params, int $by): array
{
    if (rule_kind_name($pdo, $ruleKey) === null) {
        throw new DomainException('There is no rule called ' . $ruleKey . '.');
    }
    if ($severity !== null && !isset(RULE_SEVERITIES[$severity])) {
        throw new DomainException('A rule is hard, soft or off.');
    }
    $before = site_rule_row($pdo, $siteId, $ruleKey);
    $cur = $before ?? ['severity' => 'off', 'params' => []];
    $norm = normalise_rule_params($ruleKey, $params, $cur['params']);
    $after = ['severity' => $severity ?? $cur['severity'], 'params' => $norm];
    if ($before !== null && $before['severity'] === $after['severity'] && $before['params'] == $after['params']) {
        return ['before' => $before, 'after' => $after, 'changed' => false];
    }
    $pdo->prepare('INSERT INTO site_rules (scope_id, rule_key, severity, params, updated_by) VALUES (:s, :k, :v, CAST(:p AS jsonb), :u)
                   ON CONFLICT (scope_id, rule_key) DO UPDATE SET severity = EXCLUDED.severity, params = EXCLUDED.params, updated_by = EXCLUDED.updated_by, updated_at = now()')
        ->execute(['s' => $siteId, 'k' => $ruleKey, 'v' => $after['severity'], 'p' => json_encode($norm === [] ? new stdClass() : $norm), 'u' => $by]);
    return ['before' => $before, 'after' => $after, 'changed' => true];
}

/**
 * Put every rule the preset names back to its starting values (severity and values), and record the preset on the restaurant. Answers ['preset', 'name', 'rules_changed' => int, 'keys' => [changed keys]].
 * A preset is a row in rule_presets: another one is data, not code.
 */
function apply_preset(PDO $pdo, int $siteId, string $preset, int $by): array
{
    $st = $pdo->prepare('SELECT key, name, rules FROM rule_presets WHERE key = :k');
    $st->execute(['k' => $preset]);
    $p = $st->fetch();
    if ($p === false) {
        throw new DomainException('There is no preset called ' . $preset . '.');
    }
    $changed = [];
    foreach (json_decode((string) $p['rules'], true) ?: [] as $key => $def) {
        $before = site_rule_row($pdo, $siteId, (string) $key);
        $params = $def['params'] ?? [];
        $after = ['severity' => (string) $def['severity'], 'params' => $params];
        if ($before !== null && $before['severity'] === $after['severity'] && $before['params'] == $after['params']) {
            continue;
        }
        $pdo->prepare('INSERT INTO site_rules (scope_id, rule_key, severity, params, updated_by) VALUES (:s, :k, :v, CAST(:p AS jsonb), :u)
                       ON CONFLICT (scope_id, rule_key) DO UPDATE SET severity = EXCLUDED.severity, params = EXCLUDED.params, updated_by = EXCLUDED.updated_by, updated_at = now()')
            ->execute(['s' => $siteId, 'k' => $key, 'v' => $after['severity'], 'p' => json_encode($params === [] ? new stdClass() : $params), 'u' => $by]);
        $changed[] = (string) $key;
    }
    $pdo->prepare('UPDATE site_settings SET rule_preset = :p, updated_by = :u WHERE scope_id = :s')->execute(['p' => $preset, 'u' => $by, 's' => $siteId]);
    return ['preset' => (string) $p['key'], 'name' => (string) $p['name'], 'rules_changed' => count($changed), 'keys' => $changed];
}

/**
 * The rule overrides of the last $days days (mcp_rule_overrides — schedule.build at the restaurant), newest first: override_id, shift_id, when (UTC text), rule_key, rule_name, message, reason,
 * context, person (whom it was about), by (who went ahead).
 */
function recent_overrides(PDO $pdo, int $siteId, int $days = 30): array
{
    $st = $pdo->prepare("SELECT o.override_id, o.shift_id, o.created_at, o.rule_key, k.name AS rule_name, o.message, o.reason, o.context, p.display_name AS person, b.display_name AS by_name
                           FROM mcp_rule_overrides o JOIN rule_kinds k ON k.key = o.rule_key
                           LEFT JOIN mcp_members p ON p.member_id = o.member_id LEFT JOIN mcp_members b ON b.member_id = o.overridden_by
                          WHERE o.site_id = :s AND o.created_at >= now() - make_interval(days => CAST(:d AS integer)) ORDER BY o.created_at DESC, o.override_id DESC");
    $st->execute(['s' => $siteId, 'd' => $days]);
    return $st->fetchAll();
}
