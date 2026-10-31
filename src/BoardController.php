<?php

declare(strict_types=1);

/**
 * Request handlers for both boards.
 *
 * Authentication is done before these run: scp/ajax.php requires
 * staff.inc.php (answers 403 without an agent session), ajax.php requires
 * client.inc.php (sets $thisclient or leaves it null). Both bootstraps also
 * reject any POST without a valid CSRF token. The handlers check again
 * anyway. The board must not depend on a single line in a core file.
 */
final class BoardController
{
    /** @var array{enable_staff:bool, enable_client:bool, client_nav:bool, closed_days:int, max_cards:int, wip_limits:string, refresh_seconds:int} */
    private static $settings = array(
        'enable_staff'    => true,
        'enable_client'   => false,
        'client_nav'      => true,
        'closed_days'     => 14,
        'max_cards'       => 300,
        'wip_limits'      => '',
        'refresh_seconds' => 60,
        'next_after_close' => true,
        'staff_landing'   => true,
        'next_to'         => array(),
        'next_from'       => array(),
        'next_from_acts'  => array('status'),
    );

    public static function configure(array $raw): void
    {
        self::$settings = self::normalise($raw);
    }

    public static function settings(): array
    {
        return self::$settings;
    }

    /** Clamp and cast raw plugin config. Public for the unit tests. */
    public static function normalise(array $raw): array
    {
        $days = (int) ($raw['closed_days'] ?? 14);
        $max = (int) ($raw['max_cards'] ?? 300);
        $refresh = (int) ($raw['refresh_seconds'] ?? 60);
        return array(
            'enable_staff'    => (bool) ($raw['enable_staff'] ?? true),
            'enable_client'   => (bool) ($raw['enable_client'] ?? false),
            'client_nav'      => (bool) ($raw['client_nav'] ?? true),
            'closed_days'     => max(0, min(365, $days)),
            'max_cards'       => max(10, min(2000, $max)),
            'wip_limits'      => substr((string) ($raw['wip_limits'] ?? ''), 0, 500),
            // 0 is off; anything else between 15 seconds and 10 minutes.
            'refresh_seconds' => $refresh <= 0 ? 0 : max(15, min(600, $refresh)),
            'next_after_close' => (bool) ($raw['next_after_close'] ?? true),
            'staff_landing'   => (bool) ($raw['staff_landing'] ?? true),
            'next_to'         => array_map('intval', self::keyList($raw['next_to_statuses'] ?? null, '/^[1-9]\d{0,9}$/')),
            'next_from'       => array_map('intval', self::keyList($raw['next_from_statuses'] ?? null, '/^[1-9]\d{0,9}$/')),
            'next_from_acts'  => self::keyList($raw['next_from_actions'] ?? null, '/^(status|assign|transfer|priority|mark)$/') ?: array('status'),
        );
    }

