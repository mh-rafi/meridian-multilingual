<?php

/**
 * Plugin Name: Meridian
 * Description: Multilingual content with directory-prefixed URLs, correct hreflang, and a storage model that does not duplicate a product a customer bought once.
 * Plugin URI: https://ui-lib.com/
 * Author: MH Rafi
 * Author URI: https://ui-lib.com/
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meridian
 * Domain Path: /languages
 *
 * See MULTILINGUAL-PLUGIN-SPEC.md. Requirement IDs (M0, M1, ...) in this
 * plugin's comments refer to section 6 of that document.
 *
 * Built to be published, so nothing here may depend on this store. Easy
 * Digital Downloads and Sightline SEO are detected and added to, never
 * required.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MERIDIAN_VERSION', '0.1.0');
define('MERIDIAN_FILE', __FILE__);
define('MERIDIAN_PATH', plugin_dir_path(__FILE__));
define('MERIDIAN_URL', plugin_dir_url(__FILE__));

/**
 * PSR-4 autoloader for this plugin's own namespace.
 *
 * Deliberately not Composer: this plugin has no third-party dependency,
 * and a vendor directory that exists only to map one namespace is a
 * build step and a shipped tree for nothing.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Meridian\\Multilingual\\';
    if (0 !== strpos($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = MERIDIAN_PATH . 'includes/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($path)) {
        require_once $path;
    }
});

/**
 * On 'init' rather than 'plugins_loaded': since WordPress 6.7 a textdomain
 * loaded before 'init' raises a _doing_it_wrong notice.
 */
add_action('init', 'meridian_load_textdomain');
function meridian_load_textdomain()
{
    load_plugin_textdomain('meridian', false, dirname(plugin_basename(MERIDIAN_FILE)) . '/languages');
}

register_activation_hook(__FILE__, array('Meridian\\Multilingual\\Plugin', 'activate'));

add_action('plugins_loaded', 'meridian');
function meridian()
{
    return \Meridian\Multilingual\Plugin::instance();
}
