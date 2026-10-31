<?php

declare(strict_types=1);

/**
 * Ticket Board for osTicket 1.18.x
 *
 * Hooks, all without core patches:
 *
 *   Agent panel   Application::registerStaffApp() adds the entry under
 *                 "Applications". The ajax.scp signal of scp/ajax.php receives
 *                 /board, /board/move, /board/act, /board/state, /board/panel/{id},
 *                 /board/note and /board/assets/*.
 *   Start page    scp/index.php (logo, login without a target) is buffered and
 *                 answered with a redirect to the board once the agent is
 *                 known to be fully signed in (BoardLanding).
 *   Client portal The ajax.client signal of ajax.php receives /board and
 *                 /board/assets/*. The navigation entry is placed into the
 *                 finished page by an output buffer (NavInjector), because
 *                 UserNav does not render registered client apps.
 *
 * Why the AJAX dispatchers and not apps/: in 1.18.4, apps/dispatcher.php
 * requires client.inc.php by a relative path that doesn't resolve from apps/
 * (fatal error), and scp/apps/dispatcher.php refuses direct calls and expects
 * an scp/apps.php that the release doesn't ship. scp/ajax.php and ajax.php
 * are the entry points that work on every 1.18 install, Apache or Nginx.
 *
 * @see docs/INSTALL.md
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.app.php';
require_once INCLUDE_DIR . 'class.dispatcher.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/BoardModel.php';
require_once __DIR__ . '/src/BoardRenderer.php';
require_once __DIR__ . '/src/BoardActions.php';
require_once __DIR__ . '/src/BoardI18n.php';
require_once __DIR__ . '/src/NavInjector.php';
require_once __DIR__ . '/src/BoardQuery.php';
require_once __DIR__ . '/src/BoardAssets.php';
require_once __DIR__ . '/src/BoardController.php';
require_once __DIR__ . '/src/BoardLanding.php';

class BoardPlugin extends Plugin
{
    /** Mirrors plugin.php; used for cache-busting the asset URLs only. */
    public const VERSION = '0.3.3';

    /** Route bases, relative to ROOT_PATH. */
    public const STAFF_BASE = 'scp/ajax.php/board';
    public const CLIENT_BASE = 'ajax.php/board';

    var $config_class = 'BoardConfig';

    /** @var bool */
    private static $booted = false;

    /** One board per installation; a second instance would only compete for the same routes. */
    function isMultiInstance()
    {
        return false;
    }

    public function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        $config = $this->getConfig();
        if (!$config) {
            return;
        }
        BoardController::configure(array(
            'enable_staff'    => $config->get('enable_staff'),
            'enable_client'   => $config->get('enable_client'),
            'client_nav'      => $config->get('client_nav'),
            'closed_days'     => $config->get('closed_days'),
            'max_cards'       => $config->get('max_cards'),
            'wip_limits'      => $config->get('wip_limits'),
            'refresh_seconds' => $config->get('refresh_seconds'),
            'next_after_close' => $config->get('next_after_close'),
            'staff_landing'   => $config->get('staff_landing'),
            'next_to_statuses'   => $config->get('next_to_statuses'),
            'next_from_statuses' => $config->get('next_from_statuses'),
            'next_from_actions'  => $config->get('next_from_actions'),
        ));
        $s = BoardController::settings();

        if ($s['enable_staff'] && $s['staff_landing']) {
            BoardLanding::watch(ROOT_PATH . self::STAFF_BASE);
        }

        if ($s['enable_staff']) {
            // registerStaffApp() is declared without "static" in 1.18.
            (new Application())->registerStaffApp('Board', ROOT_PATH . self::STAFF_BASE, array(
                'iconclass' => 'Ticket',
                'title'     => 'Ticket Board',
                // The agent panel loads tab links via PJAX; the board page
                // has its own script and stylesheet, so it gets a full load.
                'class'     => 'no-pjax',
            ));
            Signal::connect('ajax.scp', array(__CLASS__, 'staffRoutes'));
        }

        if ($s['enable_client']) {
            Signal::connect('ajax.client', array(__CLASS__, 'clientRoutes'));
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $info = (string) ($_SERVER['PATH_INFO'] ?? '');
            if ($s['client_nav'] && self::isPortalPage($script, $info)) {
                ob_start(array(__CLASS__, 'injectClientNav'));
            }
        }
    }

    // Handlers are closures on purpose: given array('Class', 'method'), the
    // core dispatcher instantiates the class and requires an access() method
    // (include/class.dispatcher.php, UrlMatcher::dispatch).

    public static function staffRoutes($dispatcher): void
    {
        $dispatcher->append(url_get('^/board/?$', static function () {
            return BoardController::staffPage();
        }));
        $dispatcher->append(url_post('^/board/move$', static function () {
            return BoardController::staffMove();
        }));
        $dispatcher->append(url_get('^/board/state$', static function () {
            return BoardController::staffState();
        }));
        $dispatcher->append(url_get('^/board/panel/(\d+)$', static function ($tid) {
            return BoardController::staffPanel($tid);
        }));
        $dispatcher->append(url_post('^/board/note$', static function () {
            return BoardController::staffNote();
        }));
        $dispatcher->append(url_post('^/board/act$', static function () {
            return BoardController::staffAct();
        }));
        $dispatcher->append(url_get('^/board/assets/(board\.css|board\.js)$', static function ($name) {
            return BoardController::asset((string) $name);
        }));
    }

    public static function clientRoutes($dispatcher): void
    {
        $dispatcher->append(url_get('^/board/?$', static function () {
            return BoardController::clientPage();
        }));
        $dispatcher->append(url_get('^/board/assets/(board\.css|board\.js)$', static function ($name) {
            return BoardController::asset((string) $name);
        }));
    }

    /**
     * Whether a request may render the portal chrome. The buffer holds the
     * whole response until the end, so downloads, images, AJAX and the API
     * must never get one. An attachment would otherwise sit in memory.
     */
    public static function isPortalPage(string $script, string $pathInfo = ''): bool
    {
        return PHP_SAPI !== 'cli' && self::isPortalPath($script, $pathInfo);
    }

    public static function isPortalPath(string $script, string $pathInfo = ''): bool
    {
        if ($script === '') {
            return false;
        }
        if (preg_match('#/(scp|api|setup|include)/#', $script)) {
            return false;
        }
        $base = strtolower(basename($script));
        // The board page itself is served through ajax.php; every other
        // ajax.php call stays unbuffered.
        if ($base === 'ajax.php') {
            return (bool) preg_match('#^/board/?$#', $pathInfo);
        }
        $skip = array('file.php', 'logo.php', 'avatar.php', 'captcha.php', 'logout.php');
        return !in_array($base, $skip, true);
    }

    /**
     * Output-buffer callback. Runs at the end of every request once the
     * client board is enabled, so it bails out early: only client pages,
     * only registered accounts, only HTML that carries the portal nav.
     */
    public static function injectClientNav($buffer)
    {
        if (!is_string($buffer) || !defined('OSTCLIENTINC') || strpos($buffer, '<ul id="nav"') === false) {
            return $buffer;
        }
        $client = $GLOBALS['thisclient'] ?? null;
        if (!BoardController::clientAllowed($client)) {
            return $buffer;
        }
        $active = (bool) preg_match('#^/board/?$#', (string) ($_SERVER['PATH_INFO'] ?? ''))
            && strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''))) === 'ajax.php';
        $lang = class_exists('Internationalization') ? Internationalization::getCurrentLanguage() : 'en';

        return NavInjector::inject($buffer, ROOT_PATH . self::CLIENT_BASE, BoardI18n::strings($lang)['nav'], $active);
    }
}
