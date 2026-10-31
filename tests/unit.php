<?php

declare(strict_types=1);

/**
 * Unit tests: no osTicket, no database.
 *
 *   php tests/unit.php
 *
 * Covers everything that decides what a viewer sees: grouping, nesting,
 * escaping, the navigation injection and the config clamps.
 */

define('INCLUDE_DIR', __DIR__ . '/stubs/');
define('ROOT_PATH', '/');

$dir = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
require $dir . '/class.BoardPlugin.php';

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

function row(int $id, int $status, int $pid = 0, array $extra = array()): array
{
    return array_merge(array(
        'id' => $id, 'number' => sprintf('%06d', $id), 'pid' => $pid, 'status_id' => $status,
        'subject' => "Ticket $id", 'updated' => "2026-10-0$id", 'updated_raw' => "2026-10-0$id 10:00:00",
        'dept' => 'Support', 'assignee' => '', 'user' => 'Requester', 'overdue' => false,
    ), $extra);
}

$statuses = array(
    array('id' => 3, 'name' => 'Closed', 'state' => 'closed', 'sort' => 1),
    array('id' => 1, 'name' => 'Open', 'state' => 'open', 'sort' => 1),
    array('id' => 2, 'name' => 'In progress', 'state' => 'open', 'sort' => 2),
);

$strings = BoardI18n::strings('en_US');

// --------------------------------------------------------------- BoardModel

$b = BoardModel::build($statuses, array());
check('columns: open states first, then by sort', array_column(array_column($b['columns'], 'status'), 'id') === array(1, 2, 3));
check('columns: exist without tickets', count($b['columns']) === 3 && $b['count'] === 0);

$b = BoardModel::build($statuses, array(row(1, 1), row(2, 2, 1), row(3, 3, 1), row(4, 1)));
$open = $b['columns'][0]['cards'];
check('children: nested under parent, not shown as own card', count($open) === 2 && count($b['columns'][2]['cards']) === 0);
$parent = array_values(array_filter($open, fn($c) => $c['id'] === 1))[0];
check('children: carry their own status', array_column($parent['children'], 'status_name') === array('In progress', 'Closed'));

$b = BoardModel::build($statuses, array(row(5, 2, 99)));
check('orphan: child of an invisible parent stands alone', count($b['columns'][1]['cards']) === 1);

$b = BoardModel::build($statuses, array(row(1, 1), row(2, 1, 1), row(3, 2, 2)));
check('grandchild: flattened under the top-most visible ancestor', count($b['columns'][0]['cards']) === 1
    && count($b['columns'][0]['cards'][0]['children']) === 2);

$visible = function (array $board): array {
    $ids = array();
    foreach ($board['columns'] as $col) {
        foreach ($col['cards'] as $c) {
            $ids[] = $c['id'];
            foreach ($c['children'] as $k) {
                $ids[] = $k['id'];
            }
        }
    }
    sort($ids);
    return $ids;
};
$b = BoardModel::build($statuses, array(row(1, 1, 2), row(2, 1, 1)));
check('cycle: both tickets stay visible', $visible($b) === array(1, 2) && count($b['columns'][0]['cards']) === 1);
$b = BoardModel::build($statuses, array(row(3, 1, 1), row(1, 1, 2), row(2, 1, 1)));
check('cycle with tail: every ticket visible exactly once', $visible($b) === array(1, 2, 3));

$b = BoardModel::build($statuses, array(row(1, 7), row(2, 1, 1)));
check('unknown status (archived/deleted): dropped, does not anchor children',
    $b['count'] === 1 && count($b['columns'][0]['cards']) === 1 && $b['columns'][0]['cards'][0]['id'] === 2);

$b = BoardModel::build($statuses, array(row(1, 1), row(2, 1), row(3, 1)));
check('order: most recently updated first', array_column($b['columns'][0]['cards'], 'id') === array(3, 2, 1));

// ------------------------------------------------------------ BoardRenderer

$xss = '<img src=x onerror=alert(1)>"\'';
$b = BoardModel::build($statuses, array(
    row(1, 1, 0, array('subject' => $xss, 'user' => $xss, 'assignee' => 'Agent A', 'dept' => $xss)),
    row(2, 2, 1, array('subject' => $xss)),
));
$staffHtml = BoardRenderer::render($b, array('mode' => 'staff', 'ticket_url' => '/scp/tickets.php?id=',
    'move_url' => '/scp/ajax.php/board/move', 'strings' => $strings, 'closed_days' => 14, 'max_cards' => 300, 'truncated' => false));
$clientHtml = BoardRenderer::render($b, array('mode' => 'client', 'ticket_url' => '/tickets.php?id=',
    'strings' => $strings, 'closed_days' => 14, 'max_cards' => 300, 'truncated' => true));

