<?php

declare(strict_types=1);

/**
 * Renders a board built by BoardModel as HTML.
 *
 * Pure and server-side: every value that comes from a ticket passes through
 * e() exactly once, on its way into the markup. The JavaScript never builds
 * markup from ticket data, so there is a single escaping point to review.
 */
final class BoardRenderer
{
    /**
     * @param array $board  result of BoardModel::build()
     * @param array $ctx    mode ('staff'|'client'), ticket_url (prefix, id is
     *                      appended), move_url (staff only), strings (BoardI18n),
     *                      closed_days, max_cards, truncated; staff only:
     *                      queues (BoardQuery::queueOptions), queue_id,
     *                      queue_missing, queue_action, search_url, panel_url,
     *                      sort, group, lanes (BoardModel::lanes or null),
     *                      limits (status id => limit), now (DB time),
     *                      refresh_url, refresh_seconds, signature
     */
    public static function render(array $board, array $ctx): string
    {
        $staff = ($ctx['mode'] ?? 'client') === 'staff';
        $t = $ctx['strings'];

        $attrs = sprintf(' data-mode="%s"', $staff ? 'staff' : 'client');
        if ($staff && !empty($ctx['move_url'])) {
            $attrs .= sprintf(
                ' data-move-url="%s" data-i18n="%s"',
                self::e($ctx['move_url']),
                self::e((string) json_encode(array(
                    'confirm_close' => $t['confirm_close'],
                    'move_failed'   => $t['move_failed'],
                    'moved'         => $t['moved'],
                    'panel_failed'  => $t['panel_failed'],
                    'note_saved'    => $t['note_saved'],
                    'note_failed'   => $t['note_failed'],
                    'refreshed'     => $t['refreshed'],
                    'act_done'      => $t['act_done'],
                    'act_failed'    => $t['act_failed'],
                    'act_note'      => $t['act_note'],
                    'undo'          => $t['undo'],
                    'undone'        => $t['undone'],
                    'column_done'   => $t['column_done'],
                    'limit'         => $t['limit'],
                    'limit_over'    => $t['limit_over'],
                ), JSON_UNESCAPED_UNICODE))
            );
            if (!empty($ctx['panel_url'])) {
                $attrs .= sprintf(' data-panel-url="%s" data-next-after-close="%d" data-next="%s"',
                    self::e($ctx['panel_url']), !empty($ctx['next_after_close']) ? 1 : 0,
                    self::e((string) json_encode(array(
                        'to'   => array_values($ctx['next_to'] ?? array()),
                        'from' => array_values($ctx['next_from'] ?? array()),
                        'acts' => array_values($ctx['next_from_acts'] ?? array('status')),
                    ))));
            }
            if (!empty($ctx['refresh_url'])) {
                $attrs .= sprintf(
                    ' data-refresh-url="%s" data-refresh-seconds="%d" data-signature="%s"',
                    self::e($ctx['refresh_url']),
                    (int) $ctx['refresh_seconds'],
                    self::e((string) ($ctx['signature'] ?? ''))
                );
            }
        }
        $lanes = $staff && isset($ctx['lanes']) && is_array($ctx['lanes']) ? $ctx['lanes'] : null;

        $out = array();
        $out[] = '<div class="spb-wrap">';
        if ($staff && isset($ctx['queues'], $ctx['queue_action'])) {
            $out[] = self::queuePicker($ctx);
        }
        $hint = $staff ? $t['drag_hint'] : $t['readonly'];
        if ($staff && !empty($ctx['panel_url'])) {
            $hint .= ' ' . $t['drag_hint_panel'];
        }
        $out[] = sprintf('<p class="spb-hint">%s</p>', self::e($hint));
        if (!empty($ctx['truncated'])) {
            $out[] = sprintf('<p class="spb-hint spb-warn">%s</p>', self::e(sprintf($t['truncated'], (int) $ctx['max_cards'])));
        }
        $out[] = '<p class="spb-status" role="status" aria-live="polite"></p>';
        if ($staff && !empty($ctx['refresh_url'])) {
            $out[] = sprintf('<button type="button" class="spb-updates" hidden>%s</button>', self::e($t['updates']));
        }

        if ($lanes === null) {
            $out[] = sprintf('<div class="spb-board"%s>', $attrs);
            foreach ($board['columns'] as $col) {
                $out[] = self::column($col, $ctx, $staff);
            }
            $out[] = '</div>';
        } else {
            $out[] = self::laneBoard($board, $lanes, $ctx, $attrs);
        }
        if ($staff && !empty($ctx['panel_url'])) {
            // Empty shell; board.js fills it with BoardController::staffPanel().
            $out[] = sprintf(
                '<aside class="spb-drawer" hidden aria-label="%s" tabindex="-1">'
                . '<button type="button" class="spb-drawer-close" aria-label="%s">&times;</button>'
                . '<div class="spb-drawer-content"></div></aside>',
                self::e($t['panel_title']),
                self::e($t['panel_close'])
            );
        }
        $out[] = '</div>';
        return implode("\n", $out);
    }

