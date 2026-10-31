<?php

declare(strict_types=1);

/**
 * Serves the board's CSS and JavaScript through the plugin's own route.
 *
 * include/ is "Deny from all" on Apache, so files under include/plugins/
 * cannot be linked directly. The route maps a fixed name to a fixed file;
 * nothing from the request is used to build a path.
 */
final class BoardAssets
{
    private const FILES = array(
        'board.css' => 'text/css; charset=UTF-8',
        'board.js'  => 'application/javascript; charset=UTF-8',
    );

    public static function version(): string
    {
        $mtime = 0;
        foreach (array_keys(self::FILES) as $name) {
            $mtime = max($mtime, (int) @filemtime(self::path($name)));
        }
        return substr(sha1(BoardPlugin::VERSION . ':' . $mtime), 0, 10);
    }

    public static function serve(string $name): string
    {
        if (!isset(self::FILES[$name]) || !is_file(self::path($name))) {
            Http::response(404, 'Not found');
            exit;
        }
        header('Content-Type: ' . self::FILES[$name]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        return (string) file_get_contents(self::path($name));
    }

    private static function path(string $name): string
    {
        return dirname(__DIR__) . '/assets/' . $name;
    }
}
