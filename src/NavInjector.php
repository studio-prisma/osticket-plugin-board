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

    /**
     * Agent panel: the tab goes before Tasks (falling back to Tickets), in
     * the markup of include/staff/templates/navigation.tmpl.php. The tabs
     * carry their own drop-down lists, so the end of <ul id="nav"> can't be
     * found by the first </ul>; the anchor is the tab's own link instead.
     * Without either tab the page stays untouched.
     */
    public static function injectStaff(string $html, string $href, string $label, bool $active): string
    {
        if (strpos($html, self::MARKER) !== false) {
            return $html;
        }
        $nav = strpos($html, '<ul id="nav"');
        if ($nav === false) {
            return $html;
        }
        $at = false;
        foreach (array('tasks', 'tickets') as $tab) {
            if (preg_match('#<li class="[^"]*">\s*<a href="[^"]*/scp/' . $tab . '\.php"#', $html, $m, PREG_OFFSET_CAPTURE, $nav)) {
                $at = $m[0][1];
                break;
            }
        }
        if ($at === false) {
            return $html;
        }

        $item = sprintf(
            '<li class="%s no-pjax"><a class="%s no-pjax" href="%s" title="Ticket Board">%s</a>' . "\n</li>\n",
            $active ? 'active' : 'inactive',
            self::MARKER,
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );

        return substr($html, 0, $at) . $item . substr($html, $at);
    }
}
