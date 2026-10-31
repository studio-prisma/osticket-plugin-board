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

// ------------------------------------------------------------ queue picker

$queues = array(
    array('id' => 1, 'label' => 'Open', 'depth' => 0, 'group' => 'queue'),
    array('id' => 2, 'label' => 'Overdue', 'depth' => 1, 'group' => 'queue'),
    array('id' => 20, 'label' => '<b>Acme</b> "mine"', 'depth' => 0, 'group' => 'search'),
);
$pickerCtx = array('mode' => 'staff', 'ticket_url' => '/scp/tickets.php?id=', 'move_url' => '/m',
    'strings' => $strings, 'closed_days' => 0, 'max_cards' => 300, 'truncated' => false,
    'queues' => $queues, 'queue_id' => 2, 'queue_missing' => false,
    'queue_action' => '/scp/ajax.php/board', 'search_url' => '/scp/tickets.php');
$html = BoardRenderer::render(BoardModel::build($statuses, array()), $pickerCtx);
check('picker: GET form to the board route', strpos($html, '<form class="spb-toolbar" method="get" action="/scp/ajax.php/board">') !== false);
check('picker: default view first, current queue selected', strpos($html, '<option value="all">') !== false
    && strpos($html, '<option value="2" selected>') !== false && substr_count($html, ' selected') === 1);
check('picker: queues and searches in separate groups', strpos($html, '<optgroup label="Queues">') < strpos($html, '<optgroup label="Saved searches">'));
check('picker: sub-queue indented', strpos($html, "<option value=\"2\" selected>\u{00A0}\u{00A0}\u{00A0}Overdue</option>") !== false);
check('picker: names escaped', strpos($html, '<b>Acme') === false && strpos($html, '&lt;b&gt;Acme&lt;/b&gt; &quot;mine&quot;') !== false);
check('picker: no missing notice by default', strpos($html, $strings['queue_missing']) === false);
$html = BoardRenderer::render(BoardModel::build($statuses, array()), array_merge($pickerCtx, array('queue_id' => 0, 'queue_missing' => true)));
check('picker: missing notice, default view selected', strpos($html, $strings['queue_missing']) !== false
    && strpos($html, '<option value="all" selected>') !== false);
check('picker: never on the client board', strpos(BoardRenderer::render(BoardModel::build($statuses, array()),
    array_merge($pickerCtx, array('mode' => 'client'))), 'spb-toolbar') === false);

$session = array();
check('queue: no parameter, nothing remembered', BoardController::selectedQueueId(array(), $session) === 0);
check('queue: ?queue=5 selects and remembers', BoardController::selectedQueueId(array('queue' => '5'), $session) === 5
    && BoardController::selectedQueueId(array(), $session) === 5);
check('queue: ?queue=all forgets', BoardController::selectedQueueId(array('queue' => 'all'), $session) === 0
    && BoardController::selectedQueueId(array(), $session) === 0);
$session = array();
check('queue: junk is the default view, not remembered', BoardController::selectedQueueId(array('queue' => '5 OR 1=1'), $session) === 0
    && BoardController::selectedQueueId(array('queue' => array('5')), $session) === 0
    && BoardController::selectedQueueId(array('queue' => '-3'), $session) === 0
    && $session === array());

// ------------------------------------------------------------ quick view

$statusList = array(
    array('id' => 1, 'name' => 'Open', 'state' => 'open'),
    array('id' => 6, 'name' => 'In Progress', 'state' => 'open'),
    array('id' => 3, 'name' => 'Closed', 'state' => 'closed'),
);
$all = array('id' => 7, 'status_id' => 1, 'open' => true, 'assigned' => false, 'has_staff' => false,
    'closeable' => true, 'answered' => false, 'can_close' => true, 'can_assign' => true, 'can_claim' => true,
    'can_release' => true, 'can_transfer' => true, 'can_refer' => true, 'can_mark_answered' => true,
    'can_edit' => true, 'priority_field_id' => 22, 'can_create_task' => true);
