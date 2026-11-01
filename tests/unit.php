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
check('panel: thread gets a heading before the core thread block', BoardRenderer::threadHeading('<div id="preview">s</div><div class="hidden tab_content thread-preview" id="threadPreview">t</div>', 'Thread') === '<div id="preview">s</div><h3 class="spb-thread-heading">Thread</h3><div class="hidden tab_content thread-preview" id="threadPreview">t</div>');
check('panel: thread heading escaped, only once, nothing without the block', strpos(BoardRenderer::threadHeading('<div id="threadPreview"></div>', '<b>$0</b>'), '&lt;b&gt;$0&lt;/b&gt;') !== false
    && substr_count(BoardRenderer::threadHeading('<div id="threadPreview"></div><div id="threadPreview"></div>', 'T'), 'spb-thread-heading') === 1
    && BoardRenderer::threadHeading('<div id="core">x</div>', 'T') === '<div id="core">x</div>');
$kids = BoardRenderer::subtickets(array(
    array('id' => 9, 'number' => '0<9>', 'subject' => '<img src=x>', 'status_name' => 'Open', 'status_state' => 'open', 'assignee' => '<b>Ann</b>'),
    array('id' => 10, 'number' => '10', 'subject' => 's', 'status_name' => 'Closed', 'status_state' => 'closed', 'assignee' => '')), false, '/scp/tickets.php?id=', $strings);
check('subtickets: heading with count, one entry each, opens own quick view', strpos($kids, '<h3 class="spb-subtickets-heading">' . $strings['panel_subtickets'] . ' <span class="spb-count">2</span></h3>') !== false
    && substr_count($kids, 'class="spb-child"') === 2 && strpos($kids, 'data-spb-open="9"') !== false
    && strpos($kids, 'href="/scp/tickets.php?id=10"') !== false && strpos($kids, 'spb-badge spb-state-closed">Closed') !== false);
check('subtickets: data escaped, no assignee span when unassigned', strpos($kids, '<img') === false && strpos($kids, '<b>') === false
    && strpos($kids, '#0&lt;9&gt;') !== false && substr_count($kids, 'spb-child-who') === 1);
check('subtickets: nothing without any, hint when cut off', BoardRenderer::subtickets(array(), true, '/', $strings) === ''
    && strpos($kids, $strings['panel_subtickets_more']) === false
    && strpos(BoardRenderer::subtickets(array(array('id' => 1, 'number' => '1', 'subject' => 's', 'status_name' => 'Open', 'status_state' => 'open', 'assignee' => '')), true, '/', $strings), $strings['panel_subtickets_more']) !== false);
check('panel: sub-tickets come after the preview', strpos(BoardRenderer::panel(array('id' => 7, 'number' => '1', 'subject' => 's', 'ticket_url' => '/', 'note_url' => '/',
    'actions' => array(), 'preview_html' => '<div id="core"></div>', 'strings' => $strings, 'ticket_base' => '/t?id=',
    'children' => array(array('id' => 9, 'number' => '9', 'subject' => 's', 'status_name' => 'Open', 'status_state' => 'open', 'assignee' => '')))),
    '<div id="core"></div></div><section class="spb-subtickets">') !== false
    && strpos($panel, 'spb-subtickets') === false);
check('panel: tasks between the actions and the preview, empty state without tasks',
    strpos($panel, '</div><section class="spb-tasks">') !== false
    && strpos($panel, '</section><div class="spb-preview">') !== false && strpos($panel, 'spb-tasks-none') !== false);
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
$taskCtx = array_merge($pickerCtx, array('now' => $now, 'open_tasks' => array(1 => 1, 2 => 3)));
$staffTasks = BoardRenderer::render($tagged, $taskCtx);
check('tags: open-task badge on agent cards, singular and plural, closed tickets too',
    strpos($staffTasks, 'spb-tasks-badge">1 open task<') !== false && strpos($staffTasks, 'spb-tasks-badge">3 open tasks<') !== false
    && substr_count($staffTasks, 'spb-tasks-badge') === 2 && strpos($staffTagged, 'spb-tasks-badge') === false);
