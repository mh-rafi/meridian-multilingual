<?php

namespace Meridian\Multilingual\Translations;

use Meridian\Multilingual\Languages\Registry;
use Meridian\Multilingual\Routing\Request;
use WP_Post;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Serving a single-source post's translated title, body and excerpt. (M5)
 *
 * One post row, translated values in meridian_fields keyed by the
 * source ID. The cart never carries a translation ID because there is
 * no translation ID: every commerce operation resolves to the row these
 * values hang off.
 */
final class Content
{
    const TITLE = 'title';
    const CONTENT = 'content';
    const EXCERPT = 'excerpt';

    public static function register(): void
    {
        add_filter('the_title', array(self::class, 'title'), 10, 2);
        add_filter('the_content', array(self::class, 'content'), 9);
        add_filter('get_the_excerpt', array(self::class, 'excerpt'), 10, 2);
    }

    /**
     * @param string $title
     * @param mixed  $post_id
     */
    public static function title($title, $post_id = 0): string
    {
        $translated = self::value((int) $post_id, self::TITLE);

        return null === $translated ? (string) $title : $translated;
    }

    /**
     * Before wpautop and the shortcode pass, like the stored content.
     *
     * Priority 9 so a translated body goes through exactly the same
     * filters at the same points as the one it replaces -- a
     * translation that skipped shortcode expansion would render its
     * buy button as literal text.
     *
     * @param string $content
     */
    public static function content($content): string
    {
        $post = get_post();
        if (!$post instanceof WP_Post) {
            return (string) $content;
        }

        $translated = self::value((int) $post->ID, self::CONTENT);

        return null === $translated ? (string) $content : $translated;
    }

    /**
     * @param string       $excerpt
     * @param WP_Post|null $post
     */
    public static function excerpt($excerpt, $post = null): string
    {
        $post_id = $post instanceof WP_Post ? (int) $post->ID : (int) get_the_ID();
        $translated = self::value($post_id, self::EXCERPT);

        return null === $translated ? (string) $excerpt : $translated;
    }

    /**
     * The current language's value for a single-source post, or null.
     */
    public static function value(int $post_id, string $field): ?string
    {
        if (is_admin() || $post_id < 1 || !Registry::is_multilingual()) {
            return null;
        }

        $lang = Request::code();
        if (Registry::is_default($lang)) {
            return null;
        }

        if (!Modes::is_single_source((string) get_post_type($post_id))) {
            // A post translated by duplication has its own row, and its
            // own title and content came out of the database already.
            return null;
        }

        $value = Fields::get($post_id, $lang, $field);

        return (null === $value || '' === $value) ? null : $value;
    }
}
