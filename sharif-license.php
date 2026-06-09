<?php
/**
 * Plugin Name: sharifLicense
 * Plugin URI:  https://sharifdev.ir
 * Description: A flexible system for managing and authenticating licenses in WordPress. You can create licenses for any WHMCS software, plugin, theme or module and validate them based on domain, IP address and expiration date via a secure endpoint. All sensitive information is stored encrypted (AES-256).
 * Version:     1.0.0
 * Author:      Amir Hossein Sharifnezhad
 * Author URI:  https://sharifdev.ir
 * Text Domain: sharif-license
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SHARIF_LICENSE_VERSION', '1.0.0');
define('SHARIF_LICENSE_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/Classes/Database.php';
require_once __DIR__ . '/Classes/RestApi.php';
require_once __DIR__ . '/Classes/Admin.php';

register_activation_hook(__FILE__, [Database::class, 'createTable']);
register_deactivation_hook(__FILE__, [Database::class, 'flushRewriteRules']);

add_action('plugins_loaded', function () {
    new RestApi();
    new Admin();
});
