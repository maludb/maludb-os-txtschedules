<?php
declare(strict_types=1);

/** Announcements as screens and JSON show them — a whitelist, never a raw row. No pay, phone or email is in any of these fields. */

/** Plain text with line breaks and links → safe HTML ($text is escaped first; only http(s) links become anchors). */
function announcement_html(string $text): string
{
    $h = nl2br(e($text), false);
    return (string) preg_replace('#(https?://[^\s<]+)#', '<a href="$1" rel="noopener nofollow" target="_blank">$1</a>', $h);
}

function present_announcement(array $a, ?array $readers = null): array
{
    $out = ['announcement_id' => $a['announcement_id'], 'site_id' => $a['site_id'], 'title' => $a['title'], 'body' => $a['body'] ?? null, 'audience' => $a['audience'], 'position_id' => $a['position_id'],
        'pinned' => $a['pinned'] ?? false, 'pinned_until' => $a['pinned_until'], 'posted_by' => $a['posted_by_name'], 'posted_at' => json_ts($a['created_at']), 'read_by_me' => $a['read_by_me']];
    if ($a['read_count'] !== null) {
        $out['read_count'] = $a['read_count'];
    }
    if ($readers !== null) {
        $out['readers'] = array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'], 'name' => $r['name'], 'read_at' => json_ts($r['read_at'])], $readers);
    }
    return $out;
}

/** The banner an action of this slice lands with (?notice=), by key — a whitelist, never request text. */
function notify_notice(?string $key): ?array
{
    return match ($key) {
        'an_posted' => ['success', 'Announcement posted.'],
        'an_removed' => ['secondary', 'The announcement is removed.'],
        'an_read' => ['success', 'Marked as read.'],
        'pf_saved' => ['success', 'Saved — this is how you will be told.'],
        'cf_made' => ['success', 'Your calendar link is ready — copy it now; it is shown only once.'],
        'cf_rotated' => ['success', 'Your old calendar link no longer works. The new one is below — copy it now; it is shown only once.'],
        default => null,
    };
}
