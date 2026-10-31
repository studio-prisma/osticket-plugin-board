<?php

declare(strict_types=1);

/**
 * Board as the agent panel's start page.
 *
 * scp/index.php is the panel's start page: the logo, the "Agent Panel" link
 * and a login without a specific target all lead there, and it shows the
 * ticket list. The Tickets tab links to tickets.php instead, so taking over
 * index.php leaves the ticket list one click away.
 *
 * Who the agent is, whether the session is still valid and whether a
 * password change or 2FA setup is pending is only known once
 * scp/staff.inc.php has run, well after plugins bootstrap. So the plugin
 * buffers the start page and decides at the end of the request: a fully
 * signed-in agent gets a redirect to the board, anything else (login page,
 * password change, 2FA, upgrade) gets osTicket's own response unchanged.
 * The ticket list is rendered once and discarded in that case; scp/index.php
 * has no hook that runs between the agent check and the page output.
 */
final class BoardLanding
{
    /** @var string */
    private static $target = '';

    /** Whether the request is the agent panel's start page, without parameters. */
    public static function isStartPage(string $script, string $method, string $query): bool
    {
        return $method === 'GET' && $query === '' && (bool) preg_match('#/scp/index\.php$#', $script);
    }

    /**
     * Whether the finished response may be replaced by the redirect: no
     * redirect of osTicket's own, headers still open.
     *
     * @param string[] $headers  headers_list()
     */
    public static function mayRedirect(array $headers, bool $sent, int $code): bool
    {
        if ($sent || $code !== 200) {
            return false;
        }
        foreach ($headers as $h) {
            if (stripos($h, 'location:') === 0) {
                return false;
            }
        }
        return true;
    }

    /** Called from bootstrap. */
    public static function watch(string $target): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if (!self::isStartPage($script, $method, $query)) {
            return;
        }
        self::$target = $target;
        ob_start(array(__CLASS__, 'finish'));
    }

    /** Output-buffer callback, runs once the start page is complete. */
    public static function finish($buffer)
    {
        $staff = $GLOBALS['thisstaff'] ?? null;
        if (!($staff instanceof Staff) || !$staff->getId() || !$staff->isValid()
                || BoardController::staffGated($staff)) {
            return $buffer;
        }
        $code = http_response_code();
        if (!self::mayRedirect(headers_list(), headers_sent(), is_int($code) ? $code : 200)) {
            return $buffer;
        }
        header('Location: ' . self::$target, true, 302);
        return '';
    }
}