// One key per action: the act (with value), the picker, or the dialog path.
$keys = fn($facts) => array_map(static function ($a) {
    if (isset($a['act'])) {
        return $a['act'] . ($a['value'] !== '' ? ':' . $a['value'] : '');
    }
    return isset($a['menu']) ? 'menu:' . $a['menu'] . '>' . $a['menu_act'] : 'dialog:' . $a['path'];
}, BoardActions::build($facts, $statusList, $strings));

check('actions: full role on an open, unassigned ticket', $keys($all) === array(
    'status:6', 'status:3',
    'menu:agents>assign_agent', 'menu:teams>assign_team', 'claim',
    'menu:depts>transfer', 'dialog:tickets/7/refer',
    'answered', 'menu:priority>priority', 'dialog:tickets/7/add-task'));
$none = array_merge($all, array('can_close' => false, 'can_assign' => false, 'can_claim' => false,
    'can_release' => false, 'can_transfer' => false, 'can_refer' => false, 'can_mark_answered' => false,
    'can_edit' => false, 'can_create_task' => false));
check('actions: view-only role gets none', $keys($none) === array());
check('actions: not closeable hides closed states only', !in_array('status:3', $keys(array_merge($all, array('closeable' => false))), true)
    && in_array('status:6', $keys(array_merge($all, array('closeable' => false))), true));
$assigned = $keys(array_merge($all, array('assigned' => true, 'has_staff' => true, 'answered' => true)));
check('actions: assigned ticket offers release, no claim, mark unanswered', in_array('release', $assigned, true)
    && !in_array('claim', $assigned, true) && in_array('unanswered', $assigned, true));
$closedT = $keys(array_merge($all, array('open' => false, 'status_id' => 3)));
check('actions: closed ticket, no assign or mark, reopen offered', !preg_grep('#^(menu:agents|menu:teams|claim|answered|unanswered)#', $closedT)
    && in_array('status:1', $closedT, true) && !in_array('status:3', $closedT, true));
check('actions: no priority field, no priority action', !in_array('menu:priority>priority', $keys(array_merge($all, array('priority_field_id' => 0))), true));
check('actions: every act is one the endpoint accepts', !array_diff(array_merge(
    array_column(BoardActions::build($all, $statusList, $strings), 'act'),
    array_column(BoardActions::build($all, $statusList, $strings), 'menu_act'),
    array_column(BoardActions::build(array_merge($all, array('assigned' => true, 'has_staff' => true, 'answered' => true)), $statusList, $strings), 'act')
), BoardActions::ACTS));
check('actions: status entries carry their state', array_column(array_filter(BoardActions::build($all, $statusList, $strings),
    static fn($a) => ($a['act'] ?? '') === 'status'), 'state') === array('open', 'closed'));

check('options: flattened, grouped lists merged, current left out', BoardActions::options(array(
    'Agents' => array('s1' => 'Ann', 's2' => 'Bob'), 't3' => 'Team', '' => '-- none --'), 's2') === array(
    array('value' => 's1', 'label' => 'Ann'), array('value' => 't3', 'label' => 'Team')));
check('options: empty input gives no options', BoardActions::options(null) === array() && BoardActions::options(array()) === array());

$panel = BoardRenderer::panel(array('id' => 7, 'number' => '00<7>', 'subject' => '<img src=x onerror=1>',
    'ticket_url' => '/scp/tickets.php?id=7', 'note_url' => '/scp/ajax.php/board/note', 'act_url' => '/scp/ajax.php/board/act',
    'actions' => BoardActions::build($all, $statusList, $strings), 'preview_html' => '<div id="core">core</div>',
    'menus' => array('agents' => array(array('value' => 's2', 'label' => '<b>Bob</b>')), 'teams' => array(),
        'depts' => array(array('value' => '4', 'label' => 'Sales')), 'priority' => array(array('value' => '3', 'label' => 'High'))),
    'strings' => $strings));
