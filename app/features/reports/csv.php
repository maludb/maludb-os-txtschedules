<?php
declare(strict_types=1);

/**
 * A report as a CSV download: exactly the table shown — the same columns, the same display cells, every row (not the page). UTF-8, CRLF, RFC 4180 quoting. A cell a spreadsheet would run as a formula
 * (starts with = + @ or a - that is not a number) gets a leading apostrophe. Ends the request.
 */
function csv_cell(string $v): string
{
    if ($v !== '' && (in_array($v[0], ['=', '+', '@', "\t", "\r"], true) || ($v[0] === '-' && !is_numeric($v)))) {
        $v = "'" . $v;
    }
    return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
}

/** The CSV text of a table (columns as report queries return them, rows keyed by column key). */
function csv_text(array $columns, array $rows): string
{
    $out = implode(',', array_map(static fn (array $c): string => csv_cell($c['label']), $columns)) . "\r\n";
    foreach ($rows as $r) {
        $out .= implode(',', array_map(static fn (array $c): string => csv_cell((string) ($r[$c['key']] ?? '')), $columns)) . "\r\n";
    }
    return $out;
}

/** Send it as a file called $name (letters, digits, dash) and stop. */
function stream_csv(string $name, array $columns, array $rows): never
{
    $file = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) . '.csv';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo csv_text($columns, $rows);
    exit;
}