check('tags: open-task badge never on the client board', strpos(BoardRenderer::render($tagged, array('mode' => 'client', 'ticket_url' => '/t?id=',
    'strings' => $strings, 'closed_days' => 14, 'max_cards' => 300, 'truncated' => false, 'now' => $now,
    'open_tasks' => array(1 => 1, 2 => 3))), 'spb-tasks-badge') === false);

// ---------------------------------------------------------------- tasks

$task = fn(int $id, bool $open, array $extra = array()) => array_merge(array('id' => $id, 'number' => (string) $id,
    'title' => "Task $id", 'open' => $open, 'assignee' => '', 'due_raw' => '', 'due' => '', 'overdue' => false, 'closed_raw' => ''), $extra);
check('tasks: open first by due date, undated after by number, closed newest first', array_column(BoardModel::sortTasks(array(
    $task(5, false, array('closed_raw' => '2026-10-01 10:00:00')),
    $task(10, true),
    $task(2, true),
    $task(6, false, array('closed_raw' => '2026-10-05 10:00:00')),
    $task(3, true, array('due_raw' => '2026-10-20 10:00:00')),
    $task(4, true, array('due_raw' => '2026-10-12 10:00:00')),
)), 'id') === array(4, 3, 2, 10, 6, 5));
$taskList = BoardRenderer::tasks(array(
    $task(1, true, array('title' => '<img src=x>', 'assignee' => '<b>Ann</b>', 'due_raw' => '2026-10-09 09:00:00', 'due' => '10/9/26')),
    $task(2, true, array('due_raw' => '2026-10-30 09:00:00', 'due' => '10/30/26')),
    $task(3, false),
), false, '/scp/tasks.php?id=', $now, $strings);
check('tasks: heading counts open ones, open list, closed folded', strpos($taskList, '<h3 class="spb-tasks-heading">Tasks <span class="spb-count">2</span></h3>') !== false
    && substr_count($taskList, 'class="spb-task"') === 3
    && strpos($taskList, '<details class="spb-tasks-closed"><summary>Completed <span class="spb-count">1</span></summary>') !== false
    && strpos($taskList, '</ul><details') !== false);
check('tasks: link into the agent task view, status badge', strpos($taskList, 'href="/scp/tasks.php?id=2">#2</a>') !== false
    && substr_count($taskList, 'spb-state-open">Open<') === 2 && strpos($taskList, 'spb-state-closed">Completed<') !== false);
check('tasks: overdue highlighted, later due date neutral, closed without due', strpos($taskList, 'spb-due-overdue">Overdue since 10/9/26<') !== false
    && strpos($taskList, 'spb-due-later">Due 10/30/26<') !== false && substr_count($taskList, 'spb-due ') === 2);
check('tasks: data escaped, unassigned open task says so, closed one shows no assignee', strpos($taskList, '<img') === false && strpos($taskList, '<b>') === false
    && strpos($taskList, '&lt;b&gt;Ann&lt;/b&gt;') !== false && substr_count($taskList, 'spb-child-who') === 2
    && strpos($taskList, 'spb-child-who">' . $strings['unassigned'] . '<') !== false);
$onlyClosed = BoardRenderer::tasks(array($task(3, false)), true, '/', $now, $strings);
check('tasks: only closed ones are unfolded, no count, cut-off hint', strpos($onlyClosed, '<details class="spb-tasks-closed" open>') !== false
    && strpos($onlyClosed, 'spb-tasks-heading">Tasks</h3>') !== false && strpos($onlyClosed, $strings['panel_tasks_more']) !== false
    && strpos($taskList, $strings['panel_tasks_more']) === false);
check('tasks: calm empty state', BoardRenderer::tasks(array(), false, '/', $now, $strings)
    === '<section class="spb-tasks"><h3 class="spb-tasks-heading">Tasks</h3><p class="spb-hint spb-tasks-none">No tasks.</p></section>');