check('panel: ticket data escaped', strpos($panel, '<img') === false && strpos($panel, '#00&lt;7&gt; &lt;img') !== false);
check('panel: one button per action, run directly or through a picker', preg_match_all('#class="spb-action( |")#', $panel) === 10
    && strpos($panel, 'data-spb-act="status" data-value="3" data-state="closed"') !== false
    && strpos($panel, 'data-spb-act="claim" data-value=""') !== false
    && strpos($panel, 'data-spb-menu="agents"') !== false
    && strpos($panel, 'data-act-url="/scp/ajax.php/board/act"') !== false);
check('panel: only refer and add task open core dialogs', substr_count($panel, 'data-spb-dialog=') === 2
    && strpos($panel, 'data-spb-dialog="tickets/7/refer"') !== false);
check('panel: picker entries escaped and bound to their act', strpos($panel, '<b>Bob') === false
    && strpos($panel, 'data-spb-act="assign_agent" data-value="s2">&lt;b&gt;Bob&lt;/b&gt;') !== false
    && strpos($panel, 'data-spb-act="transfer" data-value="4"') !== false);
check('panel: empty picker says so', strpos($panel, '<div class="spb-menu" data-menu="teams" hidden><p class="spb-hint">' . $strings['menu_empty']) !== false);
check('panel: note field comes before the actions', strpos($panel, 'spb-note') < strpos($panel, 'spb-actions'));
check('panel: core preview passed through unchanged', strpos($panel, '<div class="spb-preview"><div id="core">core</div></div>') !== false);
check('panel: note form posts ticket id to the note route', strpos($panel, 'action="/scp/ajax.php/board/note"') !== false
    && strpos($panel, 'name="ticket_id" value="7"') !== false && strpos($panel, 'maxlength="' . BoardController::NOTE_MAX . '"') !== false);
check('panel: empty groups are left out', strpos(BoardRenderer::panel(array_merge(array('id' => 7, 'number' => '1', 'subject' => 's',
    'ticket_url' => '/', 'note_url' => '/', 'preview_html' => '', 'strings' => $strings), array('actions' => array()))), 'spb-action-group') === false);

check('note: trimmed text accepted', BoardController::noteText("  hello\r\nworld  ") === "hello\nworld");
check('note: empty, whitespace, non-string refused', BoardController::noteText('') === null
    && BoardController::noteText(" \n ") === null && BoardController::noteText(array('x')) === null && BoardController::noteText(null) === null);
check('note: length cap', BoardController::noteText(str_repeat('a', BoardController::NOTE_MAX)) !== null
    && BoardController::noteText(str_repeat('a', BoardController::NOTE_MAX + 1)) === null);
check('note: invalid UTF-8 refused', BoardController::noteText("ok \xC3\x28") === null);

$html = BoardRenderer::render(BoardModel::build($statuses, array(row(1, 1))), array_merge($pickerCtx, array('panel_url' => '/scp/ajax.php/board/panel/')));
check('board: panel URL and empty drawer only with panel_url', strpos($html, 'data-panel-url="/scp/ajax.php/board/panel/"') !== false
    && strpos($html, '<aside class="spb-drawer" hidden') !== false
    && strpos(BoardRenderer::render(BoardModel::build($statuses, array()), $pickerCtx), 'spb-drawer') === false);

// ----------------------------------------------------- sort, due, priority

$rich = array(
    row(1, 1, 0, array('due_raw' => '', 'urgency' => 3, 'created_raw' => '2026-10-01 10:00:00', 'number' => '000010')),
    row(2, 1, 0, array('due_raw' => '2026-10-05 12:00:00', 'urgency' => 1, 'created_raw' => '2026-10-03 10:00:00', 'number' => '000002')),
    row(3, 1, 0, array('due_raw' => '2026-10-02 12:00:00', 'urgency' => 0, 'created_raw' => '2026-10-02 10:00:00', 'number' => '000003')),
);
$order = fn($sort) => array_column(BoardModel::build($statuses, $rich, $sort)['columns'][0]['cards'], 'id');
check('sort: last updated (default)', $order('updated') === array(3, 2, 1) && $order('bogus') === array(3, 2, 1));
check('sort: due date, no due date last', $order('due') === array(3, 2, 1));
check('sort: priority, unknown urgency last', $order('priority') === array(2, 1, 3));
check('sort: newest first', $order('created') === array(2, 3, 1));
check('sort: number, natural order', $order('number') === array(2, 3, 1));

