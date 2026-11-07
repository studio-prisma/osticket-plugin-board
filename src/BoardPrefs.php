<?php

declare(strict_types=1);

/**
 * Per-agent board preferences: the last team filter and named views.
 *
 * Stored in osTicket's own config table under the namespace
 * "studio-prisma.board.staff.<staff id>", key "prefs", as JSON. No schema of
 * its own, nothing to migrate. The staff ID always comes from the session
 * (the caller passes $thisstaff), never from the request.
 *
 * Everything that turns raw input or stored JSON into values is pure and
 * public, for the unit tests. Only load() and save() touch osTicket.
 */
final class BoardPrefs
{
    /** Saved views per agent. */
    public const VIEW_MAX = 20;

    /** Characters in a view name. */
    public const NAME_MAX = 60;

    /** Teams in one selection. */
    public const TEAM_MAX = 50;

    /** "Without team" in a team selection. */
    public const NO_TEAM = 0;

    private const NAMESPACE_PREFIX = 'studio-prisma.board.staff.';
    private const KEY = 'prefs';

    /** @return array{teams:int[], views:array} */
    public static function load(int $staffId): array
    {
        $config = self::config($staffId);
        return self::decode($config ? $config->get(self::KEY) : null);
    }

    public static function save(int $staffId, array $prefs): bool
    {
        $config = self::config($staffId);
        if (!$config) {
            return false;
        }
        $clean = self::decode($prefs);
        return (bool) $config->set(self::KEY, (string) json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    private static function config(int $staffId)
    {
        return $staffId > 0 && class_exists('Config') ? new Config(self::NAMESPACE_PREFIX . $staffId) : null;
    }

    /**
     * Stored JSON (or an array) to clean preferences. Anything unexpected is
     * dropped, so a broken row never breaks the board.
     *
     * @param mixed $raw
     * @return array{teams:int[], views:array}
     */
    public static function decode($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            $raw = array();
        }
        $views = array();
        foreach (is_array($raw['views'] ?? null) ? $raw['views'] : array() as $v) {
            $view = is_array($v) ? self::view($v) : null;
            if ($view && count($views) < self::VIEW_MAX) {
                $views[] = $view;
            }
        }
        return array(
            'teams' => self::teamIds($raw['teams'] ?? array()),
            'views' => $views,
        );
    }

    /** One stored view, or null if it has no usable name. */
    private static function view(array $v): ?array
    {
        $name = self::viewName($v['name'] ?? '');
        if ($name === null) {
            return null;
        }
        $queue = $v['queue'] ?? 0;
        $sort = $v['sort'] ?? '';
        $group = $v['group'] ?? '';
        return array(
            'name'  => $name,
            'queue' => is_int($queue) || (is_string($queue) && $queue !== '' && strlen($queue) <= 10 && ctype_digit($queue))
                ? max(0, (int) $queue) : 0,
            'teams' => self::teamIds($v['teams'] ?? array()),
            'sort'  => in_array($sort, BoardModel::SORTS, true) ? $sort : BoardModel::SORTS[0],
            'group' => in_array($group, BoardModel::GROUPS, true) ? $group : BoardModel::GROUPS[0],
        );
    }

    /**
     * A view name as typed: trimmed, inner whitespace collapsed, valid UTF-8,
     * no control characters, 1 to NAME_MAX characters. Null otherwise.
     *
     * @param mixed $raw
     */
    public static function viewName($raw): ?string
    {
        if (!is_string($raw) || !preg_match('//u', $raw)) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return null;
        }
        return preg_match_all('/./su', $name) <= self::NAME_MAX ? $name : null;
    }

    /**
     * Team IDs from input or storage: non-negative integers, unique, sorted,
     * at most TEAM_MAX. 0 means "without team". Whether a team exists is
     * checked against the team list by the caller (selectedTeams()).
     *
     * @param mixed $raw
     * @return int[]
     */
    public static function teamIds($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $v) {
            if (is_int($v) && $v >= 0) {
                $out[$v] = true;
            } elseif (is_string($v) && $v !== '' && strlen($v) <= 10 && ctype_digit($v)) {
                $out[(int) $v] = true;
            }
        }
        $ids = array_keys($out);
        sort($ids);
        return array_slice($ids, 0, self::TEAM_MAX);
    }

    /**
     * The team filter for this request. With team_set in the query the
     * submitted checkboxes win (none checked = all teams); without it the
     * stored selection applies. Teams that don't exist (any more) fall out.
     *
     * @param int[] $stored
     * @param array<int, string> $teams  id => name, from BoardQuery::teamOptions()
     * @return array{0:int[], 1:bool}  selection, and whether it came from the request
     */
    public static function selectedTeams(array $get, array $stored, array $teams): array
    {
        $fromRequest = array_key_exists('team_set', $get);
        $ids = $fromRequest ? self::teamIds($get['team'] ?? array()) : $stored;
        $ids = array_values(array_filter($ids, static function ($id) use ($teams) {
            return $id === self::NO_TEAM || isset($teams[$id]);
        }));
        return array($ids, $fromRequest);
    }

    /**
     * ?queue=v<n> stands for saved view n: it is replaced by the view's
     * queue, teams, sort and group, as if the agent had picked them one by
     * one. Any other query passes through unchanged.
     */
    public static function resolveView(array $get, array $views): array
    {
        $raw = is_string($get['queue'] ?? null) ? $get['queue'] : '';
        if (!preg_match('/^v(\d{1,3})$/', $raw, $m)) {
            return $get;
        }
        $view = $views[(int) $m[1]] ?? null;
        if (!$view) {
            // Gone (deleted in another tab): back to the default view.
            return array_merge($get, array('queue' => 'all'));
        }
        return array_merge($get, array(
            'queue'    => $view['queue'] > 0 ? (string) $view['queue'] : 'all',
            'team_set' => '1',
            'team'     => array_map('strval', $view['teams']),
            'sort'     => $view['sort'],
            'group'    => $view['group'],
        ));
    }

    /**
     * Index of the saved view that matches what the board shows, or -1.
     *
     * @param array{queue:int, teams:int[], sort:string, group:string} $state
     */
    public static function matchView(array $views, array $state): int
    {
        foreach ($views as $i => $v) {
            if ($v['queue'] === $state['queue'] && $v['teams'] === $state['teams']
                && $v['sort'] === $state['sort'] && $v['group'] === $state['group']) {
                return (int) $i;
            }
        }
        return -1;
    }

    /**
     * Adds or replaces (same name, case-insensitive) a view. Null if the
     * name is invalid or the list is full.
     */
    public static function withView(array $prefs, string $rawName, array $state): ?array
    {
        $name = self::viewName($rawName);
        if ($name === null) {
            return null;
        }
        $view = self::view(array_merge($state, array('name' => $name)));
        foreach ($prefs['views'] as $i => $v) {
            if (self::lower($v['name']) === self::lower($name)) {
                $prefs['views'][$i] = $view;
                return $prefs;
            }
        }
        if (count($prefs['views']) >= self::VIEW_MAX) {
            return null;
        }
        $prefs['views'][] = $view;
        return $prefs;
    }

    public static function withoutView(array $prefs, int $index): array
    {
        if (isset($prefs['views'][$index])) {
            array_splice($prefs['views'], $index, 1);
        }
        return $prefs;
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
