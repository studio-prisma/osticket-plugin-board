<?php

declare(strict_types=1);

/**
 * Adds the board entry to the client portal navigation.
 *
 * osTicket 1.18 keeps a client app registry (Application::registerClientApp),
 * but UserNav never renders it, and there is no signal for the portal chrome.
 * The entry is therefore placed into the finished page, the same way other
 * plugins inject into the portal without patching core templates.
 *
 * The match is deliberately narrow: the opening tag of <ul id="nav"> as
 * written by include/client/header.inc.php, and the first </ul> after it.
 * Anything else leaves the page untouched.
 */
final class NavInjector
{
    public const MARKER = 'spb-nav';

    public static function inject(string $html, string $href, string $label, bool $active): string
    {
        if (strpos($html, self::MARKER) !== false) {
            return $html;
        }
        if (!preg_match('/<ul\s+id="nav"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $end = strpos($html, '</ul>', $start);
        if ($end === false) {
            return $html;
        }

        $item = sprintf(
            '<li><a class="%s%s" href="%s">%s</a></li>' . "\n",
            self::MARKER,
            $active ? ' active' : '',
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );

        return substr($html, 0, $end) . $item . substr($html, $end);
    }
}
