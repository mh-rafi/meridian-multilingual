<?php

namespace Meridian\Multilingual\Routing;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which language this request is in. (M2)
 *
 * Decided by the URL and nothing else -- never a cookie, never
 * Accept-Language, never an automatic redirect (spec 2). Production runs
 * a full-page cache that varies by URL; a language decided by anything
 * the URL does not carry is how a cached Spanish page gets served to an
 * English visitor, and the failure is invisible to whoever deployed it.
 */
final class Request
{
    private static ?string $code = null;

    /**
     * The current language code.
     */
    public static function code(): string
    {
        if (null !== self::$code) {
            return self::$code;
        }

        // Assigned before the filter runs: resolving the language calls
        // home_url(), which is filtered with the current language, which
        // would resolve the language again. The default is the safe
        // value to be re-entered with.
        self::$code = Registry::default_code();

        $detected = self::detect();

        /**
         * Filter the language this request is served in.
         *
         * @param string $detected Language code.
         */
        $code = (string) apply_filters('meridian_current_language', $detected);

        return self::$code = Registry::exists($code) ? $code : Registry::default_code();
    }

    public static function is_default(): bool
    {
        return Registry::is_default(self::code());
    }

    /**
     * The prefix the current language adds to a URL: '' or 'es'.
     */
    public static function prefix(): string
    {
        return Registry::prefix(self::code());
    }

    private static function detect(): string
    {
        $default = Registry::default_code();

        if (!Registry::is_multilingual()) {
            return $default;
        }

        // The admin, cron, WP-CLI and the REST API are not the public
        // site and have no language prefix to read. They get the default
        // until something sets one explicitly -- M4's editor will, for
        // the post being edited, through the filter above.
        if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return $default;
        }

        // The query var, once WordPress has parsed the request.
        $queried = get_query_var('lang');
        if ($queried && Registry::exists((string) $queried)) {
            return (string) $queried;
        }

        // Before parse_request, and for anything that builds a URL that
        // early, read the path directly. Without this the header of a
        // Spanish page can be generated in English simply because it was
        // rendered before the query var existed.
        return self::from_path($default);
    }

    /**
     * The language named by the first path segment, or the default.
     */
    private static function from_path(string $default): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ('' === $uri) {
            return $default;
        }

        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $base = self::base_path();

        if ('/' !== $base && 0 === strpos($path, $base)) {
            $path = substr($path, strlen($base) - 1);
        }

        $segment = strtok(ltrim($path, '/'), '/');
        if (false === $segment || '' === $segment) {
            return $default;
        }

        $segment = strtolower($segment);

        // Only a non-default language has a prefix, so the default's own
        // code appearing in a URL is a path, not a language.
        return ($segment !== $default && Registry::exists($segment)) ? $segment : $default;
    }

    /**
     * The site's base path, e.g. '/' or '/shop/'.
     *
     * Read from the raw option rather than home_url(), which this class
     * is in the middle of teaching about languages.
     */
    public static function base_path(): string
    {
        static $base = null;
        if (null !== $base) {
            return $base;
        }

        $path = (string) wp_parse_url((string) get_option('home'), PHP_URL_PATH);
        $path = trim($path, '/');

        return $base = '' === $path ? '/' : '/' . $path . '/';
    }

    /**
     * Forget the resolved language. For tests; a request has one.
     */
    public static function flush(): void
    {
        self::$code = null;
    }
}
