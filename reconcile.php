<?php

declare(strict_types=1);

/**
 * Plugin Name: Reconcile
 * Description: Import and reconciliation of member data from spreadsheets using Unity framework.
 * Version: 1.4.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified) (Modified)
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
define('RECONCILE_PATH', plugin_dir_path(__FILE__));
define('RECONCILE_URL', plugin_dir_url(__FILE__));

// Autoloader for Reconcile namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Reconcile\\';
        $base_dir = RECONCILE_PATH . 'src/';

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
        error_log('Reconcile Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('Reconcile Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Initialize the plugin after Unity is fully loaded
add_action('unity_loaded', function ($container) {
    try {
        if (!class_exists('Reconcile\Plugin')) {
            throw new \Exception('Reconcile\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        if (!\Reconcile\Plugin::unityIsAvailable()) {
            return;
        }

        \Reconcile\Plugin::init($container);

        /**
         * Fires after Reconcile is fully loaded.
         */
        do_action('reconcile_loaded');

    } catch (\Exception $e) {
        error_log('Reconcile Plugin Initialization Error: ' . $e->getMessage());
        error_log('Reconcile Plugin Stack Trace: ' . $e->getTraceAsString());

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
        error_log('Reconcile Plugin Fatal Error: ' . $e->getMessage());
        error_log('Reconcile Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Reconcile Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }
    }
});

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
