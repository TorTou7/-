<?php
/**
 * Plugin Name: 子比自助广告位
 * Description: 子比主题自助广告位插件,支持图片和文字广告位的自助购买与管理。
 * Version: 0.1.5
 * Author: 人民的骆驼
 * Author URI: https://www.8uid.com/
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Text Domain: zibll-ad
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ZIBLL_AD_VERSION', '0.1.5');
define('ZIBLL_AD_PATH', plugin_dir_path(__FILE__));
define('ZIBLL_AD_URL', plugin_dir_url(__FILE__));
if (!defined('ZIBLL_AD_DEV_MODE')) {
    define('ZIBLL_AD_DEV_MODE', false);
}

if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
    if (!function_exists('zibll_ad_log')) {
        require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
    }
    zibll_ad_log('Plugin main file loaded', array(
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
        'script' => isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '',
    ));

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($uri && strpos($uri, '/zibpay/shop/') !== false) {
        zibll_ad_log('Detected ZibPay shop request', array(
            'uri' => $uri,
            'get' => $_GET,
        ));
    }
}

require_once ZIBLL_AD_PATH . 'includes/compat.php';

require_once ZIBLL_AD_PATH . 'includes/helpers.php';

require_once ZIBLL_AD_PATH . 'includes/class-install.php';

require_once ZIBLL_AD_PATH . 'includes/class-order-sync.php';

require_once ZIBLL_AD_PATH . 'includes/class-notify.php';


register_activation_hook(__FILE__, 'zibll_ad_activate');
function zibll_ad_activate() {
    require_once ZIBLL_AD_PATH . 'includes/class-install.php';
    Zibll_Ad_Install::activate();
}


register_deactivation_hook(__FILE__, 'zibll_ad_deactivate');
function zibll_ad_deactivate() {
    require_once ZIBLL_AD_PATH . 'includes/class-install.php';
    Zibll_Ad_Install::deactivate();
}

register_uninstall_hook(__FILE__, 'zibll_ad_uninstall');
function zibll_ad_uninstall() {
    require_once ZIBLL_AD_PATH . 'includes/class-install.php';
    Zibll_Ad_Install::uninstall();
}

require_once ZIBLL_AD_PATH . 'includes/class-plugin.php';

Zibll_Ad_Plugin::instance()->init();