$now = '2026-10-10 12:00:00';
check('due: none', BoardModel::dueState('', $now, false) === null);
check('due: overdue flag wins', BoardModel::dueState('', $now, true)['kind'] === 'overdue'
    && BoardModel::dueState('2026-10-20 09:00:00', $now, true)['kind'] === 'overdue');
check('due: past is overdue, earlier today too', BoardModel::dueState('2026-10-09 23:00:00', $now, false)['kind'] === 'overdue'
    && BoardModel::dueState('2026-10-10 08:00:00', $now, false)['kind'] === 'overdue');
check('due: later today, tomorrow, in days, later', BoardModel::dueState('2026-10-10 18:00:00', $now, false)['kind'] === 'today'
    && BoardModel::dueState('2026-10-11 08:00:00', $now, false)['kind'] === 'tomorrow'
    && BoardModel::dueState('2026-10-15 08:00:00', $now, false) === array('kind' => 'soon', 'days' => 5)
    && BoardModel::dueState('2026-10-30 08:00:00', $now, false)['kind'] === 'later');

$tagged = BoardModel::build($statuses, array(
    row(1, 1, 0, array('urgency' => 1, 'priority' => 'Emergency', 'due_raw' => '2026-10-11 09:00:00', 'due' => '10/11/26 9:00 AM')),
    row(2, 3, 0, array('urgency' => 1, 'priority' => 'Emergency', 'due_raw' => '2026-10-01 09:00:00')),
    row(3, 1, 0, array('urgency' => 3, 'priority' => 'Normal')),
));
$staffTagged = BoardRenderer::render($tagged, array_merge($pickerCtx, array('now' => $now)));
check('tags: high/emergency priority and due on open agent cards', strpos($staffTagged, 'spb-urgency-1">Emergency') !== false
    && strpos($staffTagged, 'spb-due-tomorrow" title="10/11/26 9:00 AM">Due tomorrow') !== false);
$soonTagged = BoardRenderer::render(BoardModel::build($statuses, array(row(1, 1, 0, array('due_raw' => '2026-10-15 09:00:00')))), array_merge($pickerCtx, array('now' => $now)));
check('tags: due dates days away get no tag', strpos($soonTagged, 'spb-due') === false);
check('tags: none for normal priority or closed tickets', substr_count($staffTagged, 'spb-prio') === 1
    && strpos($staffTagged, 'spb-due-overdue') === false);
$clientTagged = BoardRenderer::render($tagged, array('mode' => 'client', 'ticket_url' => '/t?id=', 'strings' => $strings,
    'closed_days' => 14, 'max_cards' => 300, 'truncated' => false, 'now' => $now));
check('tags: never on the client board', strpos($clientTagged, 'spb-tag') === false);

// ---------------------------------------------------------------- lanes

$laneRows = array(
    row(1, 1, 0, array('org' => 'Globex', 'assignee' => '', 'team' => 'Level 2', 'dept' => 'Sales')),
    row(2, 2, 0, array('org' => 'Acme', 'assignee' => 'Ann', 'dept' => 'Support')),
    row(3, 1, 0, array('org' => '', 'assignee' => '', 'team' => '', 'dept' => 'Support')),
    row(4, 1, 2, array('org' => 'Globex')), // child of 2: lane follows the parent card
);
$lb = BoardModel::build($statuses, $laneRows);
$lanes = BoardModel::lanes($lb, 'org', 'No organisation');
check('lanes: by organisation, sorted, empty last', array_column($lanes, 'label') === array('Acme', 'Globex', 'No organisation'));
check('lanes: every lane has every column', count($lanes[0]['columns']) === 3 && $lanes[0]['columns'][1]['cards'][0]['id'] === 2
    && count($lanes[0]['columns'][1]['cards'][0]['children']) === 1);
