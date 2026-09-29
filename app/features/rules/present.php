<?php
declare(strict_types=1);

/** How the rules screen is worded and what its JSON carries. Nothing on it is a wage. */

const RULES_DISCLAIMER = 'txtSchedules helps you follow your own rules; it does not guarantee legal compliance.';

function rules_url(int $siteId, array $extra = []): string
{
    return '/rules/?' . http_build_query(['site' => $siteId] + array_filter($extra, static fn ($v) => $v !== null && $v !== ''));
}

/** A rule as JSON: key, name, what it means, severity, its values with their labels and bounds. */
function present_rule(array $r): array
{
    $values = [];
    foreach ($r['help'] as $name => [$label, $unit, $type, $min, $max, $default]) {
        $values[] = ['name' => $name, 'label' => $label, 'unit' => $unit, 'type' => $type, 'min' => $min, 'max' => $max, 'value' => $r['params'][$name] ?? $default];
    }
    return ['rule' => $r['rule_key'], 'name' => $r['name'], 'explains' => $r['explains'], 'severity' => $r['severity'], 'values' => $values];
}

function present_override(array $o, string $tz): array
{
    return ['override_id' => (int) $o['override_id'], 'shift_id' => $o['shift_id'] === null ? null : (int) $o['shift_id'], 'when' => json_ts($o['created_at']), 'rule' => $o['rule_key'], 'rule_name' => $o['rule_name'],
            'message' => $o['message'], 'reason' => $o['reason'], 'context' => $o['context'], 'person' => $o['person'], 'by' => $o['by_name']];
}
