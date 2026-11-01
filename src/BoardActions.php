<?php

declare(strict_types=1);

/**
 * Which ticket actions the quick view offers, and how each one runs.
 *
 * Pure: takes facts about one ticket and one agent, returns the action list.
 * The conditions mirror include/staff/ticket-view.inc.php and
 * include/staff/templates/status-options.tmpl.php, so the panel offers what
 * the ticket view would. The endpoint that runs an action checks the same
 * permissions again; this list only decides what is worth showing.
 *
 * Each entry has exactly one of:
 *   act    run on click through BoardController::staffAct (with value)
 *   menu   open a list in the panel; picking an entry runs `menu_act`
 *   path   open osTicket's own dialog under scp/ajax.php/<path>
 */
final class BoardActions
{
    /** Actions the board runs itself. Anything else is refused. */
    public const ACTS = array('status', 'claim', 'release', 'answered', 'unanswered',
        'assign_agent', 'assign_team', 'transfer', 'priority');

    /**
     * @param array $f  facts: id, open, assigned, has_staff, closeable,
     *                  answered, can_close, can_assign, can_claim, can_release,
     *                  can_transfer, can_refer, can_mark_answered, can_edit,
     *                  priority_field_id, can_create_task, status_id
     * @param array $statuses  [id, name, state, enabled?] for open and closed states
     * @param array $t  BoardI18n strings
     * @return array<int, array<string, mixed>>
     */
    public static function build(array $f, array $statuses, array $t): array
    {
        $id = (int) $f['id'];
        $base = 'tickets/' . $id . '/';
        $out = array();

        // Status: same rules as status-options.tmpl.php.
        if (!empty($f['can_close'])) {
            foreach ($statuses as $s) {
                if ((int) $s['id'] === (int) $f['status_id']) {
                    continue;
                }
                if (!in_array($s['state'], array('open', 'closed'), true)) {
                    continue;
                }
                // Disabled in the status list: the core menu leaves it out.
                if (array_key_exists('enabled', $s) && !$s['enabled']) {
                    continue;
                }
                if ($s['state'] === 'closed' && empty($f['closeable'])) {
                    continue;
                }
                $out[] = array('group' => 'status', 'label' => (string) $s['name'],
                    'act' => 'status', 'value' => (string) (int) $s['id'], 'state' => $s['state']);
            }
        }

        // Assignment.
        if (!empty($f['open']) && !empty($f['can_assign'])) {
            $out[] = array('group' => 'assign', 'label' => $t['act_assign_agent'],
                'menu' => 'agents', 'menu_act' => 'assign_agent');
            $out[] = array('group' => 'assign', 'label' => $t['act_assign_team'],
                'menu' => 'teams', 'menu_act' => 'assign_team');
            if (!empty($f['can_claim']) && empty($f['has_staff'])) {
                $out[] = array('group' => 'assign', 'label' => $t['act_claim'], 'act' => 'claim', 'value' => '');
            }
        }
        if (!empty($f['assigned']) && !empty($f['can_release'])) {
            $out[] = array('group' => 'assign', 'label' => $t['act_release'], 'act' => 'release', 'value' => '');
        }

        // Hand over.
        if (!empty($f['can_transfer'])) {
            $out[] = array('group' => 'route', 'label' => $t['act_transfer'],
                'menu' => 'depts', 'menu_act' => 'transfer');
        }
        if (!empty($f['can_refer'])) {
            $out[] = array('group' => 'route', 'label' => $t['act_refer'], 'path' => $base . 'refer');
        }

        // Mark and edit.
        if (!empty($f['open']) && !empty($f['can_mark_answered'])) {
            $out[] = !empty($f['answered'])
                ? array('group' => 'mark', 'label' => $t['act_unanswered'], 'act' => 'unanswered', 'value' => '')
                : array('group' => 'mark', 'label' => $t['act_answered'], 'act' => 'answered', 'value' => '');
        }
        if (!empty($f['can_edit']) && (int) ($f['priority_field_id'] ?? 0) > 0) {
            $out[] = array('group' => 'mark', 'label' => $t['act_priority'],
                'menu' => 'priority', 'menu_act' => 'priority');
        }
        if (!empty($f['can_create_task'])) {
            $out[] = array('group' => 'mark', 'label' => $t['act_task'], 'path' => $base . 'add-task');
        }

        return $out;
    }

    /**
     * Flattens an osTicket choice list (possibly grouped, values may be
     * objects with __toString) into [value, label] pairs. Public for tests.
     *
     * @return array<int, array{value:string, label:string}>
     */
    public static function options($choices, ?string $current = null): array
    {
        $out = array();
        foreach ($choices ?: array() as $value => $label) {
            if (is_array($label) || $label instanceof Traversable) {
                foreach (self::options($label, $current) as $o) {
                    $out[] = $o;
                }
                continue;
            }
            $value = (string) $value;
            if ($value === '' || ($current !== null && $value === $current)) {
                continue;
            }
            $out[] = array('value' => $value, 'label' => (string) $label);
        }
        return $out;
    }
}