    /**
     * GET form with one select: the default view, then queues, then saved
     * searches, nested like the agent panel's navigation. Works without
     * JavaScript (button), board.js submits on change.
     */
    private static function queuePicker(array $ctx): string
    {
        $t = $ctx['strings'];
        $current = (int) ($ctx['queue_id'] ?? 0);

        $groups = array('queue' => array(), 'search' => array());
        foreach ($ctx['queues'] as $q) {
            $group = $q['group'] === 'search' ? 'search' : 'queue';
            $groups[$group][] = sprintf(
                '<option value="%d"%s>%s%s</option>',
                (int) $q['id'],
                (int) $q['id'] === $current ? ' selected' : '',
                str_repeat("\u{00A0}\u{00A0}\u{00A0}", max(0, (int) $q['depth'])),
                self::e($q['label'])
            );
        }

        $html = sprintf('<form class="spb-toolbar" method="get" action="%s">', self::e($ctx['queue_action']));
        $html .= sprintf('<label for="spb-queue">%s</label> ', self::e($t['queue_label']));
        $html .= '<select id="spb-queue" name="queue" data-spb-autosubmit>';
        $html .= sprintf('<option value="all"%s>%s</option>', $current === 0 ? ' selected' : '', self::e($t['queue_all']));
        if ($groups['queue']) {
            $html .= sprintf('<optgroup label="%s">%s</optgroup>', self::e($t['queue_group']), implode('', $groups['queue']));
        }
        if ($groups['search']) {
            $html .= sprintf('<optgroup label="%s">%s</optgroup>', self::e($t['search_group']), implode('', $groups['search']));
        }
        $html .= '</select> ';

        if (isset($ctx['sort'])) {
            $html .= sprintf('<label for="spb-sort">%s</label> ', self::e($t['sort_label']));
            $html .= '<select id="spb-sort" name="sort" data-spb-autosubmit>';
            foreach (BoardModel::SORTS as $sort) {
                $html .= sprintf('<option value="%s"%s>%s</option>', self::e($sort),
                    $sort === $ctx['sort'] ? ' selected' : '', self::e($t['sort_' . $sort]));
            }
            $html .= '</select> ';
        }
        if (isset($ctx['group'])) {
            $html .= sprintf('<label for="spb-group">%s</label> ', self::e($t['group_label']));
            $html .= '<select id="spb-group" name="group" data-spb-autosubmit>';
            foreach (BoardModel::GROUPS as $group) {
                $html .= sprintf('<option value="%s"%s>%s</option>', self::e($group),
                    $group === $ctx['group'] ? ' selected' : '', self::e($t['group_' . $group]));
            }
            $html .= '</select> ';
        }

        $html .= sprintf('<button type="submit" class="spb-show">%s</button>', self::e($t['queue_show']));
        if (!empty($ctx['search_url'])) {
            $html .= sprintf(' <a class="spb-manage" href="%s">%s</a>', self::e($ctx['search_url']), self::e($t['queue_manage']));
        }
        $html .= '</form>';
        if (!empty($ctx['queue_missing'])) {
            $html .= sprintf('<p class="spb-hint spb-warn">%s</p>', self::e($t['queue_missing']));
        }
        return $html;
    }

