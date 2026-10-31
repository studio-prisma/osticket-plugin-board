<?php

declare(strict_types=1);

/**
 * Which ticket actions the quick view offers.
 *
 * Pure: takes facts about one ticket and one agent, returns the action list.
 * The conditions mirror include/staff/ticket-view.inc.php and
 * include/staff/templates/status-options.tmpl.php, so the panel offers what
 * the ticket view would. Every action opens the matching core dialog under
 * scp/ajax.php/tickets/..., which checks permissions again on submit; this
 * list only decides what is worth showing.
 */
final class BoardActions
{
    /**
     * @param array $f  facts: id, open, assigned, has_staff, closeable,
     *                  answered, can_close, can_assign, can_claim, can_release,
     *                  can_transfer, can_refer, can_mark_answered, can_edit,
     *                  priority_field_id, can_create_task, status_id
     * @param array $statuses  [id, name, state] for open and closed states
     * @param array $t  BoardI18n strings
     * @return array<int, array{group:string, label:string, path:string}>
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
                if ($s['state'] === 'closed' && empty($f['closeable'])) {
                    continue;
                }
                $action = $s['state'] === 'closed' ? 'close' : ($s['state'] === 'open' ? 'reopen' : '');
                if ($action === '') {
                    continue;
                }
                $out[] = array(
                    'group' => 'status',
                    'label' => (string) $s['name'],
                    'path'  => $base . 'status/' . $action . '/' . (int) $s['id'],
                );
            }
        }

        // Assignment.
        if (!empty($f['open']) && !empty($f['can_assign'])) {
            // Two targets, like the ticket view's assign menu; plain
            // .../assign would pick one for the agent.
            $out[] = array('group' => 'assign', 'label' => $t['act_assign_agent'], 'path' => $base . 'assign/agents');
            $out[] = array('group' => 'assign', 'label' => $t['act_assign_team'], 'path' => $base . 'assign/teams');
            if (!empty($f['can_claim']) && empty($f['has_staff'])) {
                $out[] = array('group' => 'assign', 'label' => $t['act_claim'], 'path' => $base . 'claim');
            }
        }
        if (!empty($f['assigned']) && !empty($f['can_release'])) {
            $out[] = array('group' => 'assign', 'label' => $t['act_release'], 'path' => $base . 'release');
        }

        // Hand over.
        if (!empty($f['can_transfer'])) {
            $out[] = array('group' => 'route', 'label' => $t['act_transfer'], 'path' => $base . 'transfer');
        }
        if (!empty($f['can_refer'])) {
            $out[] = array('group' => 'route', 'label' => $t['act_refer'], 'path' => $base . 'refer');
        }

        // Mark and edit.
        if (!empty($f['open']) && !empty($f['can_mark_answered'])) {
            $out[] = !empty($f['answered'])
                ? array('group' => 'mark', 'label' => $t['act_unanswered'], 'path' => $base . 'mark/unanswered')
                : array('group' => 'mark', 'label' => $t['act_answered'], 'path' => $base . 'mark/answered');
        }
        if (!empty($f['can_edit']) && (int) ($f['priority_field_id'] ?? 0) > 0) {
            $out[] = array('group' => 'mark', 'label' => $t['act_priority'],
                'path' => $base . 'field/' . (int) $f['priority_field_id'] . '/edit');
        }
        if (!empty($f['can_create_task'])) {
            $out[] = array('group' => 'mark', 'label' => $t['act_task'], 'path' => $base . 'add-task');
        }

        return $out;
    }
}
