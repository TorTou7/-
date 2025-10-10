<?php
/**
 * 安装器类
 *
 * @package Zibll_Ad
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Install {

    /**
     * 数据库版本
     */
    const DB_VERSION = '1.1.0';

    /**
     * 激活插件
     */
    public static function activate() {
        if (version_compare(PHP_VERSION, '7.2', '<')) {
            deactivate_plugins(plugin_basename(ZIBLL_AD_PATH . 'zibll-ad.php'));
            wp_die(
                __('子比自助广告位插件需要 PHP 7.2 或更高版本。当前版本：', 'zibll-ad') . PHP_VERSION,
                __('插件激活失败', 'zibll-ad'),
                array('back_link' => true)
            );
        }

        global $wpdb;
        $mysql_version = $wpdb->db_version();
        if (version_compare($mysql_version, '5.7', '<')) {
            deactivate_plugins(plugin_basename(ZIBLL_AD_PATH . 'zibll-ad.php'));
            wp_die(
                __('子比自助广告位插件需要 MySQL 5.7 或更高版本。当前版本：', 'zibll-ad') . $mysql_version,
                __('插件激活失败', 'zibll-ad'),
                array('back_link' => true)
            );
        }

        self::create_tables();

        update_option('zibll_ad_db_version', self::DB_VERSION);

        self::init_default_settings();

        self::schedule_cron();
    }

    /**
     * 创建数据表
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_units = $wpdb->prefix . 'zibll_ad_units';
        $sql_units = "CREATE TABLE $table_units (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slot_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
            unit_key int(11) NOT NULL DEFAULT 0,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            order_num varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'available',
            customer_name varchar(255) DEFAULT NULL,
            website_name varchar(255) DEFAULT NULL,
            website_url varchar(500) DEFAULT NULL,
            contact_type varchar(20) DEFAULT NULL,
            contact_value varchar(255) DEFAULT NULL,
            color_key varchar(50) DEFAULT NULL,
            image_id bigint(20) UNSIGNED DEFAULT NULL,
            image_url varchar(500) DEFAULT NULL,
            text_content varchar(500) DEFAULT NULL,
            target_url varchar(500) DEFAULT NULL,
            price decimal(10,2) DEFAULT 0.00,
            duration_months int(11) DEFAULT 0,
            starts_at int(11) DEFAULT NULL,
            ends_at int(11) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            pending_expires_at int(11) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_slot_status (slot_id, status),
            KEY idx_ends (ends_at),
            KEY idx_unit_key (slot_id, unit_key)
        ) $charset_collate;";

        dbDelta($sql_units);

        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $sql_orders = "CREATE TABLE $table_orders (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            unit_id bigint(20) UNSIGNED NOT NULL,
            slot_id bigint(20) UNSIGNED NOT NULL,
            zibpay_order_id bigint(20) UNSIGNED DEFAULT NULL,
            zibpay_payment_id bigint(20) UNSIGNED DEFAULT NULL,
            zibpay_order_num varchar(100) DEFAULT NULL,
            user_id bigint(20) UNSIGNED DEFAULT 0,
            customer_snapshot longtext DEFAULT NULL,
            attempt_token varchar(100) DEFAULT NULL,
            plan_type varchar(50) DEFAULT NULL,
            duration_months int(11) DEFAULT 0,
            base_price decimal(10,2) DEFAULT 0.00,
            color_price decimal(10,2) DEFAULT 0.00,
            total_price decimal(10,2) DEFAULT 0.00,
            payment_method varchar(50) DEFAULT NULL,
            pay_status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            paid_at datetime DEFAULT NULL,
            closed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_unit_id (unit_id),
            KEY idx_slot_pay (slot_id, pay_status),
            KEY idx_paid (paid_at),
            KEY idx_zibpay_order (zibpay_order_id),
            KEY idx_attempt_token (attempt_token)
        ) $charset_collate;";

        dbDelta($sql_orders);
    }

    /**
     * 按需升级数据库（在插件加载时调用）
     */
    public static function maybe_upgrade() {
        $installed = get_option('zibll_ad_db_version');
        if ($installed !== self::DB_VERSION) {
            self::create_tables();
            update_option('zibll_ad_db_version', self::DB_VERSION);
            if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
                zibll_ad_log('DB upgraded', array('from' => $installed, 'to' => self::DB_VERSION));
            }
        }
        
        self::ensure_webp_support();
    }
    
    /**
     * 确保设置中包含 webp 支持
     * 
     * 修复旧版本插件安装时默认配置不包含 webp 的问题
     */
    private static function ensure_webp_support() {
        $settings = get_option('zibll_ad_settings', array());
        
        if (empty($settings) || !is_array($settings)) {
            return;
        }
        
        $allowed_types = isset($settings['image_allowed_types']) && is_array($settings['image_allowed_types']) 
            ? $settings['image_allowed_types'] 
            : array();
        
        $allowed_types_lower = array_map('strtolower', $allowed_types);
        
        if (!in_array('webp', $allowed_types_lower, true)) {
            $allowed_types[] = 'webp';
            $settings['image_allowed_types'] = array_values(array_unique(array_map('strtolower', $allowed_types)));
            update_option('zibll_ad_settings', $settings);
            
            if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
                zibll_ad_log('WebP support added to settings', array(
                    'old_types' => $allowed_types_lower,
                    'new_types' => $settings['image_allowed_types'],
                ));
            }
        }
    }

    /**
     * 初始化默认设置
     */
    private static function init_default_settings() {
        if (get_option('zibll_ad_settings')) {
            return;
        }

        $default_settings = array(
            'allow_guest_purchase' => true,
            'default_purchase_notice' => __('请确保您的网站内容合法合规，广告内容需符合相关法律法规。支付成功后广告将自动上线，到期后自动下线。', 'zibll-ad'),
            'image_max_size' => 10240, // KB (10MB)
            'image_allowed_types' => array('jpg', 'jpeg', 'png', 'gif', 'webp'),
            'enable_expiry_notification' => true,
            'expiry_notice_days' => 7,
            'keep_data_on_uninstall' => true,
            'allow_balance_payment' => true,
        );

        update_option('zibll_ad_settings', $default_settings);
    }

    /**
     * 注册定时任务
     */
    private static function schedule_cron() {
        $timestamp = wp_next_scheduled('zibll_ad_cron_check_expire');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'zibll_ad_cron_check_expire');
        }
        $ts2 = wp_next_scheduled('zibll_ad_cron_reconcile_orders');
        if ($ts2) {
            wp_unschedule_event($ts2, 'zibll_ad_cron_reconcile_orders');
        }

        wp_schedule_event(time(), 'hourly', 'zibll_ad_cron_check_expire');
    }

    /**
     * 停用插件
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled('zibll_ad_cron_check_expire');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'zibll_ad_cron_check_expire');
        }
    }

    /**
     * 卸载插件
     */
    public static function uninstall() {
        global $wpdb;

        $settings = get_option('zibll_ad_settings', array());
        $keep_data = isset($settings['keep_data_on_uninstall']) ? $settings['keep_data_on_uninstall'] : false;

        $timestamp = wp_next_scheduled('zibll_ad_cron_check_expire');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'zibll_ad_cron_check_expire');
        }

        if ($keep_data) {
            return;
        }

        $table_units = $wpdb->prefix . 'zibll_ad_units';
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';

        $wpdb->query("DROP TABLE IF EXISTS $table_units");
        $wpdb->query("DROP TABLE IF EXISTS $table_orders");

        delete_option('zibll_ad_db_version');
        delete_option('zibll_ad_settings');

        $slots = get_posts(array(
            'post_type' => 'zibll_ad_slot',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ));

        foreach ($slots as $slot) {
            wp_delete_post($slot->ID, true);
        }

        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_zibll_ad_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_zibll_ad_%'");

        $role = get_role('administrator');
        if ($role) {
            $role->remove_cap('manage_zibll_ads');
        }
    }
}