check('i18n: task strings in both languages', count(array_filter(array('panel_tasks', 'panel_tasks_none', 'task_due', 'tasks_badge'),
    fn($k) => isset(BoardI18n::strings('de_DE')[$k], $strings[$k]))) === 4
    && BoardI18n::strings('de_DE')['panel_tasks'] === 'Aufgaben');

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
check('landing: only a plain GET of the panel start page is taken over', BoardLanding::isStartPage('/scp/index.php', 'GET', '')
    && BoardLanding::isStartPage('/helpdesk/scp/index.php', 'GET', '') && !BoardLanding::isStartPage('/scp/index.php', 'POST', '')
    && !BoardLanding::isStartPage('/scp/index.php', 'GET', 'queue=5') && !BoardLanding::isStartPage('/scp/tickets.php', 'GET', '')
    && !BoardLanding::isStartPage('/index.php', 'GET', '') && !BoardLanding::isStartPage('/scp/ajax.php', 'GET', ''));
check('landing: osTicket\'s own redirects and sent headers win', BoardLanding::mayRedirect(array('Content-Type: text/html'), false, 200)
    && !BoardLanding::mayRedirect(array('Location: login.php'), false, 302) && !BoardLanding::mayRedirect(array('location: x'), false, 200)
    && !BoardLanding::mayRedirect(array(), true, 200) && !BoardLanding::mayRedirect(array(), false, 403));
$ids = '/^[1-9]\d{0,9}$/';
check('next rules: status pickers read as stored by osTicket', BoardController::keyList(array('5' => 'Triage', '7' => 'Open'), $ids) === array('5', '7')
    && BoardController::keyList('{"5":"Triage"}', $ids) === array('5')
    && BoardController::keyList('5, 7', $ids) === array('5', '7')
    && BoardController::keyList(null, $ids) === array() && BoardController::keyList('', $ids) === array());
check('next rules: junk keys dropped', BoardController::keyList(array('0' => 'x', '-1' => 'x', '5 OR 1' => 'x', 'abc' => 'x', '9' => 'ok'), $ids) === array('9'));
$n = BoardController::normalise(array('next_from_statuses' => array('5' => 'Triage'), 'next_to_statuses' => '{"6":"Waiting"}',
    'next_from_actions' => array('assign' => 'x', 'delete' => 'x')));
check('next rules: normalised to ids and known action groups', $n['next_from'] === array(5) && $n['next_to'] === array(6)
    && $n['next_from_acts'] === array('assign'));
$n = BoardController::normalise(array());
check('next rules: default none, status change only', $n['next_from'] === array() && $n['next_to'] === array()
    && $n['next_from_acts'] === array('status'));
check('next rules: board carries them for the quick view', strpos(BoardRenderer::render(BoardModel::build($statuses, array()), array_merge($pickerCtx, array(
    'panel_url' => '/p/', 'next_from' => array(5), 'next_to' => array(6), 'next_from_acts' => array('status', 'assign')))),
    'data-next="{&quot;to&quot;:[6],&quot;from&quot;:[5],&quot;acts&quot;:[&quot;status&quot;,&quot;assign&quot;]}"') !== false);
check('clamp: column limits text capped', strlen(BoardController::normalise(array('wip_limits' => str_repeat('a', 900)))['wip_limits']) === 500);

// ------------------------------------- status list: disabled, abbreviations

$withDisabled = array_merge($statuses, array(
    array('id' => 8, 'name' => 'Parked', 'state' => 'open', 'sort' => 3, 'enabled' => false),
    array('id' => 9, 'name' => 'Retired', 'state' => 'closed', 'sort' => 2, 'enabled' => false),
));
$colIds = fn($board) => array_column(array_column($board['columns'], 'status'), 'id');
check('disabled: no column without tickets', $colIds(BoardModel::build($withDisabled, array(row(1, 1)))) === array(1, 2, 3));
$b = BoardModel::build($withDisabled, array(row(1, 1), row(2, 8)));
check('disabled: column kept while a ticket sits in it, marked', $colIds($b) === array(1, 2, 8, 3)
    && $b['columns'][2]['status']['enabled'] === false && $b['columns'][2]['cards'][0]['id'] === 2 && $b['count'] === 2);
