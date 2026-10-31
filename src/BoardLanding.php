<?php

declare(strict_types=1);

/**
 * Board as the agent's start page after login.
 *
 * scp/login.php fixes the redirect target before the login runs, so the
 * plugin can't change it. Instead it marks the session when a login ends
 * on the default start page (scp/index.php, which shows the ticket list),
 * and the first load of that page afterwards goes to the board. A login
 * that came from a specific link, e.g. a ticket in an alert mail, keeps
 * its target. The mark is used once: the Tickets tab works as before.
 */
final class BoardLanding
{
    public const KEY = '_spb_landing';

    /** The mark expires if the agent never reaches the start page. */
    public const TTL = 600;

    /**
     * Same rule as scp/login.php: no target, or the login or AJAX script,
     * means the default page. The panel root and index.php count as default
     * too: that's where an expired session sends someone who opened /scp/.
     */
    public static function isDefaultTarget(?string $dest, string $root): bool
    {
        if ($dest === null || $dest === '' || strpos($dest, 'login.php') !== false || strpos($dest, 'ajax.php') !== false) {
            return true;
        }
        $base = rtrim($root, '/') . '/scp/';
        return in_array($dest, array('index.php', $base, $base . 'index.php'), true);
    }

    /** Whether the request is the agent panel's start page. */
    public static function isStartPage(string $script, string $method): bool
    {
        return $method === 'GET' && (bool) preg_match('#/scp/index\.php$#', $script);
    }

    /** Whether another agent panel page is loaded, which uses up the mark. */
    public static function isOtherPage(string $script): bool
    {
        return (bool) preg_match('#/scp/(?!index\.php$|login\.php$|ajax\.php$)[^/]+\.php$#', $script);
    }

    /** Signal auth.login.succeeded. */
    public static function onLogin($staff): void
    {
        if (!($staff instanceof Staff)) {
            return;
        }
        $dest = $_SESSION['_staff']['auth']['dest'] ?? null;
        if (self::isDefaultTarget(is_string($dest) ? $dest : null, ROOT_PATH)) {
            $_SESSION[self::KEY] = time();
        } else {
            unset($_SESSION[self::KEY]);
        }
    }

    /**
     * Whether the session holds a signed-in agent past the 2FA step. Reads
     * only the session: plugins bootstrap before osTicket has loaded its
     * config, so looking up the agent here would fail.
     */
    public static function signedIn(array $session): bool
    {
        $auth = $session['_auth']['staff'] ?? null;
        return is_array($auth) && !empty($auth['id']) && !isset($auth['2fa']);
    }

    /**
     * Called from bootstrap on every request while the mark is set. Sends a
     * signed-in agent from the start page to the board, once. A forced
     * password change or 2FA setup is left to the board page, which sends
     * such an agent back to the start page (BoardController::staffPage);
     * the mark is gone by then, so that doesn't loop.
     */
    public static function maybeRedirect(string $target): void
    {
        $mark = $_SESSION[self::KEY] ?? null;
        if (!is_int($mark)) {
            return;
        }
        if (time() - $mark > self::TTL) {
            unset($_SESSION[self::KEY]);
            return;
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $start = self::isStartPage($script, $method);
        if (!$start && !self::isOtherPage($script)) {
            return; // login.php (2FA), ajax.php, the portal: wait
        }
        if (!self::signedIn($_SESSION)) {
            return;
        }
        unset($_SESSION[self::KEY]);
        if ($start) {
            Http::redirect($target);
            exit;
        }
    }
}
