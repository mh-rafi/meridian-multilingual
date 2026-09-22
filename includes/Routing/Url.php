<?php

namespace Meridian\Multilingual\Routing;

use Meridian\Multilingual\Languages\Registry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adding and removing a language prefix on this site's own URLs.
 *
 * Kept apart from the filters that call it because the string handling
 * is where this goes wrong quietly: a prefix inserted into a query
 * string, or added twice, produces a URL that still looks plausible.
 */
final class Url
{
    /**
     * Whether a URL belongs to this site.
     *
     * Compared on host and base path rather than on the whole home URL,
     * so that a scheme difference (an http link on an https site, or the
     * other way round behind a proxy) does not make this site's own URL
     * look external and go unprefixed.
     */
    public static function is_internal(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!$parts) {
            return false;
        }

        // Protocol-relative and root-relative URLs are ours.
        if (!empty($parts['host'])) {
            $home_host = (string) wp_parse_url((string) get_option('home'), PHP_URL_HOST);
            if (strtolower($parts['host']) !== strtolower($home_host)) {
                return false;
            }
        }

        $path = $parts['path'] ?? '/';
        $base = Request::base_path();

        return '/' === $base || 0 === strpos($path, $base);
    }

    /**
     * Paths that are files or endpoints rather than content.
     *
     * These have no language: /es/wp-json/ is not the REST API in
     * Spanish, it is a 404. Checked on the path so that a page whose
     * slug merely contains one of these words is unaffected.
     */
    public static function is_untranslatable_path(string $path): bool
    {
        $path = ltrim((string) $path, '/');
        $base = ltrim(Request::base_path(), '/');
        if ('' !== $base && 0 === strpos($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $prefixes = array('wp-json/', 'wp-admin/', 'wp-content/', 'wp-includes/', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php', 'wp-signup.php');

        /**
         * Filter the paths a language prefix is never added to.
         *
         * This is where a path owned by something else on the same
         * domain goes. WordPress cannot detect a sibling installation
         * in a subdirectory -- from inside, /blog is simply a path that
         * routes nowhere -- so it has to be declared. On this project
         * that is the blog install, which owns /blog and adds its own
         * prefix inside it, giving /blog/es/slug (spec 8). Without the
         * declaration the menu's "Blog" link becomes /es/blog, which
         * belongs to neither installation.
         *
         * Matched as path prefixes, relative to the site root.
         *
         * @param string[] $prefixes
         */
        $prefixes = (array) apply_filters('meridian_untranslatable_paths', $prefixes);

        foreach ($prefixes as $prefix) {
            $prefix = ltrim((string) $prefix, '/');
            if ('' !== $prefix && 0 === strpos($path, $prefix)) {
                return true;
            }
        }

        // Sitemaps, core's and Sightline's, are one set for the whole
        // site; their per-language children are Sightline's (spec 7).
        return (bool) preg_match('#(^|/)[^/]*sitemap[^/]*\.(xml|xsl)$#i', $path);
    }

    /**
     * Whether a URL already carries a language prefix.
     */
    public static function prefix_of(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $base = Request::base_path();

        if ('/' !== $base && 0 === strpos($path, $base)) {
            $path = '/' . substr($path, strlen($base));
        }

        $segment = strtolower((string) strtok(ltrim($path, '/'), '/'));
        if ('' === $segment) {
            return '';
        }

        // The segment must be a configured, non-default language.
        // Registry::prefix() alone is not that test: it answers "what
        // prefix would this code have", and for any string that is not
        // the default language -- 'downloads', 'checkout' -- it answers
        // with the string itself. Trusting it here read the first path
        // segment of every URL as a language and ate it, turning
        // /downloads/foo/ into /es/foo/.
        return (Registry::exists($segment) && !Registry::is_default($segment)) ? $segment : '';
    }

    /**
     * Put $code's prefix on a URL, or take any prefix off when $code is
     * the default language.
     */
    public static function set_language(string $url, string $code): string
    {
        if (!self::is_internal($url)) {
            return $url;
        }

        $parts = wp_parse_url($url);
        $path = $parts['path'] ?? '/';

        if (self::is_untranslatable_path($path)) {
            return $url;
        }

        $base = Request::base_path();
        $rest = ('/' !== $base && 0 === strpos($path, $base)) ? substr($path, strlen($base)) : ltrim($path, '/');

        // Take off whatever prefix is there, then put on the one asked
        // for. Doing it in that order is what makes this safe to call
        // twice, and what lets one method serve both directions.
        $current = self::prefix_of($url);
        if ('' !== $current) {
            $rest = (string) substr($rest, strlen($current));
            $rest = ltrim($rest, '/');
        }

        $prefix = Registry::prefix($code);
        $new_path = $base . ('' === $prefix ? '' : $prefix . '/') . $rest;

        return self::rebuild($parts, $new_path);
    }

    /**
     * Reassemble a URL with a different path, leaving everything else
     * exactly as it was.
     *
     * A str_replace of the old path would also hit an identical string
     * in the query or the fragment, which is the kind of bug that only
     * shows up on the one URL where it happens.
     */
    private static function rebuild(array $parts, string $path): string
    {
        $url = '';

        if (!empty($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        } elseif (!empty($parts['host'])) {
            $url .= '//';
        }

        if (!empty($parts['user'])) {
            $url .= $parts['user'];
            if (!empty($parts['pass'])) {
                $url .= ':' . $parts['pass'];
            }
            $url .= '@';
        }

        if (!empty($parts['host'])) {
            $url .= $parts['host'];
        }
        if (!empty($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= $path;

        if (isset($parts['query']) && '' !== $parts['query']) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && '' !== $parts['fragment']) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