    /** Column head: status dot, name, count, and "n of limit" when limited. */
    private static function columnHead(array $status, int $count, array $ctx, string $tag): string
    {
        $t = $ctx['strings'];
        $limit = (int) ($ctx['limits'][(int) $status['id']] ?? 0);
        $over = $limit > 0 && $count > $limit;
        // Agent board only: customers don't know the abbreviations.
        $abbrev = ($ctx['mode'] ?? 'client') === 'staff' ? (string) ($status['abbrev'] ?? '') : '';
        $heading = $abbrev !== ''
            ? sprintf('<h3 class="spb-abbreviated" title="%1$s"><span class="spb-abbrev" aria-hidden="true">%2$s</span>'
                . '<span class="spb-sr">%1$s</span></h3>', self::e($status['name']), self::e($abbrev))
            : sprintf('<h3>%s</h3>', self::e($status['name']));
        return sprintf(
            '<%1$s class="spb-col-head%2$s">%3$s<span class="spb-count"%4$s%5$s>%6$s</span></%1$s>',
            $tag,
            $over ? ' spb-over' : '',
            $heading,
            $limit > 0 ? sprintf(' data-limit="%d"', $limit) : '',
            $over ? sprintf(' title="%s"', self::e(sprintf($t['limit_over'], $limit))) : '',
            self::e($limit > 0 ? sprintf($t['limit'], $count, $limit) : (string) $count)
        );
    }

    private static function column(array $col, array $ctx, bool $staff): string
    {
        $t = $ctx['strings'];
        $s = $col['status'];
        $html = sprintf(
            '<section class="spb-col spb-state-%s" data-status-id="%d" data-status-name="%s" data-state="%s"%s>',
            self::e($s['state']),
            (int) $s['id'],
            self::e($s['name']),
            self::e($s['state']),
            self::disabledAttr($s, $staff)
        );
        $html .= self::columnHead($s, count($col['cards']), $staff ? $ctx : array_merge($ctx, array('limits' => array())), 'header');
        if ($staff && self::isDisabled($s)) {
            $html .= sprintf('<p class="spb-col-note spb-disabled-note">%s</p>', self::e($t['status_disabled']));
        }
        if ($s['state'] === 'closed' && !empty($ctx['closed_days'])) {
            $html .= sprintf('<p class="spb-col-note">%s</p>', self::e(sprintf($t['closed_hint'], (int) $ctx['closed_days'])));
        }
        $html .= self::cards($col, $ctx, $staff);
        $html .= '</section>';
        return $html;
    }

    /** Disabled in the status list: shown while it holds cards, never a drop target. */
    private static function isDisabled(array $status): bool
    {
        return array_key_exists('enabled', $status) && !$status['enabled'];
    }

    /** board.js skips columns with data-disabled when dragging or moving by keyboard. */
    private static function disabledAttr(array $status, bool $staff): string
    {
        return $staff && self::isDisabled($status) ? ' data-disabled="1"' : '';
    }

    private static function cards(array $col, array $ctx, bool $staff): string
    {
        $html = '<ol class="spb-cards">';
        if (!$col['cards']) {
            $html .= sprintf('<li class="spb-empty">%s</li>', self::e($ctx['strings']['empty']));
        }
        foreach ($col['cards'] as $card) {
            $html .= self::card($card, $ctx, $staff, $col['status']['state']);
        }
        return $html . '</ol>';
    }