    /**
     * Keys of a multi-select setting. osTicket hands them over as
     * key => label, as stored JSON or, from older forms, comma-separated.
     * Only keys matching the pattern survive. Public for the unit tests.
     *
     * @return string[]
     */
    public static function keyList($raw, string $pattern): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array_fill_keys(array_map('trim', explode(',', $raw)), true);
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach (array_keys($raw) as $key) {
            $key = (string) $key;
            if (preg_match($pattern, $key) && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return array_slice($out, 0, 50);
    }

    // ------------------------------------------------------------------
    // Agent panel: /scp/ajax.php/board
    // ------------------------------------------------------------------

    public static function staffPage(): string
    {
        global $thisstaff, $nav;

        if (!self::$settings['enable_staff'] || !($thisstaff instanceof Staff)) {
            Http::response(404, 'Not found');
            exit;
        }
        if (self::staffGated($thisstaff)) {
            // index.php shows the password, 2FA or upgrade screen the agent
            // still owes.
            Http::redirect(ROOT_PATH . 'scp/index.php');
            exit;
        }
        // scp/ajax.php skips the navigation (AJAX_REQUEST); the page needs it.
        if (!$nav) {
            $nav = new StaffNav($thisstaff);
        }
        $nav->setActiveTab('apps');

        $s = self::$settings;
        $strings = BoardI18n::strings(self::language());
        $view = self::staffView($thisstaff, $_GET);

        $board = BoardModel::build($view['statuses'], $view['result']['rows'], $view['sort']);
        $lanes = $view['group'] !== ''
            ? BoardModel::lanes($board, $view['group'], $strings['lane_none_' . $view['group']])
            : null;
        $base = ROOT_PATH . BoardPlugin::STAFF_BASE;

        $html = BoardRenderer::render($board, array(
            'mode'          => 'staff',
            'ticket_url'    => ROOT_PATH . 'scp/tickets.php?id=',
            'move_url'      => $base . '/move',
            'strings'       => $strings,
            'closed_days'   => $view['queue'] ? 0 : $s['closed_days'],
            'max_cards'     => $s['max_cards'],
            'truncated'     => $view['result']['truncated'],
            'queues'        => BoardQuery::queueOptions($thisstaff),
            'queue_id'      => $view['queue'] ? (int) $view['queue']->getId() : 0,
            'queue_missing' => $view['queue_missing'],
            'queue_action'  => $base,
            'search_url'    => ROOT_PATH . 'scp/tickets.php',
            'panel_url'     => $base . '/panel/',
            'next_after_close' => $s['next_after_close'],
            'next_to'         => $s['next_to'],
            'next_from'       => $s['next_from'],
            'next_from_acts'  => $s['next_from_acts'],
            'sort'          => $view['sort'],
            'group'         => $view['group'],
            'lanes'         => $lanes,
            'limits'        => BoardModel::parseLimits($s['wip_limits'], $view['statuses']),
            'now'           => BoardQuery::dbNow(),
            'refresh_url'   => $s['refresh_seconds'] > 0 ? $base . '/state' : '',
            'refresh_seconds' => $s['refresh_seconds'],
            'signature'     => BoardModel::signature($view['result']['rows']),
        ));

        $q = $view['queue'];
        return self::page('staff', $q ? $strings['title'] . ': ' . $q->getName() : $strings['title'], $html);
    }

    /**
     * Everything that decides which tickets the agent board shows, shared by
     * the page and the live-refresh check so both always agree.
     */
    private static function staffView(Staff $staff, array $get): array
    {
        $s = self::$settings;
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = array();
        }
        $queueId = self::selectedQueueId($get, $_SESSION);
        $queue = $queueId > 0 ? BoardQuery::queueFor($staff, $queueId) : null;
        if ($queueId > 0 && !$queue) {
            // Forget it, so the notice shows once and the next visit is clean.
            unset($_SESSION[self::QUEUE_SESSION_KEY]);
        }

        if ($queue) {
            // The queue's criteria decide which states show up, so the closed
            // window of the default view doesn't apply; closed columns stay.
            $result = BoardQuery::forStaffQueue($staff, $queue, $s['max_cards']);
            $statuses = BoardQuery::statuses(max(1, $s['closed_days']));
        } else {
            $result = BoardQuery::forStaff($staff, $s['closed_days'], $s['max_cards']);
            $statuses = BoardQuery::statuses($s['closed_days']);
        }

        return array(
            'queue'         => $queue,
            'queue_missing' => $queueId > 0 && !$queue,
            'result'        => $result,
            'statuses'      => $statuses,
            'sort'          => self::selectedOption($get, $_SESSION, 'sort', BoardModel::SORTS),
            'group'         => self::selectedOption($get, $_SESSION, 'group', BoardModel::GROUPS),
        );
    }