check('escape: no raw markup from ticket data', strpos($staffHtml, '<img') === false && strpos($clientHtml, '<img') === false);
check('escape: quotes encoded', strpos($staffHtml, '&quot;&#039;') !== false);
check('staff: cards draggable, move URL present', substr_count($staffHtml, 'draggable="true"') === 1
    && strpos($staffHtml, 'data-move-url="/scp/ajax.php/board/move"') !== false);
check('staff: assignee and requester shown', strpos($staffHtml, 'Agent A') !== false && strpos($staffHtml, '>Requester<') !== false);
check('client: nothing draggable, no move URL', strpos($clientHtml, 'draggable') === false && strpos($clientHtml, 'data-move-url') === false);
check('client: no assignee, no requester field', strpos($clientHtml, 'Agent A') === false
    && strpos($clientHtml, '>' . $strings['assignee'] . '<') === false
    && strpos($clientHtml, '>' . $strings['requester'] . '<') === false);
check('client: links point to the portal ticket view', strpos($clientHtml, 'href="/tickets.php?id=1"') !== false
    && strpos($clientHtml, '/scp/') === false);
check('render: sub-ticket summary and child link', strpos($staffHtml, '1 sub-ticket') !== false
    && strpos($staffHtml, 'href="/scp/tickets.php?id=2"') !== false);
check('render: truncation notice only when truncated', strpos($clientHtml, 'spb-warn') !== false && strpos($staffHtml, 'spb-warn') === false);
check('i18n: German strings, English fallback', BoardI18n::strings('de')['empty'] === 'Keine Tickets'
    && BoardI18n::strings('xx')['empty'] === 'No tickets' && BoardI18n::strings(null)['empty'] === 'No tickets');

// ------------------------------------------------------------- NavInjector

$page = "<div><ul id=\"nav\" class=\"flush-left\">\n<li><a href=\"/\">Home</a></li>\n</ul></div><ul><li>x</li></ul>";
$out = NavInjector::inject($page, '/ajax.php/board', 'Board', false);
check('nav: entry placed inside the portal nav', strpos($out, '<li><a class="spb-nav" href="/ajax.php/board">Board</a></li>' . "\n" . '</ul></div>') !== false);
check('nav: idempotent', NavInjector::inject($out, '/ajax.php/board', 'Board', false) === $out);
check('nav: page without portal nav untouched', NavInjector::inject('<ul><li>x</li></ul>', '/a', 'B', false) === '<ul><li>x</li></ul>');
check('nav: unterminated list untouched', NavInjector::inject('<ul id="nav">', '/a', 'B', false) === '<ul id="nav">');
check('nav: active flag and escaping', strpos(NavInjector::inject($page, '/a?"x', '<B>', true), 'class="spb-nav active" href="/a?&quot;x">&lt;B&gt;</a>') !== false);

// --------------------------------------------------------------- settings

$n = BoardController::normalise(array());
check('defaults: client board off, agent board on', $n['enable_client'] === false && $n['enable_staff'] === true);
$n = BoardController::normalise(array('closed_days' => '-5', 'max_cards' => '999999'));
check('clamp: closed_days >= 0, max_cards <= 2000', $n['closed_days'] === 0 && $n['max_cards'] === 2000);
$n = BoardController::normalise(array('closed_days' => '9999', 'max_cards' => '1'));
check('clamp: closed_days <= 365, max_cards >= 10', $n['closed_days'] === 365 && $n['max_cards'] === 10);

// -------------------------------------------------------- output buffering

$web = array('/tickets.php', '/apps/dispatcher.php', '/support/view.php', '/kb/index.php');
$skip = array('/file.php', '/support/file.php', '/scp/index.php', '/api/http.php', '/ajax.php', '/logo.php', '/avatar.php', '');
$isPortal = fn($s) => BoardPlugin::isPortalPath($s);
check('buffer: portal pages get it', array_map($isPortal, $web) === array_fill(0, 4, true));
check('buffer: downloads, agent panel, API, AJAX do not', array_map($isPortal, $skip) === array_fill(0, 8, false));
check('buffer: never under the CLI', BoardPlugin::isPortalPage('/index.php') === false);
check('buffer: ajax.php only for the board page itself',
    BoardPlugin::isPortalPath('/ajax.php', '/board') === true
    && BoardPlugin::isPortalPath('/support/ajax.php', '/board/') === true
    && BoardPlugin::isPortalPath('/ajax.php', '/board/assets/board.css') === false
    && BoardPlugin::isPortalPath('/ajax.php', '/form/upload/1') === false
    && BoardPlugin::isPortalPath('/ajax.php', '') === false
    && BoardPlugin::isPortalPath('/scp/ajax.php', '/board') === false);

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
