<?php

declare(strict_types=1);

/**
 * Plugin Name: Reconcile
 * Description: Import/Export of member, group and position data from spreadsheets using Unity framework.
 * Version: 1.12.2
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: sentinel, scrutiny
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/integrity
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$reconcile_plugin_data = get_plugin_data(__FILE__, false, false);
define('RECONCILE_VERSION', $reconcile_plugin_data['Version']);
define('RECONCILE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RECONCILE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for Reconcile namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Reconcile\\';
        $base_dir = RECONCILE_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('reconcile')->error('Reconcile Autoloader Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reconcile Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('reconcile')->critical('Reconcile Autoloader Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reconcile Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Register admin menus early — does not depend on Unity's container.
// This runs at plugin-load time, well before the admin_menu hook fires.
if (is_admin()) {
    \Reconcile\Plugin::registerMenus();
}

// Initialize AJAX handlers after Unity is fully loaded (container available)
add_action('unity/loaded', function ($container) {
    try {
        if (!class_exists('Reconcile\Plugin')) {
            function_exists('wp_log')
                ? wp_log('reconcile')->error('Reconcile: Plugin class not found — aborting init.')
                : error_log('Reconcile: Plugin class not found — aborting init.');
            throw new \Exception('Reconcile\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        if (!\Reconcile\Plugin::unityIsAvailable()) {
            function_exists('wp_log')
                ? wp_log('reconcile')->error('Reconcile: unityIsAvailable() returned false — Unity core classes not found. Aborting init.')
                : error_log('Reconcile: unityIsAvailable() returned false — Unity core classes not found. Aborting init.');
            return;
        }

        \Reconcile\Plugin::init($container);

        /**
         * Fires after Reconcile is fully loaded.
         */
        do_action('reconcile_loaded');

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('reconcile')->error('Reconcile Plugin Initialization Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reconcile Plugin Initialization Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                $message = sprintf(
                    '<strong>Reconcile Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('reconcile')->critical('Reconcile Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reconcile Plugin Fatal Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Reconcile Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }
    }
});

// Safety net: if unity/loaded never fired by the time WordPress is ready to
// serve an admin page or AJAX request, log a diagnostic message.
add_action('admin_init', function () {
    if (\Reconcile\Plugin::getContainer() === null) {
        function_exists('wp_log')
            ? wp_log('reconcile')->error('Container is still null at admin_init', [
                'unity_hook_fired' => did_action('unity/loaded'),
                'unity_class_exists' => class_exists('Unity\\Plugin'),
            ])
            : error_log('Reconcile: Container is still null at admin_init. '
                . 'did_action(unity/loaded): ' . did_action('unity/loaded') . '.');
    }
}, 999);

// Show admin notice if Unity is not available
add_action('plugins_loaded', function () {
    if (!class_exists('Unity\\Plugin')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Reconcile', 'reconcile') . ':</strong> ';
            echo esc_html__('This plugin requires the Unity plugin to be installed and activated.', 'reconcile');
            echo '</p></div>';
        });
    }
}, 20);

// Plugin activation hook
register_activation_hook(__FILE__, function () {
    if (!class_exists('Unity\\Plugin')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Reconcile requires the Unity plugin to be installed and activated.', 'reconcile'),
            esc_html__('Plugin Activation Error', 'reconcile'),
            ['back_link' => true]
        );
    }
});

// Plugin deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Cleanup code here if needed
});