$b = BoardModel::build($withDisabled, array(row(1, 1), row(2, 9, 1)));
check('disabled: a sub-ticket in it keeps its badge, adds no column', $colIds($b) === array(1, 2, 3)
    && $b['columns'][0]['cards'][0]['children'][0]['status_name'] === 'Retired');
check('disabled: statuses without the key count as enabled', BoardModel::build($statuses, array())['columns'][0]['status']['enabled'] === true);

$pb = BoardModel::build($withDisabled, array(row(1, 1), row(2, 8)));
$disabledStaff = BoardRenderer::render($pb, $pickerCtx);
check('disabled: agent column marked, not a drop target, with a note',
    preg_match('#<section class="spb-col spb-state-open" data-status-id="8" data-status-name="Parked" data-state="open" data-disabled="1">#', $disabledStaff) === 1
    && substr_count($disabledStaff, 'data-disabled="1"') === 1
    && strpos($disabledStaff, '<p class="spb-col-note spb-disabled-note">' . $strings['status_disabled'] . '</p>') !== false);
$disabledClient = BoardRenderer::render($pb, array('mode' => 'client', 'ticket_url' => '/t?id=', 'strings' => $strings,
    'closed_days' => 14, 'max_cards' => 300, 'truncated' => false));
check('disabled: client sees the column and card, without the admin note', strpos($disabledClient, 'data-status-id="8"') !== false
    && strpos($disabledClient, 'data-disabled') === false && strpos($disabledClient, $strings['status_disabled']) === false);
$pl = BoardModel::lanes($pb, 'dept', '-');
$disabledLanes = BoardRenderer::render($pb, array_merge($pickerCtx, array('lanes' => $pl, 'group' => 'dept')));
check('disabled: lane cells marked too', substr_count($disabledLanes, 'data-disabled="1"') === count($pl)
    && substr_count($disabledLanes, 'spb-disabled-note') === 1);

$statusListDisabled = array_merge($statusList, array(array('id' => 8, 'name' => 'Parked', 'state' => 'open', 'enabled' => false),
    array('id' => 9, 'name' => 'Retired', 'state' => 'closed', 'enabled' => false),
    array('id' => 10, 'name' => 'Waiting', 'state' => 'open', 'enabled' => true)));
$offered = array_column(BoardActions::build($all, $statusListDisabled, $strings), 'value', 'label');
check('disabled: never offered as a quick-view status', !isset($offered['Parked']) && !isset($offered['Retired'])
    && isset($offered['Waiting']) && isset($offered['In Progress']));
check('disabled: a ticket in a disabled status can move out', ($offered = array_column(BoardActions::build(
    array_merge($all, array('status_id' => 8)), $statusListDisabled, $strings), 'label')) && in_array('Open', $offered, true)
    && !in_array('Parked', $offered, true));

// Properties as TicketStatus::setConfiguration() stores them: by field ID;
// the installer's own data is keyed by variable name.
check('abbrev: by field id, JSON or array', BoardModel::abbrevFrom('{"35":"Open tickets.","41":"TR"}', 41, 'kuerzel') === 'TR'
    && BoardModel::abbrevFrom(array('41' => ' WV '), 41, 'kuerzel') === 'WV');
check('abbrev: by variable name as fallback', BoardModel::abbrevFrom('{"kuerzel":"OF"}', 41, 'kuerzel') === 'OF'
    && BoardModel::abbrevFrom('{"41":"","kuerzel":"OF"}', 41, 'kuerzel') === 'OF');
check('abbrev: none, empty, junk give nothing', BoardModel::abbrevFrom(null, 41, 'kuerzel') === ''
    && BoardModel::abbrevFrom('', 41, 'kuerzel') === '' && BoardModel::abbrevFrom('not json', 41, 'kuerzel') === ''
    && BoardModel::abbrevFrom('{"41":"   "}', 41, 'kuerzel') === '' && BoardModel::abbrevFrom('{"42":"TR"}', 41, 'kuerzel') === ''
    && BoardModel::abbrevFrom('{"41":{"1":"Choice"}}', 41, 'kuerzel') === ''
    && BoardModel::abbrevFrom('{"41":true}', 41, 'kuerzel') === '');