check('lanes: agent falls back to team', array_column(BoardModel::lanes($lb, 'agent', 'Unassigned'), 'label') === array('Ann', 'Level 2', 'Unassigned'));
check('lanes: department', array_column(BoardModel::lanes($lb, 'dept', '-'), 'label') === array('Sales', 'Support'));

$grouped = BoardRenderer::render($lb, array_merge($pickerCtx, array('lanes' => $lanes, 'group' => 'org', 'limits' => array(1 => 1))));
check('lanes: one head row, one section per lane', substr_count($grouped, 'class="spb-head-row"') === 1
    && substr_count($grouped, '<section class="spb-lane"') === 3 && strpos($grouped, 'style="--spb-cols: 3"') !== false);
check('lanes: cells are columns with status data', substr_count($grouped, 'class="spb-col spb-cell') === 9);

// ------------------------------------------------------ limits, signature

$limits = BoardModel::parseLimits("In progress = 5; open:20,\nClosed=x; Unknown=3; Open=0", $statuses);
check('limits: by name, case-insensitive, junk skipped', $limits === array(2 => 5, 1 => 20));
$limited = BoardRenderer::render(BoardModel::build($statuses, array(row(1, 1), row(2, 1))), array_merge($pickerCtx, array('limits' => array(1 => 1))));
check('limits: column over its limit is marked', strpos($limited, 'class="spb-col-head spb-over"') !== false
    && strpos($limited, 'data-limit="1"') !== false && strpos($limited, '2 of 1') !== false);

$sigA = BoardModel::signature(array(row(1, 1), row(2, 2)));
check('signature: order of rows does not matter', $sigA === BoardModel::signature(array(row(2, 2), row(1, 1))));
check('signature: changes with status, assignee, due', $sigA !== BoardModel::signature(array(row(1, 2), row(2, 2)))
    && $sigA !== BoardModel::signature(array(row(1, 1, 0, array('assignee' => 'Ann')), row(2, 2)))
    && $sigA !== BoardModel::signature(array(row(1, 1, 0, array('due_raw' => '2026-10-11 09:00:00')), row(2, 2))));

$session = array();
check('option: default, select, remember, junk falls back', BoardController::selectedOption(array(), $session, 'sort', BoardModel::SORTS) === 'updated'
    && BoardController::selectedOption(array('sort' => 'due'), $session, 'sort', BoardModel::SORTS) === 'due'
    && BoardController::selectedOption(array(), $session, 'sort', BoardModel::SORTS) === 'due'
    && BoardController::selectedOption(array('sort' => '<x>'), $session, 'sort', BoardModel::SORTS) === 'updated'
    && BoardController::selectedOption(array('group' => array('org')), $session, 'group', BoardModel::GROUPS) === '');

$live = BoardRenderer::render(BoardModel::build($statuses, array()), array_merge($pickerCtx,
    array('refresh_url' => '/scp/ajax.php/board/state', 'refresh_seconds' => 60, 'signature' => 'abc')));
check('live: refresh data on the board, updates button present', strpos($live, 'data-refresh-url="/scp/ajax.php/board/state" data-refresh-seconds="60" data-signature="abc"') !== false
    && strpos($live, '<button type="button" class="spb-updates" hidden>') !== false
    && strpos(BoardRenderer::render(BoardModel::build($statuses, array()), $pickerCtx), 'spb-updates') === false);

// --------------------------------------------------------------- settings

