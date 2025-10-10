<?php
/**
 * 订单对账与补录（仅插件内逻辑，不改主题）
 *
 * 背景：部分站点由于主题 notify/return 路径无法正确引导 WordPress，导致支付成功回调没有触达插件。
 * 本类通过定时扫描主题订单表（zibpay_order），将已支付且属于广告订单（order_type=31）的记录补录到插件订单表。
 *
 * @package Zibll_Ad
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Order_Reconcile
{
    /**
     * 注册计划任务与钩子
     */
    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('init', array(__CLASS__, 'maybe_schedule'));
        add_action('admin_init', array(__CLASS__, 'maybe_run_lightweight')); // 后台访问时轻量尝试一次，限流
        add_action('zibll_ad_cron_reconcile_orders', array(__CLASS__, 'run')); // 定时任务
    }

    /**
     * 增加每5分钟的计划任务
     */
    public static function cron_schedules($schedules) {
        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = array(
                'interval' => 5 * 60,
                'display'  => __('Every Five Minutes', 'zibll-ad'),
            );
        }
        return $schedules;
    }

    /**
     * 如未注册则注册计划任务
     */
    public static function maybe_schedule() {
        if (!wp_next_scheduled('zibll_ad_cron_reconcile_orders')) {
            wp_schedule_event(time() + 60, 'every_five_minutes', 'zibll_ad_cron_reconcile_orders');
            if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
                zibll_ad_log('Reconcile cron scheduled');
            }
        }
    }

    /**
     * 后台访问时轻量运行一次（限流2分钟）
     */
    public static function maybe_run_lightweight() {
        if (!current_user_can('manage_zibll_ads')) {
            return;
        }
        $key = 'zibll_ad_last_reconcile_run';
        $last = get_transient($key);
        if ($last) return;

        self::run(10);
        set_transient($key, time(), 120); // 2 分钟限流
    }

    /**
     * 扫描并补录
     *
     * @param int $limit 限制处理条数（默认50）
     */
    public static function run($limit = 50) {
        global $wpdb;
        $zibpay_order = $wpdb->prefix . 'zibpay_order';
        $plugin_orders = $wpdb->prefix . 'zibll_ad_orders';

        // 先标记超时未支付的 pending 订单（历史保留）
        $timeout_minutes = zibll_ad_get_order_timeout();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (($timeout_minutes + 10) * 60)); // 比 pending_expires 多给10分钟裕量
        $closed = $wpdb->query($wpdb->prepare("UPDATE {$plugin_orders} SET pay_status='timeout', closed_at=%s WHERE pay_status='pending' AND created_at < %s", current_time('mysql'), $cutoff));
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('Reconcile: timeout pending closed', array('affected' => intval($closed)));
        }

        // 仅扫描广告订单（order_type=31）且已支付（status=1），且尚未入插件表
        // 🔧 修复：排除已删除的订单（通过 option 记录追踪）
        $sql = $wpdb->prepare(
            "SELECT o.* FROM {$zibpay_order} o
             LEFT JOIN {$plugin_orders} a ON a.zibpay_order_id = o.id
             WHERE o.order_type = %s AND o.status = %d AND a.id IS NULL
             ORDER BY o.id DESC
             LIMIT %d",
             '31', 1, absint($limit)
        );
        $rows = $wpdb->get_results($sql);
        
        // 🔧 过滤掉已被管理员删除的订单
        if (!empty($rows)) {
            $deleted_order_ids = get_option('zibll_ad_deleted_zibpay_orders', array());
            if (!is_array($deleted_order_ids)) {
                $deleted_order_ids = array();
            }
            if (!empty($deleted_order_ids)) {
                $rows = array_filter($rows, function($row) use ($deleted_order_ids) {
                    return !in_array(intval($row->id), $deleted_order_ids, true);
                });
            }
        }

        if (empty($rows)) {
            if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
                zibll_ad_log('Reconcile: no missing orders');
            }
            return;
        }

        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('Reconcile: found missing orders', array('count' => count($rows)));
        }

        // 逐条调用已存在的成功回调处理逻辑（具有幂等检查）
        $sync = new Zibll_Ad_Order_Sync();
        foreach ($rows as $row) {
            try {
                $sync->on_payment_success($row); // 内部已做存在性检查与fallback
            } catch (Exception $e) {
                zibll_ad_log('Reconcile: on_payment_success exception', array(
                    'order_id' => $row->id,
                    'message'  => $e->getMessage(),
                ));
            }
        }
    }
}

// 注册钩子
Zibll_Ad_Order_Reconcile::init();