check('abbrev: multi-line or too long gives nothing, umlauts count as one', BoardModel::abbrevFrom('{"41":"A\nB"}', 41, 'k') === ''
    && BoardModel::abbrevFrom(array('41' => str_repeat('x', BoardModel::ABBREV_MAX + 1)), 41, 'k') === ''
    && BoardModel::abbrevFrom(array('41' => str_repeat('ü', BoardModel::ABBREV_MAX)), 41, 'k') === str_repeat('ü', BoardModel::ABBREV_MAX)
    && BoardModel::abbrevFrom(array('41' => "ok \xC3\x28"), 41, 'k') === '');

$abbrevStatuses = array(
    array('id' => 1, 'name' => 'Open', 'state' => 'open', 'sort' => 1, 'abbrev' => 'OF'),
    array('id' => 2, 'name' => 'In <progress> "x"', 'state' => 'open', 'sort' => 2, 'abbrev' => '<b>'),
    array('id' => 3, 'name' => 'Closed', 'state' => 'closed', 'sort' => 1, 'abbrev' => ''),
);
$ab = BoardModel::build($abbrevStatuses, array(row(1, 1)));
$abStaff = BoardRenderer::render($ab, $pickerCtx);
check('abbrev: agent head shows it, full name as tooltip and for screen readers', strpos($abStaff,
    '<h3 class="spb-abbreviated" title="Open"><span class="spb-abbrev" aria-hidden="true">OF</span><span class="spb-sr">Open</span></h3>') !== false);
check('abbrev: escaped in head, tooltip and screen-reader text', strpos($abStaff, '<b>') === false
    && strpos($abStaff, 'title="In &lt;progress&gt; &quot;x&quot;"><span class="spb-abbrev" aria-hidden="true">&lt;b&gt;</span><span class="spb-sr">In &lt;progress&gt; &quot;x&quot;</span>') !== false);
check('abbrev: a status without a value keeps its name', strpos($abStaff, '<h3>Closed</h3>') !== false);
check('abbrev: column keeps the full name for moves and toasts', strpos($abStaff, 'data-status-id="1" data-status-name="Open"') !== false);
$abClient = BoardRenderer::render($ab, array('mode' => 'client', 'ticket_url' => '/t?id=', 'strings' => $strings,
    'closed_days' => 14, 'max_cards' => 300, 'truncated' => false));
check('abbrev: never on the client board', strpos($abClient, 'spb-abbrev') === false && strpos($abClient, '<h3>Open</h3>') !== false);
$abLanes = BoardRenderer::render($ab, array_merge($pickerCtx, array('lanes' => BoardModel::lanes($ab, 'dept', '-'), 'group' => 'dept')));
check('abbrev: shared head row of a grouped board too', substr_count($abLanes, 'class="spb-abbreviated"') === 2);
check('abbrev: without the setting the head is unchanged', strpos(BoardRenderer::render(BoardModel::build($statuses, array()), $pickerCtx),
    '<header class="spb-col-head"><h3>Open</h3><span class="spb-count">0</span></header>') !== false);

check('setting: status abbreviations off by default', BoardController::normalise(array())['status_abbrev'] === '');
check('setting: variable name lower-cased and trimmed', BoardController::normalise(array('status_abbrev_var' => ' Kuerzel '))['status_abbrev'] === 'kuerzel');
check('setting: anything but a variable name switches it off', BoardController::variableName('kü') === ''
    && BoardController::variableName('a b') === '' && BoardController::variableName("x'--") === ''
    && BoardController::variableName(array('x')) === '' && BoardController::variableName(null) === ''
    && BoardController::variableName(str_repeat('a', 65)) === '' && BoardController::variableName('abbrev_2') === 'abbrev_2');

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