    /**
     * Live refresh: the fingerprint of what the board would show right now.
     * Same view rules as the page, so a change anywhere that touches the
     * agent's board changes it. GET only, nothing is written.
     */
    public static function staffState(): string
    {
        global $thisstaff;

        header('Cache-Control: no-store');
        if (!self::$settings['enable_staff'] || !($thisstaff instanceof Staff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        if (self::staffGated($thisstaff)) {
            return self::json(403, array('error' => 'account_action_required'));
        }
        $view = self::staffView($thisstaff, array_intersect_key($_GET, array('queue' => 1)));
        return self::json(200, array(
            'signature' => BoardModel::signature($view['result']['rows']),
            'count'     => count($view['result']['rows']),
        ));
    }

    /**
     * A remembered choice from a fixed list: ?<name>=<value> selects and
     * remembers it, an unknown value falls back to the first entry. Public
     * for the unit tests.
     */
    public static function selectedOption(array $get, array &$session, string $name, array $allowed): string
    {
        $key = 'spb:staff_' . $name;
        if (array_key_exists($name, $get)) {
            $value = is_string($get[$name]) && in_array($get[$name], $allowed, true) ? $get[$name] : $allowed[0];
            $session[$key] = $value;
            return $value;
        }
        $value = $session[$key] ?? $allowed[0];
        return in_array($value, $allowed, true) ? $value : $allowed[0];
    }

    public const QUEUE_SESSION_KEY = 'spb:staff_queue';

    /**
     * Which queue the board shows. ?queue=<id> selects and remembers it,
     * ?queue=all goes back to the default view; without the parameter the
     * remembered choice applies. Access is checked by the caller.
     */
    public static function selectedQueueId(array $get, array &$session): int
    {
        if (array_key_exists('queue', $get)) {
            $raw = is_string($get['queue']) ? $get['queue'] : '';
            $id = ctype_digit($raw) ? (int) $raw : 0;
            if ($id > 0) {
                $session[self::QUEUE_SESSION_KEY] = $id;
            } else {
                unset($session[self::QUEUE_SESSION_KEY]);
            }
            return $id;
        }
        return (int) ($session[self::QUEUE_SESSION_KEY] ?? 0);
    }

    public static function staffMove(): string
    {
        global $thisstaff, $ost;

        header('Cache-Control: no-store');

        if (!self::$settings['enable_staff'] || !($thisstaff instanceof Staff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        if (self::staffGated($thisstaff)) {
            return self::json(403, array('error' => 'account_action_required'));
        }
        if (!$ost || !$ost->checkCSRFToken()) {
            return self::json(400, array('error' => 'csrf'));
        }

        // Unknown and invisible tickets answer the same, so the endpoint
        // cannot be used to probe which ticket IDs exist.
        $ticket = self::visibleTicket($thisstaff, $_POST['ticket_id'] ?? 0);
        if (!$ticket) {
            return self::json(404, array('error' => 'not_found'));
        }

        list($code, $payload) = self::changeStatus($ticket, $thisstaff, (int) ($_POST['status_id'] ?? 0));
        return self::json($code, $payload);
    }

    private static function visibleTicket($staff, $rawId)
    {
        $id = is_scalar($rawId) ? (int) $rawId : 0;
        $ticket = $id > 0 ? Ticket::lookup($id) : null;
        return $ticket && $ticket->checkStaffPerm($staff) ? $ticket : null;
    }

    /**
     * Status change with the core dialog's permission matrix. Shared by drag
     * and drop and the quick view, so both refuse the same things.
     *
     * @return array{0:int, 1:array}  HTTP code and JSON payload
     */
    private static function changeStatus($ticket, $staff, int $statusId): array
    {
        $status = $statusId > 0 ? TicketStatus::lookup($statusId) : null;
        $state = $status ? strtolower((string) $status->getState()) : '';
        if (!$status || !in_array($state, array('open', 'closed'), true)) {
            return array(422, array('error' => 'invalid_status'));
        }
        $before = $ticket->getStatus();
        $previous = $before ? array('id' => (int) $before->getId(), 'name' => (string) $before->getLocalName()) : null;
        if ((int) $ticket->getStatusId() === (int) $status->getId()) {
            return array(200, array('ok' => true, 'unchanged' => true, 'previous' => $previous));
        }

        $error = self::permissionError($ticket, $staff, $state);
        if ($error !== null) {
            return array(403, array('error' => $error));
        }

        $errors = array();
        if (!$ticket->setStatus($status, '', $errors)) {
            // 422, not 409: Http::header_code_verbose() maps unknown codes to 500.
            return array(422, array('error' => 'rejected', 'message' => self::firstError($errors)));
        }

        return array(200, array(
            'ok'       => true,
            'ticket'   => (int) $ticket->getId(),
            'status'   => array('id' => (int) $status->getId(), 'name' => (string) $status->getLocalName(), 'state' => $state),
            'previous' => $previous,
        ));
    }

    private static function firstError($errors): string
    {
        if (!is_array($errors) || !$errors) {
            return '';
        }
        $e = $errors['err'] ?? reset($errors);
        return strip_tags(is_array($e) ? (string) reset($e) : (string) $e);
    }

    // ------------------------------------------------------------------
    // Quick-view actions: /scp/ajax.php/board/act
    // ------------------------------------------------------------------

    /**
     * Runs one quick-view action without osTicket's dialog: status, claim,
     * release, mark (un)answered, assign to an agent or team, transfer,
     * priority. Every action goes through the same core form or method as
     * its dialog, behind the same permission check as the dialog's handler
     * in include/ajax.tickets.php.
     *
     * If `note` carries text, it is stored as an internal note (plain text)
     * after the action succeeded, so a note never lands on an action that
     * didn't happen.
     */
    public static function staffAct(): string
    {
        global $thisstaff, $ost;

        header('Cache-Control: no-store');
        if (!self::$settings['enable_staff'] || !($thisstaff instanceof Staff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        if (self::staffGated($thisstaff)) {
            return self::json(403, array('error' => 'account_action_required'));
        }
        if (!$ost || !$ost->checkCSRFToken()) {
            return self::json(400, array('error' => 'csrf'));
        }

        $ticket = self::visibleTicket($thisstaff, $_POST['ticket_id'] ?? 0);
        if (!$ticket) {
            return self::json(404, array('error' => 'not_found'));
        }

        $act = is_string($_POST['act'] ?? null) ? $_POST['act'] : '';
        if (!in_array($act, BoardActions::ACTS, true)) {
            return self::json(422, array('error' => 'invalid_action'));
        }
        $value = is_string($_POST['value'] ?? null) ? trim($_POST['value']) : '';

        $note = null;
        $rawNote = $_POST['note'] ?? '';
        if (is_string($rawNote) && trim($rawNote) !== '') {
            $note = self::noteText($rawNote);
            if ($note === null) {
                return self::json(422, array('error' => 'invalid_note'));
            }
        }

        list($code, $payload) = self::runAct($ticket, $thisstaff, $act, $value);
        if ($code !== 200 || empty($payload['ok'])) {
            return self::json($code, $payload);
        }

        if ($note !== null) {
            $errors = array();
            $payload['note'] = $ticket->postNote(array('title' => '', 'note' => new TextThreadEntryBody($note)),
                $errors, $thisstaff) ? 'saved' : 'failed';
        }
        return self::json(200, $payload);
    }

    /** @return array{0:int, 1:array} */
    private static function runAct($ticket, $staff, string $act, string $value): array
    {
        $denied = array(403, array('error' => 'forbidden'));
        $ok = array(200, array('ok' => true, 'ticket' => (int) $ticket->getId()));
        $errors = array();

        switch ($act) {
            case 'status':
                return ctype_digit($value)
                    ? self::changeStatus($ticket, $staff, (int) $value)
                    : array(422, array('error' => 'invalid_status'));

            case 'claim':
                // TicketsAjaxAPI::claim()
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_ASSIGN) || !$ticket->isOpen() || $ticket->getStaff()) {
                    return $denied;
                }
                $form = $ticket->getClaimForm();
                if (!$form->isValid() || !$ticket->claim($form, $errors)) {
                    return array(422, array('error' => 'rejected', 'message' => self::firstError($errors)));
                }
                return $ok;

            case 'release':
                // TicketsAjaxAPI::release(): every current assignment.
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_RELEASE) && !$staff->isManager()) {
                    return $denied;
                }
                $agent = $ticket->getStaff();
                $team = $ticket->getTeam();
                if (!$agent && !$team) {
                    return array(422, array('error' => 'not_assigned'));
                }
                $vars = array();
                if ($agent) {
                    $vars['sid'] = array($agent->getId());
                }
                if ($team) {
                    $vars['tid'] = array($team->getId());
                }
                if (!$ticket->release($vars, $errors)) {
                    return array(422, array('error' => 'rejected', 'message' => self::firstError($errors)));
                }
                $data = array();
                if ($agent && !$ticket->getStaff()) {
                    $data['staff'] = array($agent->getId(), (string) $agent->getName()->getOriginal());
                }
                if ($team && !$ticket->getTeam()) {
                    $data['team'] = $team->getId();
                }
                $ticket->logEvent('released', $data);
                return $ok;

            case 'answered':
            case 'unanswered':
                // TicketsAjaxAPI::markAs()
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_MARKANSWERED) && !$staff->isManager($ticket->getDept())) {
                    return $denied;
                }
                if (!$ticket->isOpen()) {
                    return array(422, array('error' => 'not_open'));
                }
                $done = $act === 'answered' ? $ticket->markAnswered() : $ticket->markUnAnswered();
                if (!$done) {
                    return array(422, array('error' => 'rejected'));
                }
                $ticket->logActivity(sprintf(__('Ticket Marked %s'), ucfirst($act)),
                    sprintf(__('Ticket flagged as %s by %s'), $act, $staff->getName()));
                return $ok;

            case 'assign_agent':
            case 'assign_team':
                // TicketsAjaxAPI::assign()
                $target = $act === 'assign_agent' ? 'agents' : 'teams';
                $prefix = $act === 'assign_agent' ? 's' : 't';
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_ASSIGN) || !$ticket->isOpen()) {
                    return $denied;
                }
                if (!preg_match('/^' . $prefix . '\d+$/', $value)
                    || !self::isOption(self::assignChoices($ticket, $target), $value)) {
                    return array(422, array('error' => 'invalid_value'));
                }
                $form = $ticket->getAssignmentForm(array('assignee' => array($value), 'comments' => ''),
                    array('target' => $target));
                if (!$form || !$form->isValid() || !$ticket->assign($form, $errors)) {
                    return array(422, array('error' => 'rejected', 'message' => self::firstError($errors ?: ($form ? $form->errors() : array()))));
                }
                return $ok;