$n = BoardController::normalise(array());
check('defaults: client board off, agent board on', $n['enable_client'] === false && $n['enable_staff'] === true);
$n = BoardController::normalise(array('closed_days' => '-5', 'max_cards' => '999999'));
check('clamp: closed_days >= 0, max_cards <= 2000', $n['closed_days'] === 0 && $n['max_cards'] === 2000);
$n = BoardController::normalise(array('closed_days' => '9999', 'max_cards' => '1'));
check('clamp: closed_days <= 365, max_cards >= 10', $n['closed_days'] === 365 && $n['max_cards'] === 10);
check('clamp: refresh off at 0, else 15 to 600, default 60', BoardController::normalise(array())['refresh_seconds'] === 60
    && BoardController::normalise(array('refresh_seconds' => '0'))['refresh_seconds'] === 0
    && BoardController::normalise(array('refresh_seconds' => '-1'))['refresh_seconds'] === 0
    && BoardController::normalise(array('refresh_seconds' => '5'))['refresh_seconds'] === 15
    && BoardController::normalise(array('refresh_seconds' => '9999'))['refresh_seconds'] === 600);
check('setting: next ticket after closing on by default, can be switched off', BoardController::normalise(array())['next_after_close'] === true
    && BoardController::normalise(array('next_after_close' => false))['next_after_close'] === false
    && BoardController::normalise(array('next_after_close' => 0))['next_after_close'] === false);
check('setting: board as start page on by default, can be switched off', BoardController::normalise(array())['staff_landing'] === true
    && BoardController::normalise(array('staff_landing' => false))['staff_landing'] === false);
check('landing: login without a target, or from the panel root, lands on the board', BoardLanding::isDefaultTarget(null, '/')
    && BoardLanding::isDefaultTarget('', '/') && BoardLanding::isDefaultTarget('index.php', '/')
    && BoardLanding::isDefaultTarget('/scp/', '/') && BoardLanding::isDefaultTarget('/scp/index.php', '/')
    && BoardLanding::isDefaultTarget('/helpdesk/scp/', '/helpdesk/')
    && BoardLanding::isDefaultTarget('/scp/login.php', '/') && BoardLanding::isDefaultTarget('/scp/ajax.php/tickets/5', '/'));
check('landing: a login from a link keeps its target', !BoardLanding::isDefaultTarget('/scp/tickets.php?id=5', '/')
    && !BoardLanding::isDefaultTarget('/scp/tickets.php', '/') && !BoardLanding::isDefaultTarget('/scp/index.php?a=1', '/')
    && !BoardLanding::isDefaultTarget('/scp/', '/helpdesk/'));
check('landing: only a GET of the panel start page redirects', BoardLanding::isStartPage('/scp/index.php', 'GET')
    && BoardLanding::isStartPage('/helpdesk/scp/index.php', 'GET') && !BoardLanding::isStartPage('/scp/index.php', 'POST')
    && !BoardLanding::isStartPage('/scp/tickets.php', 'GET') && !BoardLanding::isStartPage('/index.php', 'GET'));
check('landing: other panel pages use up the mark, login and AJAX wait', BoardLanding::isOtherPage('/scp/tickets.php')
    && BoardLanding::isOtherPage('/scp/users.php') && !BoardLanding::isOtherPage('/scp/login.php')
    && !BoardLanding::isOtherPage('/scp/ajax.php') && !BoardLanding::isOtherPage('/scp/index.php')
    && !BoardLanding::isOtherPage('/tickets.php'));
check('landing: signed in means an agent id and no pending 2FA', BoardLanding::signedIn(array('_auth' => array('staff' => array('id' => 3, '2fa' => null))))
    && !BoardLanding::signedIn(array('_auth' => array('staff' => array('id' => 3, '2fa' => 'email:abc:1'))))
    && !BoardLanding::signedIn(array('_auth' => array('staff' => array('id' => 0)))) && !BoardLanding::signedIn(array()));
check('clamp: column limits text capped', strlen(BoardController::normalise(array('wip_limits' => str_repeat('a', 900)))['wip_limits']) === 500);

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
