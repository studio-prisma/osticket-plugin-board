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
    /** @var array{enable_staff:bool, enable_client:bool, client_nav:bool, closed_days:int, max_cards:int} */
    private static $settings = array(
        'enable_staff'  => true,
        'enable_client' => false,
        'client_nav'    => true,
        'closed_days'   => 14,
        'max_cards'     => 300,
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
        return array(
            'enable_staff'  => (bool) ($raw['enable_staff'] ?? true),
            'enable_client' => (bool) ($raw['enable_client'] ?? false),
            'client_nav'    => (bool) ($raw['client_nav'] ?? true),
            'closed_days'   => max(0, min(365, $days)),
            'max_cards'     => max(10, min(2000, $max)),
        );
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
        $result = BoardQuery::forStaff($thisstaff, $s['closed_days'], $s['max_cards']);
        $board = BoardModel::build(BoardQuery::statuses($s['closed_days']), $result['rows']);

        $html = BoardRenderer::render($board, array(
            'mode'        => 'staff',
            'ticket_url'  => ROOT_PATH . 'scp/tickets.php?id=',
            'move_url'    => ROOT_PATH . BoardPlugin::STAFF_BASE . '/move',
            'strings'     => $strings,
            'closed_days' => $s['closed_days'],
            'max_cards'   => $s['max_cards'],
            'truncated'   => $result['truncated'],
        ));

        return self::page('staff', $strings['title'], $html);
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

        $ticketId = (int) ($_POST['ticket_id'] ?? 0);
        $statusId = (int) ($_POST['status_id'] ?? 0);

        // Unknown and invisible tickets answer the same, so the endpoint
        // cannot be used to probe which ticket IDs exist.
        $ticket = $ticketId > 0 ? Ticket::lookup($ticketId) : null;
        if (!$ticket || !$ticket->checkStaffPerm($thisstaff)) {
            return self::json(404, array('error' => 'not_found'));
        }

        $status = $statusId > 0 ? TicketStatus::lookup($statusId) : null;
        $state = $status ? strtolower((string) $status->getState()) : '';
        if (!$status || !in_array($state, array('open', 'closed'), true)) {
            return self::json(422, array('error' => 'invalid_status'));
        }
        if ((int) $ticket->getStatusId() === (int) $status->getId()) {
            return self::json(200, array('ok' => true, 'unchanged' => true));
        }

        $error = self::permissionError($ticket, $thisstaff, $state);
        if ($error !== null) {
            return self::json(403, array('error' => $error));
        }

        $errors = array();
        if (!$ticket->setStatus($status, '', $errors)) {
            // 422, not 409: Http::header_code_verbose() maps unknown codes to 500.
            return self::json(422, array(
                'error'   => 'rejected',
                'message' => is_array($errors) && isset($errors['err']) ? strip_tags((string) $errors['err']) : '',
            ));
        }

        return self::json(200, array(
            'ok'     => true,
            'ticket' => (int) $ticket->getId(),
            'status' => array('id' => (int) $status->getId(), 'name' => (string) $status->getLocalName(), 'state' => $state),
        ));
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