            case 'transfer':
                // TicketsAjaxAPI::transfer()
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_TRANSFER)) {
                    return $denied;
                }
                if (!ctype_digit($value) || !self::isOption(self::deptChoices($ticket), $value)) {
                    return array(422, array('error' => 'invalid_value'));
                }
                $form = $ticket->getTransferForm(array('dept' => array((int) $value), 'refer' => false, 'comments' => ''));
                if (!$form->isValid() || !$ticket->transfer($form, $errors)) {
                    return array(422, array('error' => 'rejected', 'message' => self::firstError($errors ?: $form->errors())));
                }
                return $ok;

            case 'priority':
                // TicketsAjaxAPI::editField() for the priority field.
                if (!$ticket->checkStaffPerm($staff, Ticket::PERM_EDIT) || !($field = $ticket->getField('priority'))) {
                    return $denied;
                }
                if (!ctype_digit($value) || !self::isOption(self::priorityChoices($ticket), $value)) {
                    return array(422, array('error' => 'invalid_value'));
                }
                $source = array();
                foreach ($field->getFormNames() as $name) {
                    $source[$name] = $value;
                }
                $form = $field->getEditForm($source);
                if (!$form->isValid() || !$ticket->updateField($form, $errors)) {
                    return array(422, array('error' => 'rejected', 'message' => self::firstError($errors ?: $form->errors())));
                }
                return $ok;
        }

        return array(422, array('error' => 'invalid_action'));
    }

    // Choice lists, taken from the very fields the core dialogs render.

    private static function assignChoices($ticket, string $target): array
    {
        $form = $ticket->getAssignmentForm(null, array('target' => $target));
        $field = $form ? $form->getField('assignee') : null;
        return $field ? BoardActions::options($field->getChoices()) : array();
    }

    private static function deptChoices($ticket): array
    {
        $field = $ticket->getTransferForm()->getField('dept');
        return $field ? BoardActions::options($field->getChoices(), (string) $ticket->getDeptId()) : array();
    }

    private static function priorityChoices($ticket): array
    {
        $field = $ticket->getField('priority');
        $current = $field && $field->getAnswer() ? (string) $field->getAnswer()->getIdValue() : null;
        return $field ? BoardActions::options($field->getChoices(), $current) : array();
    }

    private static function isOption(array $options, string $value): bool
    {
        foreach ($options as $o) {
            if ($o['value'] === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * staff.inc.php exempts ajax.php from the forced password change, the
     * forced 2FA setup and the pending-upgrade screen, because AJAX calls
     * can't show them. The board is a full page on that entry point, so it
     * applies those gates itself instead of inheriting the exemption.
     */
    public static function staffGated($staff): bool
    {
        global $ost;

        return ($ost && $ost->isUpgradePending())
            || $staff->forcePasswdChange()
            || $staff->force2faConfig();
    }

    /**
     * Same permission matrix as the core status dialog
     * (include/ajax.tickets.php, setTicketStatus).
     */
    public static function permissionError($ticket, $staff, string $state): ?string
    {
        $role = $ticket->getRole($staff);
        if (!$role) {
            return 'forbidden';
        }
        if ($state === 'open' && !$role->hasPerm(Ticket::PERM_CLOSE) && !$role->hasPerm(Ticket::PERM_CREATE)) {
            return 'forbidden';
        }
        if ($state === 'closed' && !$role->hasPerm(Ticket::PERM_CLOSE)) {
            return 'forbidden';
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Quick view: /scp/ajax.php/board/panel/{id}, /scp/ajax.php/board/note
    // ------------------------------------------------------------------

    /** Upper bound for an internal note from the board, in characters. */
    public const NOTE_MAX = 10000;

    /** Sub-tickets listed in the quick view; more point to the ticket view. */
    public const SUBTICKET_MAX = 50;

    /**
     * HTML fragment for the quick view. Unknown and invisible tickets get
     * the same 404 as on the move endpoint.
     */
    public static function staffPanel($tid): string
    {
        global $thisstaff, $cfg;

        if (!self::$settings['enable_staff'] || !($thisstaff instanceof Staff)) {
            Http::response(404, 'Not found');
            exit;
        }
        if (self::staffGated($thisstaff)) {
            Http::response(403, 'Account action required');
            exit;
        }
        $ticket = (int) $tid > 0 ? Ticket::lookup((int) $tid) : null;
        if (!$ticket || !$ticket->checkStaffPerm($thisstaff)) {
            Http::response(404, 'Not found');
            exit;
        }

        $strings = BoardI18n::strings(self::language());
        $statuses = array();
        foreach (BoardQuery::statuses(1) as $s) {
            $statuses[] = array('id' => $s['id'], 'name' => $s['name'], 'state' => $s['state']);
        }
        $actions = BoardActions::build(self::facts($ticket, $thisstaff), $statuses, $strings);

        // Lists for the in-panel pickers, only for actions the agent gets.
        $menus = array();
        foreach ($actions as $a) {
            switch ($a['menu'] ?? '') {
                case 'agents':
                    $menus['agents'] = self::assignChoices($ticket, 'agents');
                    break;
                case 'teams':
                    $menus['teams'] = self::assignChoices($ticket, 'teams');
                    break;
                case 'depts':
                    $menus['depts'] = self::deptChoices($ticket);
                    break;
                case 'priority':
                    $menus['priority'] = self::priorityChoices($ticket);
                    break;
            }
        }

        // The core preview template reads $ticket, $thisstaff and $cfg from
        // the including scope, like TicketsAjaxAPI::previewTicket().
        ob_start();
        include STAFFINC_DIR . 'templates/ticket-preview.tmpl.php';
        $preview = (string) ob_get_clean();
        $children = self::childTickets($ticket, $thisstaff, $statuses);

        header('Cache-Control: no-store');
        Http::response(200, BoardRenderer::panel(array(
            'id'           => (int) $ticket->getId(),
            'number'       => (string) $ticket->getNumber(),
            'subject'      => (string) $ticket->getSubject(),
            'ticket_url'   => ROOT_PATH . 'scp/tickets.php?id=' . (int) $ticket->getId(),
            'note_url'     => ROOT_PATH . BoardPlugin::STAFF_BASE . '/note',
            'act_url'      => ROOT_PATH . BoardPlugin::STAFF_BASE . '/act',
            'actions'      => $actions,
            'menus'        => $menus,
            'preview_html' => $preview,
            'children'     => $children['rows'],
            'children_more' => $children['more'],
            'ticket_base'  => ROOT_PATH . 'scp/tickets.php?id=',
            'strings'      => $strings,
        )), 'text/html');
        exit;
    }

    /**
     * Sub-tickets of $ticket for the quick view, oldest first. Same rules as
     * on the board: ticket_pid only, and the agent's own visibility, so a
     * sub-ticket the agent may not see is not listed.
     *
     * @return array{rows: array, more: bool}
     */
    private static function childTickets($ticket, Staff $staff, array $statuses): array
    {
        if (class_exists('TicketForm')) {
            TicketForm::ensureDynamicDataView();
        }
        $known = array_column($statuses, null, 'id');
        $q = Ticket::objects()
            ->filter(array('ticket_pid' => (int) $ticket->getId()))
            ->filter($staff->getTicketsVisibility())
            ->distinct('ticket_id')
            ->order_by('ticket_id')
            ->limit(self::SUBTICKET_MAX + 1)
            ->values('ticket_id', 'number', 'status_id', 'status__name', 'status__state',
                'cdata__subject', 'staff__firstname', 'staff__lastname');

        $rows = array();
        foreach ($q as $r) {
            $status = $known[(int) $r['status_id']] ?? array(
                'name' => (string) $r['status__name'], 'state' => strtolower((string) $r['status__state']));
            $rows[] = array(
                'id'           => (int) $r['ticket_id'],
                'number'       => (string) $r['number'],
                'subject'      => (string) $r['cdata__subject'],
                'status_name'  => (string) $status['name'],
                'status_state' => (string) $status['state'],
                'assignee'     => trim($r['staff__firstname'] . ' ' . $r['staff__lastname']),
            );
        }
        $more = count($rows) > self::SUBTICKET_MAX;
        return array('rows' => array_slice($rows, 0, self::SUBTICKET_MAX), 'more' => $more);
    }

    /**
     * Facts for BoardActions, read the way include/staff/ticket-view.inc.php
     * reads them.
     */
    private static function facts($ticket, $staff): array
    {
        $role = $ticket->getRole($staff);
        $dept = $ticket->getDept();
        $isManager = $dept && $dept->isManager($staff);
        $has = static function ($perm) use ($role) {
            return $role && $role->hasPerm($perm);
        };
        $pf = $ticket->getPriorityField();

        return array(
            'id'                => (int) $ticket->getId(),
            'status_id'         => (int) $ticket->getStatusId(),
            'open'              => (bool) $ticket->isOpen(),
            'assigned'          => (bool) $ticket->isAssigned(),
            'has_staff'         => (bool) $ticket->getStaff(),
            'closeable'         => $ticket->isCloseable() === true,
            'answered'          => (bool) $ticket->isAnswered(),
            'can_close'         => $has(Ticket::PERM_CLOSE),
            'can_assign'        => $has(Ticket::PERM_ASSIGN),
            'can_claim'         => $dept && (!$dept->assignMembersOnly() || $dept->isMember($staff)),
            'can_release'       => $isManager || $has(Ticket::PERM_RELEASE),
            'can_transfer'      => $has(Ticket::PERM_TRANSFER),
            'can_refer'         => $has(Ticket::PERM_REFER),
            'can_mark_answered' => $isManager || $has(Ticket::PERM_MARKANSWERED),
            'can_edit'          => $has(Ticket::PERM_EDIT),
            'priority_field_id' => $pf ? (int) $pf->getId() : 0,
            'can_create_task'   => (bool) $ticket->checkStaffPerm($staff, Task::PERM_CREATE),
        );
    }

    /**
     * Internal note from the quick view. Goes through Ticket::postNote(),
     * the method the ticket view's note form ends in. The text is stored as
     * a TextThreadEntryBody (format "text"), so osTicket escapes it on
     * display and no markup gets into the thread from here, whether or not
     * rich text is enabled.
     */
    public static function staffNote(): string
    {
        global $thisstaff, $ost;

        header('Cache-Control: no-store');
        if (!self::$settings['enable_staff'] || !($thisstaff instanceof Staff)) {
            return self::json(404, array('error' => 'not_found'));
        }
        if (self::staffGated($thisstaff)) {
            return self::json(403, array('error' => 'account_action_required'));
        }
        if (!$ost || !$ost->checkCSRFToken()) {
            return self::json(400, array('error' => 'csrf'));
        }

        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $ticket = $ticketId > 0 ? Ticket::lookup($ticketId) : null;
        if (!$ticket || !$ticket->checkStaffPerm($thisstaff)) {
            return self::json(404, array('error' => 'not_found'));
        }

        $text = self::noteText($_POST['note'] ?? '');
        if ($text === null) {
            return self::json(422, array('error' => 'invalid_note'));
        }

        $errors = array();
        $note = $ticket->postNote(array(
            'title' => '',
            'note'  => new TextThreadEntryBody($text),
        ), $errors, $thisstaff);
        if (!$note) {
            return self::json(422, array('error' => 'rejected'));
        }
        return self::json(200, array('ok' => true, 'ticket' => (int) $ticket->getId()));
    }

    /**
     * Trimmed note text, or null if empty, too long or not a string. Public
     * for the unit tests.
     */
    public static function noteText($raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $text = trim(str_replace("\r\n", "\n", $raw));
        // preg with /u instead of mbstring: valid UTF-8 check and character
        // count without depending on an optional extension.
        if ($text === '' || !preg_match('//u', $text)) {
            return null;
        }
        if (preg_match_all('/./su', $text) > self::NOTE_MAX) {
            return null;
        }
        return $text;
    }

    // ------------------------------------------------------------------
    // Client portal: /ajax.php/board
    // ------------------------------------------------------------------

    public static function clientPage(): string
    {
        global $thisclient;

        if (!self::$settings['enable_client']) {
            Http::response(404, 'Not found');
            exit;
        }
        if (!self::clientAllowed($thisclient)) {
            Http::redirect(ROOT_PATH . 'login.php');
            exit;
        }

        $s = self::$settings;
        $strings = BoardI18n::strings(self::language());
        $result = BoardQuery::forClient($thisclient, $s['closed_days'], $s['max_cards']);
        $board = BoardModel::build(BoardQuery::statuses($s['closed_days']), $result['rows']);

        $html = BoardRenderer::render($board, array(
            'mode'        => 'client',
            'ticket_url'  => ROOT_PATH . 'tickets.php?id=',
            'strings'     => $strings,
            'closed_days' => $s['closed_days'],
            'max_cards'   => $s['max_cards'],
            'truncated'   => $result['truncated'],
        ));

        return self::page('client', $strings['title'], $html);
    }

    /**
     * Registered portal accounts only. A guest session (ticket access link)
     * is bound to a single ticket and already has that ticket's page.
     */
    public static function clientAllowed($client): bool
    {
        return $client
            && is_object($client)
            && method_exists($client, 'isValid') && $client->isValid()
            && !$client->isGuest();
    }

    public static function asset(string $name): string
    {
        return BoardAssets::serve($name);
    }

    // ------------------------------------------------------------------

    // $heading, not $title: the core header templates assign $title in the
    // including scope and would overwrite it.
    private static function page(string $panel, string $heading, string $body): string
    {
        // The core header and footer read these from the including scope.
        global $ost, $cfg, $thisstaff, $thisclient, $nav, $errors, $msg, $warn, $info;

        $assets = ROOT_PATH . ($panel === 'staff' ? BoardPlugin::STAFF_BASE : BoardPlugin::CLIENT_BASE) . '/assets/';
        $v = BoardAssets::version();

        if ($panel === 'staff') {
            // The page lives at scp/ajax.php/board, but the core header,
            // footer and scp.js use paths relative to scp/ (ajax.php/i18n,
            // the dialog forms' ajax.php/tickets/...). Without a base they'd
            // resolve to scp/ajax.php/ajax.php/... The board's own links
            // are absolute.
            $ost->addExtraHeader(sprintf('<base href="%s" />', BoardRenderer::e(ROOT_PATH . 'scp/')));
        }

        ob_start();
        if ($panel === 'staff') {
            require STAFFINC_DIR . 'header.inc.php';
        } else {
            require CLIENTINC_DIR . 'header.inc.php';
        }
        // The stylesheet sits in the body on purpose: the agent panel swaps
        // page content via PJAX and keeps the old <head>, so a link added
        // with addExtraHeader() would be missing after in-app navigation.
        printf('<link rel="stylesheet" type="text/css" href="%s" />', BoardRenderer::e($assets . 'board.css?v=' . $v));
        printf('<h1 class="spb-title">%s</h1>', BoardRenderer::e($heading));
        echo $body;
        printf('<script src="%s"></script>', BoardRenderer::e($assets . 'board.js?v=' . $v));
        if ($panel === 'staff') {
            require STAFFINC_DIR . 'footer.inc.php';
        } else {
            require CLIENTINC_DIR . 'footer.inc.php';
        }
        return (string) ob_get_clean();
    }

    /**
     * Sends the response and ends the request. Returning the JSON instead
     * would lose the status code: scp/ajax.php answers every string that
     * starts with "{" with Http::response(200, ...).
     */
    private static function json(int $code, array $payload): string
    {
        Http::response($code, (string) json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json');
        exit;
    }

    private static function language(): string
    {
        return class_exists('Internationalization')
            ? (string) Internationalization::getCurrentLanguage()
            : 'en';
    }
}
