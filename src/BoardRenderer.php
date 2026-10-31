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
     *                      queue_missing, queue_action, search_url
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
                ), JSON_UNESCAPED_UNICODE))
            );
        }

        $out = array();
        $out[] = '<div class="spb-wrap">';
        if ($staff && isset($ctx['queues'], $ctx['queue_action'])) {
            $out[] = self::queuePicker($ctx);
        }
        $out[] = sprintf('<p class="spb-hint">%s</p>', self::e($staff ? $t['drag_hint'] : $t['readonly']));
        if (!empty($ctx['truncated'])) {
            $out[] = sprintf('<p class="spb-hint spb-warn">%s</p>', self::e(sprintf($t['truncated'], (int) $ctx['max_cards'])));
        }
        $out[] = '<p class="spb-status" role="status" aria-live="polite"></p>';
        $out[] = sprintf('<div class="spb-board"%s>', $attrs);

        foreach ($board['columns'] as $col) {
            $out[] = self::column($col, $ctx, $staff);
        }

        $out[] = '</div></div>';
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

    private static function column(array $col, array $ctx, bool $staff): string
    {
        $t = $ctx['strings'];
        $s = $col['status'];
        $html = sprintf(
            '<section class="spb-col spb-state-%s" data-status-id="%d" data-status-name="%s" data-state="%s">',
            self::e($s['state']),
            (int) $s['id'],
            self::e($s['name']),
            self::e($s['state'])
        );
        $html .= sprintf(
            '<header class="spb-col-head"><h3>%s</h3><span class="spb-count">%d</span></header>',
            self::e($s['name']),
            count($col['cards'])
        );
        if ($s['state'] === 'closed' && !empty($ctx['closed_days'])) {
            $html .= sprintf('<p class="spb-col-note">%s</p>', self::e(sprintf($t['closed_hint'], (int) $ctx['closed_days'])));
        }
        $html .= '<ol class="spb-cards">';
        if (!$col['cards']) {
            $html .= sprintf('<li class="spb-empty">%s</li>', self::e($t['empty']));
        }
        foreach ($col['cards'] as $card) {
            $html .= self::card($card, $ctx, $staff);
        }
        $html .= '</ol></section>';
        return $html;
    }

    private static function card(array $c, array $ctx, bool $staff): string
    {
        $t = $ctx['strings'];
        $url = $ctx['ticket_url'] . rawurlencode((string) $c['id']);

        $html = sprintf(
            '<li class="spb-card%s" data-ticket-id="%d" data-number="%s"%s>',
            $c['overdue'] ? ' spb-overdue' : '',
            (int) $c['id'],
            self::e($c['number']),
            $staff ? ' draggable="true" tabindex="0"' : ''
        );
        $html .= sprintf(
            '<a class="spb-num" href="%s">#%s</a>',
            self::e($url),
            self::e($c['number'])
        );
        if ($c['overdue']) {
            $html .= sprintf(' <span class="spb-flag">%s</span>', self::e($t['overdue']));
        }
        $html .= sprintf('<div class="spb-subject">%s</div>', self::e($c['subject']));

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

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