    /**
     * Swimlanes: one shared row of column heads (with totals and limits),
     * then one row per lane. Each lane cell is a .spb-col of its own, so
     * drag and drop and the keyboard move work per lane.
     */
    private static function laneBoard(array $board, array $lanes, array $ctx, string $attrs): string
    {
        $t = $ctx['strings'];
        $html = sprintf(
            '<div class="spb-board spb-grouped" style="--spb-cols: %d"%s>',
            count($board['columns']),
            $attrs
        );

        $html .= '<div class="spb-head-row">';
        foreach ($board['columns'] as $col) {
            $html .= sprintf('<div class="spb-col-total spb-state-%s" data-status-id="%d">',
                self::e($col['status']['state']), (int) $col['status']['id']);
            $html .= self::columnHead($col['status'], count($col['cards']), $ctx, 'div');
            if (self::isDisabled($col['status'])) {
                $html .= sprintf('<p class="spb-col-note spb-disabled-note">%s</p>', self::e($t['status_disabled']));
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        if (!$lanes) {
            $html .= sprintf('<p class="spb-empty spb-lane-empty">%s</p>', self::e($t['empty']));
        }
        foreach ($lanes as $lane) {
            $html .= sprintf(
                '<section class="spb-lane" data-lane="%s"><header class="spb-lane-head"><h3>%s</h3><span class="spb-count">%d</span></header><div class="spb-lane-cells">',
                self::e($lane['key']),
                self::e($lane['label']),
                (int) $lane['count']
            );
            foreach ($lane['columns'] as $col) {
                $s = $col['status'];
                $html .= sprintf(
                    '<div class="spb-col spb-cell spb-state-%s" data-status-id="%d" data-status-name="%s" data-state="%s"%s>',
                    self::e($s['state']),
                    (int) $s['id'],
                    self::e($s['name']),
                    self::e($s['state']),
                    self::disabledAttr($s, true)
                );
                $html .= self::cards($col, $ctx, true) . '</div>';
            }
            $html .= '</div></section>';
        }

        return $html . '</div>';
    }

    private static function card(array $c, array $ctx, bool $staff, string $state = 'open'): string
    {
        $t = $ctx['strings'];
        $url = $ctx['ticket_url'] . rawurlencode((string) $c['id']);

        $html = sprintf(
            '<li class="spb-card" data-ticket-id="%d" data-number="%s"%s>',
            (int) $c['id'],
            self::e($c['number']),
            $staff ? ' draggable="true" tabindex="0"' : ''
        );
        $html .= sprintf(
            '<a class="spb-num" href="%s">#%s</a>',
            self::e($url),
            self::e($c['number'])
        );
        $html .= sprintf('<div class="spb-subject">%s</div>', self::e($c['subject']));

        // Due and priority: agent board only, open tickets only. Open tasks:
        // agent board only, on any ticket, since a closed ticket with open
        // tasks is worth seeing. The portal shows none of these to customers.
        $tags = '';
        if ($staff && $state === 'open') {
            if ($c['urgency'] > 0 && $c['urgency'] <= 2 && $c['priority'] !== '') {
                $tags .= sprintf('<span class="spb-tag spb-prio spb-urgency-%d">%s</span>',
                    (int) $c['urgency'], self::e($c['priority']));
            }
            // Only what needs attention gets a tag. A due date days away is
            // on nearly every ticket (the default SLA) and would be noise;
            // "Sort: Due date" still orders by it.
            $due = BoardModel::dueState($c['due_raw'], (string) ($ctx['now'] ?? ''), $c['overdue']);
            if ($due !== null && in_array($due['kind'], array('overdue', 'today', 'tomorrow'), true)) {
                $tags .= sprintf('<span class="spb-tag spb-due spb-due-%s"%s>%s</span>',
                    self::e($due['kind']),
                    $c['due'] !== '' ? sprintf(' title="%s"', self::e($c['due'])) : '',
                    self::e($t['due_' . $due['kind']]));
            }
        }
        $tasks = $staff ? (int) ($ctx['open_tasks'][(int) $c['id']] ?? 0) : 0;
        if ($tasks > 0) {
            $tags .= sprintf('<span class="spb-tag spb-tasks-badge">%s</span>',
                self::e($tasks === 1 ? $t['tasks_badge_one'] : sprintf($t['tasks_badge'], $tasks)));
        }
        if ($tags !== '') {
            $html .= '<div class="spb-tags">' . $tags . '</div>';
        }

        $meta = array();
        if ($staff && $c['user'] !== '') {
            $meta[] = array($t['requester'], $c['user']);
        }
        if ($c['dept'] !== '') {
            $meta[] = array($t['department'], $c['dept']);
        }
        if ($staff) {
            $meta[] = array($t['assignee'], $c['assignee'] !== '' ? $c['assignee'] : $t['unassigned']);
        }
        if ($c['updated'] !== '') {
            $meta[] = array($t['updated'], $c['updated']);
        }
        if ($meta) {
            $html .= '<dl class="spb-meta">';
            foreach ($meta as $m) {
                $html .= sprintf('<dt>%s</dt><dd>%s</dd>', self::e($m[0]), self::e($m[1]));
            }
            $html .= '</dl>';
        }

        if (!empty($c['children'])) {
            $n = count($c['children']);
            $html .= sprintf(
                '<details class="spb-children"><summary>%s</summary><ul>',
                self::e($n === 1 ? $t['subticket'] : sprintf($t['subtickets'], $n))
            );
            foreach ($c['children'] as $k) {
                $html .= sprintf(
                    '<li class="spb-child" data-ticket-id="%d"><a href="%s">#%s</a> <span class="spb-child-subject">%s</span> <span class="spb-badge spb-state-%s">%s</span></li>',
                    (int) $k['id'],
                    self::e($ctx['ticket_url'] . rawurlencode((string) $k['id'])),
                    self::e($k['number']),
                    self::e($k['subject']),
                    self::e($k['status_state']),
                    self::e($k['status_name'])
                );
            }
            $html .= '</ul></details>';
        }

        $html .= '</li>';
        return $html;
    }

    /**
     * Quick-view panel: action buttons, osTicket's own ticket preview, note
     * form. $previewHtml is the output of the core template
     * include/staff/templates/ticket-preview.tmpl.php and is passed through
     * as-is; that template escapes its own data. Everything this method adds
     * goes through e().
     *
     * @param array $p  id, number, subject, ticket_url, note_url, actions
     *                  (BoardActions::build), preview_html, strings
     */
    public static function panel(array $p): string
    {
        $t = $p['strings'];
        $groups = array('status' => 'grp_status', 'assign' => 'grp_assign', 'route' => 'grp_route', 'mark' => 'grp_mark');

        $html = sprintf(
            '<div class="spb-panel-body" data-ticket-id="%d" data-number="%s" data-act-url="%s">',
            (int) $p['id'],
            self::e($p['number']),
            self::e((string) ($p['act_url'] ?? ''))
        );
        $html .= sprintf(
            '<header class="spb-panel-head"><h2>#%s %s</h2><a class="spb-panel-open" href="%s">%s</a></header>',
            self::e($p['number']),
            self::e($p['subject']),
            self::e($p['ticket_url']),
            self::e($t['panel_open'])
        );

        // Note first: write, then click an action, and the note goes with it.
        $html .= sprintf(
            '<form class="spb-note" method="post" action="%s">'
            . '<label for="spb-note-text">%s</label>'
            . '<textarea id="spb-note-text" name="note" rows="3" maxlength="%d" required></textarea>'
            . '<div class="spb-note-foot"><p class="spb-hint">%s</p>'
            . '<input type="hidden" name="ticket_id" value="%d">'
            . '<button type="submit">%s</button></div>'
            . '</form>',
            self::e($p['note_url']),
            self::e($t['note_label']),
            BoardController::NOTE_MAX,
            self::e($t['note_hint']),
            (int) $p['id'],
            self::e($t['note_submit'])
        );

        $menus = $p['menus'] ?? array();
        $html .= '<div class="spb-actions">';
        foreach ($groups as $group => $label) {
            $items = array_values(array_filter($p['actions'], static function ($a) use ($group) {
                return $a['group'] === $group;
            }));
            if (!$items) {
                continue;
            }
            $html .= sprintf('<div class="spb-action-group" data-group="%s"><h3>%s</h3>', self::e($group), self::e($t[$label]));
            $lists = '';
            foreach ($items as $a) {
                if (isset($a['act'])) {
                    $html .= sprintf(
                        '<button type="button" class="spb-action" data-spb-act="%s" data-value="%s"%s>%s</button>',
                        self::e($a['act']),
                        self::e((string) $a['value']),
                        isset($a['state']) ? sprintf(' data-state="%s"', self::e($a['state'])) : '',
                        self::e($a['label'])
                    );
                } elseif (isset($a['menu'])) {
                    $options = $menus[$a['menu']] ?? array();
                    $html .= sprintf(
                        '<button type="button" class="spb-action spb-has-menu" data-spb-menu="%s" aria-expanded="false">%s</button>',
                        self::e($a['menu']),
                        self::e($a['label'])
                    );
                    $lists .= self::menu($a['menu'], (string) $a['menu_act'], $options, $t);
                } else {
                    $html .= sprintf(
                        '<button type="button" class="spb-action spb-dialog" data-spb-dialog="%s">%s</button>',
                        self::e($a['path']),
                        self::e($a['label'])
                    );
                }
            }
            $html .= $lists . '</div>';
        }
        $html .= '</div>';

        // Tasks before the preview: they are the open work on the ticket, and
        // under a long thread nobody would scroll to them.
        $html .= self::tasks($p['tasks'] ?? array(), !empty($p['tasks_more']),
            (string) ($p['task_base'] ?? ''), (string) ($p['now'] ?? ''), $t);
        $html .= sprintf('<div class="spb-preview">%s</div>',
            self::threadHeading((string) $p['preview_html'], (string) ($t['panel_thread'] ?? '')));
        $html .= self::subtickets($p['children'] ?? array(), !empty($p['children_more']),
            (string) ($p['ticket_base'] ?? ''), $t);

        return $html . '</div>';
    }

    /**
     * The ticket's tasks between the actions and the preview: open ones as
     * a list, closed ones folded below. Agent quick view only; tasks are internal. Unlike
     * sub-tickets, an empty list says so, since tasks replace to-do
     * sub-tickets and "none" is worth knowing. Public for the unit tests.
     *
     * @param array $tasks  id, number, title, open, assignee, due_raw, due,
     *        overdue; in display order (BoardModel::sortTasks())
     */
    public static function tasks(array $tasks, bool $more, string $base, string $now, array $t): string
    {
        $open = array_values(array_filter($tasks, static function ($k) {
            return !empty($k['open']);
        }));
        $closed = array_values(array_filter($tasks, static function ($k) {
            return empty($k['open']);
        }));

        $html = sprintf(
            '<section class="spb-tasks"><h3 class="spb-tasks-heading">%s%s</h3>',
            self::e($t['panel_tasks']),
            $open ? sprintf(' <span class="spb-count">%d</span>', count($open)) : ''
        );
        if (!$tasks) {
            return $html . sprintf('<p class="spb-hint spb-tasks-none">%s</p></section>', self::e($t['panel_tasks_none']));
        }
        if ($open) {
            $html .= '<ul class="spb-tasks-open">';
            foreach ($open as $k) {
                $html .= self::taskRow($k, $base, $now, $t);
            }
            $html .= '</ul>';
        }
        if ($closed) {
            $html .= sprintf(
                '<details class="spb-tasks-closed"%s><summary>%s <span class="spb-count">%d</span></summary><ul>',
                $open ? '' : ' open',
                self::e($t['panel_tasks_closed']),
                count($closed)
            );
            foreach ($closed as $k) {
                $html .= self::taskRow($k, $base, $now, $t);
            }
            $html .= '</ul></details>';
        }
        if ($more) {
            $html .= sprintf('<p class="spb-hint">%s</p>', self::e($t['panel_tasks_more']));
        }
        return $html . '</section>';
    }

    private static function taskRow(array $k, string $base, string $now, array $t): string
    {
        $open = !empty($k['open']);
        $due = '';
        if ($open && (string) ($k['due_raw'] ?? '') !== '') {
            $state = BoardModel::dueState((string) $k['due_raw'], $now, !empty($k['overdue']));
            $kind = $state['kind'] ?? 'later';
            $due = sprintf(' <span class="spb-tag spb-due spb-due-%s">%s</span>',
                self::e($kind),
                self::e(sprintf($t[$kind === 'overdue' ? 'task_overdue' : 'task_due'], (string) $k['due'])));
        }
        return sprintf(
            '<li class="spb-task" data-task-id="%1$d"><a href="%2$s">#%3$s</a>'
            . ' <span class="spb-task-title">%4$s</span>%5$s%6$s'
            . ' <span class="spb-badge spb-state-%7$s">%8$s</span></li>',
            (int) $k['id'],
            self::e($base . rawurlencode((string) $k['id'])),
            self::e((string) $k['number']),
            self::e((string) $k['title']),
            $open ? sprintf(' <span class="spb-child-who">%s</span>',
                self::e((string) ($k['assignee'] ?? '') !== '' ? $k['assignee'] : $t['unassigned'])) : '',
            $due,
            $open ? 'open' : 'closed',
            self::e($t[$open ? 'task_open' : 'task_closed'])
        );
    }

    /**
     * Sub-tickets under the thread. The subject opens the sub-ticket's own
     * quick view, the number its ticket page. Nothing without sub-tickets.
     * Public for the unit tests.
     *
     * @param array $kids  id, number, subject, status_name, status_state, assignee
     */
    public static function subtickets(array $kids, bool $more, string $base, array $t): string
    {
        if (!$kids) {
            return '';
        }
        $html = sprintf(
            '<section class="spb-subtickets"><h3 class="spb-subtickets-heading">%s <span class="spb-count">%d</span></h3><ul>',
            self::e($t['panel_subtickets']),
            count($kids)
        );
        foreach ($kids as $k) {
            $html .= sprintf(
                '<li class="spb-child" data-ticket-id="%1$d"><a href="%2$s">#%3$s</a>'
                . ' <button type="button" class="spb-child-subject spb-child-open" data-spb-open="%1$d">%4$s</button>'
                . '%5$s <span class="spb-badge spb-state-%6$s">%7$s</span></li>',
                (int) $k['id'],
                self::e($base . rawurlencode((string) $k['id'])),
                self::e($k['number']),
                self::e($k['subject']),
                $k['assignee'] !== '' ? sprintf(' <span class="spb-child-who">%s</span>', self::e($k['assignee'])) : '',
                self::e($k['status_state']),
                self::e($k['status_name'])
            );
        }
        $html .= '</ul>';
        if ($more) {
            $html .= sprintf('<p class="spb-hint">%s</p>', self::e($t['panel_subtickets_more']));
        }
        return $html . '</section>';
    }

    /**
     * The core preview keeps the thread behind a tab; the quick view shows
     * it under the summary instead (CSS) and gives it a heading here. Only
     * the first element with id="threadPreview" gets one; without it, e.g.
     * on another osTicket version, the preview stays as it is. Public for
     * the unit tests.
     */
    public static function threadHeading(string $preview, string $label): string
    {
        if ($label === '') {
            return $preview;
        }
        $heading = sprintf('<h3 class="spb-thread-heading">%s</h3>', self::e($label));
        $out = preg_replace_callback('#<div\b[^>]*\bid="threadPreview"[^>]*>#', static function ($m) use ($heading) {
            return $heading . $m[0];
        }, $preview, 1);
        return is_string($out) ? $out : $preview;
    }

    /** A picker that opens under its button; each entry runs the action. */
    private static function menu(string $name, string $act, array $options, array $t): string
    {
        $html = sprintf('<div class="spb-menu" data-menu="%s" hidden>', self::e($name));
        if (!$options) {
            $html .= sprintf('<p class="spb-hint">%s</p>', self::e($t['menu_empty']));
        }
        foreach ($options as $o) {
            $html .= sprintf(
                '<button type="button" class="spb-option" data-spb-act="%s" data-value="%s">%s</button>',
                self::e($act),
                self::e($o['value']),
                self::e($o['label'])
            );
        }
        return $html . '</div>';
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
