<?php
declare(strict_types=1);

/** How the reports screen is linked and what its JSON carries. */

function reports_url(int $siteId, string $report = '', array $extra = []): string
{
    return '/reports/?' . http_build_query(array_filter(['report' => $report, 'site' => $siteId] + $extra, static fn ($v) => $v !== null && $v !== ''));
}

/** A report as JSON: the page's rows with the columns' names — display text, as shown. */
function present_report(string $key, array $table, int $page, int $total, string $from, string $to): array
{
    return ['report' => $key, 'title' => REPORTS[$key][0], 'from' => $from, 'to' => $to, 'columns' => array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label']], $table['columns']),
            'rows' => $table['rows'], 'page' => $page, 'per_page' => REPORT_PAGE, 'total' => $total];
}
