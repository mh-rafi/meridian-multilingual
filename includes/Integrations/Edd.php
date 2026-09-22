<?php

namespace Meridian\Multilingual\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Easy Digital Downloads, where it is present. (M6)
 *
 * Detected, never required. EDD binds behaviour to page IDs stored in
 * its own settings, so those pages are read from the settings rather
 * than listed: repointing EDD's checkout at a different page moves the
 * exclusion with it, which is what M6 asks for.
 */
final class Edd
{
    /**
     * Every EDD setting whose value is a page ID.
     *
     * Taken from EDD's own edd_get_option() call sites, not from the
     * four this site happens to have set -- a site that uses EDD's
     * login or confirmation pages needs them excluded too.
     */
    private const PAGE_OPTIONS = array(
        'purchase_page'         => 'Checkout',
        'success_page'          => 'Purchase confirmation',
        'failure_page'          => 'Transaction failed',
        'purchase_history_page' => 'Purchase history',
        'confirmation_page'     => 'Confirmation',
        'confirm_page'          => 'Confirmation',
        'login_page'            => 'Login',
        'login_redirect_page'   => 'Login redirect',
        'products_page'         => 'Products',
    );

    public static function register(): void
    {
        if (!self::available()) {
            return;
        }

        add_filter('meridian_functional_pages', array(self::class, 'pages'));
    }

    public static function available(): bool
    {
        return function_exists('edd_get_option');
    }

    /**
     * @param array $pages
     * @return array
     */
    public static function pages($pages): array
    {
        $pages = (array) $pages;

        foreach (self::PAGE_OPTIONS as $option => $label) {
            $id = (int) edd_get_option($option, 0);
            if ($id > 0 && !isset($pages[$id])) {
                $pages[$id] = sprintf(
                    /* translators: %s: the name of an Easy Digital Downloads setting, e.g. "Checkout". */
                    __('Easy Digital Downloads uses this page for %s', 'meridian'),
                    $label
                );
            }
        }

        return $pages;
    }
}
