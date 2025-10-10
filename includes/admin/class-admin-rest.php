<?php
/**
 * 管理端 REST API 控制器
 *
 * 处理广告位的 CRUD 操作和 Widget 自动挂载
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 加载基础控制器
require_once ZIBLL_AD_PATH . 'includes/rest/class-rest-controller.php';
require_once ZIBLL_AD_PATH . 'includes/class-settings.php';

/**
 * 管理端 REST API 控制器类
 */
class Zibll_Ad_Admin_REST extends Zibll_Ad_REST_Controller {

    /**
     * 注册路由
     */
    public function register_routes() {
        // 仪表盘概览
        register_rest_route($this->namespace, '/dashboard', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_dashboard'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'recent_limit' => array(
                        'description' => __('最近订单条数', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 5,
                        'sanitize_callback' => 'absint',
                    ),
                    'expiring_days' => array(
                        'description' => __('即将到期天数范围', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 7,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ));
        // 获取所有广告位列表
        register_rest_route($this->namespace, '/slots', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_slots'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'keyword' => array(
                        'description' => __('搜索关键词（广告位名称）', 'zibll-ad'),
                        'type' => 'string',
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'type' => array(
                        'description' => __('广告位类型', 'zibll-ad'),
                        'type' => 'string',
                        'default' => '',
                        'enum' => array('', 'image', 'text'),
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'per_page' => array(
                        'description' => __('每页数量（-1 表示全部）', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => -1,
                        'sanitize_callback' => function($value) {
                            // 允许 -1 表示全部,其他值取绝对值
                            return ($value == -1) ? -1 : absint($value);
                        },
                    ),
                    'page' => array(
                        'description' => __('页码', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ),
                    'orderby' => array(
                        'description' => __('排序字段', 'zibll-ad'),
                        'type' => 'string',
                        'default' => 'sort_order',
                        'enum' => array('sort_order', 'date', 'title', 'ID'),
                    ),
                    'order' => array(
                        'description' => __('排序方向', 'zibll-ad'),
                        'type' => 'string',
                        'default' => 'ASC',
                        'enum' => array('ASC', 'DESC'),
                    ),
                ),
            ),
        ));

        // ================= 插件设置 =================
        // 获取设置
        register_rest_route($this->namespace, '/settings', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_settings'),
                'permission_callback' => array($this, 'check_read_permission'),
            ),
        ));

        // 更新设置（PUT）
        register_rest_route($this->namespace, '/settings', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_settings'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'allow_guest_purchase' => array('type' => 'boolean'),
                    'default_purchase_notice' => array('type' => 'string'),
                    'allow_image_upload' => array('type' => 'boolean'), // 允许上传图片（所有用户）
                    'image_max_size' => array('type' => 'integer'), // KB
                    'image_allowed_types' => array('type' => 'array'),
                    'enable_expiry_notification' => array('type' => 'boolean'),
                    'expiry_notice_days' => array('type' => 'integer'),
                    'keep_data_on_uninstall' => array('type' => 'boolean'),
                    'allow_balance_payment' => array('type' => 'boolean'),
                    // 新增：允许游客上传图片（控制前台图片素材上传权限）
                    'allow_guest_image_upload' => array('type' => 'boolean'),
                    // 新增：链接重定向（go.php）
                    'link_redirect' => array('type' => 'boolean'),
                ),
            ),
        ));

        // 创建广告位
        register_rest_route($this->namespace, '/slots', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_slot'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => $this->get_slot_create_args(),
            ),
        ));

        // 获取单个广告位
        register_rest_route($this->namespace, '/slots/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_slot'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'id' => array(
                        'description' => __('广告位 ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        },
                    ),
                ),
            ),
        ));

        // 更新广告位
        register_rest_route($this->namespace, '/slots/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_slot'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => $this->get_slot_update_args(),
            ),
        ));

        // 删除广告位
        register_rest_route($this->namespace, '/slots/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_slot'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'id' => array(
                        'description' => __('广告位 ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                    ),
                ),
            ),
        ));

        // 获取可用的 sidebars（用于前端下拉选择）
        register_rest_route($this->namespace, '/sidebars', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_sidebars'),
                'permission_callback' => array($this, 'check_read_permission'),
            ),
        ));

        // ================= 广告管理（基于单元 Units） =================
        // 广告列表（仅投放中的：paid 且未过期）
        register_rest_route($this->namespace, '/ads', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_ads'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'page' => array(
                        'description' => __('页码', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ),
                    'per_page' => array(
                        'description' => __('每页数量', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 20,
                        'sanitize_callback' => 'absint',
                    ),
                    'keyword' => array(
                        'description' => __('关键词（名称/网站/链接）', 'zibll-ad'),
                        'type' => 'string',
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'slot_id' => array(
                        'description' => __('广告位 ID', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ),
                    'orderby' => array(
                        'description' => __('排序字段', 'zibll-ad'),
                        'type' => 'string',
                        'default' => 'ends_at',
                        'enum' => array('ends_at', 'starts_at', 'id'),
                    ),
                    'order' => array(
                        'description' => __('排序方向', 'zibll-ad'),
                        'type' => 'string',
                        'default' => 'DESC',
                        'enum' => array('ASC', 'DESC'),
                    ),
                ),
            ),
        ));

        // 广告详情
        register_rest_route($this->namespace, '/ads/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_ad'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'id' => array(
                        'description' => __('单元(广告) ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                    ),
                ),
            ),
        ));

        // 新增广告（无需订单）
        register_rest_route($this->namespace, '/ads', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_ad'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'slot_id' => array('type' => 'integer', 'required' => true),
                    'unit_key' => array('type' => 'integer', 'required' => true),
                    'duration_months' => array('type' => 'integer', 'required' => true),
                    'customer_name' => array('type' => 'string'),
                    'website_name' => array('type' => 'string'),
                    'website_url' => array('type' => 'string'),
                    'contact_type' => array('type' => 'string'),
                    'contact_value' => array('type' => 'string'),
                    'color_key' => array('type' => 'string'),
                    'image_id' => array('type' => 'integer'),
                    'image_url' => array('type' => 'string'),
                    'text_content' => array('type' => 'string'),
                    'target_url' => array('type' => 'string'),
                ),
            ),
        ));

        // 修改广告
        register_rest_route($this->namespace, '/ads/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_ad'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                    'customer_name' => array('type' => 'string'),
                    'website_name' => array('type' => 'string'),
                    'website_url' => array('type' => 'string'),
                    'contact_type' => array('type' => 'string'),
                    'contact_value' => array('type' => 'string'),
                    'color_key' => array('type' => 'string'),
                    'image_id' => array('type' => 'integer'),
                    'image_url' => array('type' => 'string'),
                    'text_content' => array('type' => 'string'),
                    'target_url' => array('type' => 'string'),
                    'duration_months' => array('type' => 'integer'),
                    'ends_at' => array('type' => array('string','integer')),
                ),
            ),
        ));

        // 下架广告
        register_rest_route($this->namespace, '/ads/(?P<id>\d+)/takedown', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'takedown_ad'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));

        // 查询某广告位可选位置（可用的 units）
        register_rest_route($this->namespace, '/slots/(?P<id>\d+)/available-units', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_available_units'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'id' => array('type' => 'integer', 'required' => true),
                ),
            ),
        ));

        // ================= 订单管理 =================
        // 列表查询
        register_rest_route($this->namespace, '/orders', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_orders'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => $this->get_order_list_args(),
            ),
        ));

        // 订单详情
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_order'),
                'permission_callback' => array($this, 'check_read_permission'),
                'args' => array(
                    'id' => array(
                        'description' => __('订单 ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                    ),
                ),
            ),
        ));

        // 手动下架
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/takedown', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'takedown_order'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'id' => array(
                        'description' => __('订单 ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                    ),
                ),
            ),
        ));

        // 删除订单
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_order'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'id' => array(
                        'description' => __('订单 ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                    ),
                ),
            ),
        ));
    }

    /**
     * 获取仪表盘概览数据
     */
    public function get_dashboard($request) {
        global $wpdb;

        $recent_limit  = max(1, min(20, absint($request->get_param('recent_limit'))));
        $expiring_days = max(1, absint($request->get_param('expiring_days')));

        $this->log_api_call('/dashboard', 'GET', array(
            'recent_limit' => $recent_limit,
            'expiring_days' => $expiring_days,
        ));

        // 统计：广告位总数
        $total_slots = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private')",
            'zibll_ad_slot'
        ));

        // Units 统计
        $table_units = $wpdb->prefix . 'zibll_ad_units';
        // 使用服务器时间，避免时区偏移造成的8小时差
        $now_ts = time();

        // paid（未过期）
        $paid_units = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_units} WHERE status = 'paid' AND (ends_at IS NULL OR ends_at = 0 OR ends_at > %d)",
            $now_ts
        ));

        // available
        $available_units = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_units} WHERE status = 'available'");

        // pending
        $pending_units = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_units} WHERE status = 'pending'");

        // 即将到期：在 expiring_days 天内到期的 paid 单元
        $deadline = $now_ts + ($expiring_days * DAY_IN_SECONDS);
        $expiring_soon = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_units} WHERE status = 'paid' AND ends_at IS NOT NULL AND ends_at > %d AND ends_at <= %d",
            $now_ts, $deadline
        ));

        // 即将到期的单元明细列表（用于概览展示）
        $expiring_list = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.id AS unit_id, u.slot_id, u.unit_key, u.ends_at, u.website_name, u.website_url,
                        u.contact_type, u.contact_value,
                        p.post_title AS slot_title
                 FROM {$table_units} u
                 LEFT JOIN {$wpdb->posts} p ON p.ID = u.slot_id
                 WHERE u.status = 'paid'
                   AND u.ends_at IS NOT NULL
                   AND u.ends_at > %d
                   AND u.ends_at <= %d
                 ORDER BY u.ends_at ASC
                 LIMIT 50",
                $now_ts,
                $deadline
            ),
            ARRAY_A
        );

        // 丰富列表信息：每条记录添加 slot_per_row 与剩余天数
        if (is_array($expiring_list) && !empty($expiring_list)) {
            foreach ($expiring_list as &$item) {
                $sid = isset($item['slot_id']) ? intval($item['slot_id']) : 0;
                $display_layout = $sid ? get_post_meta($sid, 'display_layout', true) : array();
                $item['slot_per_row'] = (is_array($display_layout) && isset($display_layout['per_row'])) ? intval($display_layout['per_row']) : 3;
                $ends_ts = isset($item['ends_at']) ? intval($item['ends_at']) : 0;
                $item['remaining_days'] = $ends_ts > $now_ts ? ceil(($ends_ts - $now_ts) / DAY_IN_SECONDS) : 0;
            }
            unset($item);
        }

        // 本月收入（paid 订单按 paid_at 汇总）
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        // 本月区间采用服务器时间
        $month_start = date('Y-m-01 00:00:00', $now_ts);
        $month_end   = date('Y-m-t 23:59:59', $now_ts);
        $monthly_revenue = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_price),0) FROM {$table_orders} WHERE pay_status = 'paid' AND paid_at BETWEEN %s AND %s",
            $month_start, $month_end
        ));

        // 订单状态汇总
        $orders_summary = class_exists('Zibll_Ad_Order_Model') ? Zibll_Ad_Order_Model::count_by_status(array()) : array();

        // 最近订单
        $recent_orders = array();
        if (class_exists('Zibll_Ad_Order_Model')) {
            $res = Zibll_Ad_Order_Model::query_orders(array(
                'page' => 1,
                'per_page' => $recent_limit,
                'orderby' => 'created_at',
                'order' => 'DESC',
            ));
            $recent_orders = isset($res['orders']) ? $res['orders'] : array();
        }

        // 近30天收入趋势（按日聚合）与 Top 广告位（按收入）
        $trend_days = 30;
        $range_start_ts = strtotime(date('Y-m-d 00:00:00', $now_ts - (29 * DAY_IN_SECONDS)));
        $range_end_ts   = strtotime(date('Y-m-d 23:59:59', $now_ts));
        $range_start = date('Y-m-d H:i:s', $range_start_ts);
        $range_end   = date('Y-m-d H:i:s', $range_end_ts);

        // 收入趋势
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(paid_at) AS d, COUNT(*) AS cnt, SUM(total_price) AS amount
                 FROM {$table_orders}
                 WHERE pay_status = 'paid' AND paid_at BETWEEN %s AND %s
                 GROUP BY DATE(paid_at)",
                $range_start,
                $range_end
            ),
            ARRAY_A
        );

        $trend_map = array();
        if ($rows) {
            foreach ($rows as $r) {
                $key = $r['d'];
                $trend_map[$key] = array(
                    'date' => $key,
                    'orders' => intval($r['cnt']),
                    'revenue' => (float) $r['amount'],
                );
            }
        }

        $trend = array();
        for ($i = 29; $i >= 0; $i--) {
            $day_ts = strtotime(date('Y-m-d', $now_ts - ($i * DAY_IN_SECONDS)));
            $key = date('Y-m-d', $day_ts);
            if (isset($trend_map[$key])) {
                $trend[] = $trend_map[$key];
            } else {
                $trend[] = array(
                    'date' => $key,
                    'orders' => 0,
                    'revenue' => 0.0,
                );
            }
        }

        // Top 广告位（近30天按收入）
        $top_slots = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.slot_id, p.post_title AS slot_title, COUNT(*) AS orders, SUM(o.total_price) AS revenue
                 FROM {$table_orders} o
                 LEFT JOIN {$wpdb->posts} p ON p.ID = o.slot_id
                 WHERE o.pay_status = 'paid' AND o.paid_at BETWEEN %s AND %s
                 GROUP BY o.slot_id
                 ORDER BY revenue DESC
                 LIMIT 10",
                $range_start,
                $range_end
            ),
            ARRAY_A
        );

        // 系统运行状态
        $cron_expire_ts    = wp_next_scheduled('zibll_ad_cron_check_expire');
        $cron_reconcile_ts = wp_next_scheduled('zibll_ad_cron_reconcile_orders');

        // 广告位统计（启用/未启用）
        $slot_stats = array(
            'total' => $total_slots,
            'enabled' => 0,
            'disabled' => 0,
        );

        // 统计启用和未启用的广告位
        $enabled_slots = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE p.post_type = %s
             AND p.post_status = 'publish'
             AND pm.meta_key = 'enabled'
             AND pm.meta_value = '1'",
            'zibll_ad_slot'
        ));

        $slot_stats['enabled'] = $enabled_slots;
        $slot_stats['disabled'] = max(0, $total_slots - $enabled_slots);

        // 设置快照（用于概览展示）
        $settings_snapshot = array();
        if (class_exists('Zibll_Ad_Settings')) {
            $settings = Zibll_Ad_Settings::get_all();
            $settings_snapshot = array(
                'allow_guest_purchase'    => isset($settings['allow_guest_purchase']) ? (bool) $settings['allow_guest_purchase'] : true,
                'allow_balance_payment'   => isset($settings['allow_balance_payment']) ? (bool) $settings['allow_balance_payment'] : true,
                'expiry_notice_days'      => isset($settings['expiry_notice_days']) ? intval($settings['expiry_notice_days']) : 7,
                'enable_expiry_notification' => isset($settings['enable_expiry_notification']) ? (bool) $settings['enable_expiry_notification'] : true,
            );
        }

        $data = array(
            'stats' => array(
                'totalSlots'      => $total_slots,
                'paidUnits'       => $paid_units,
                'availableUnits'  => $available_units,
                'pendingUnits'    => $pending_units,
                'expiringSoon'    => $expiring_soon,
                'monthlyRevenue'  => round($monthly_revenue, 2),
            ),
            'orders_summary' => $orders_summary,
            'recent_orders'  => $recent_orders,
            'expiring_list'  => $expiring_list,
            'trend' => array(
                'range' => array(
                    'start' => $range_start,
                    'end'   => $range_end,
                ),
                'days' => $trend,
                'total_revenue' => array_sum(wp_list_pluck($trend, 'revenue')),
                'total_orders'  => array_sum(wp_list_pluck($trend, 'orders')),
            ),
            'top_slots' => $top_slots,
            'system' => array(
                'cron' => array(
                    'expire' => array(
                        'scheduled' => $cron_expire_ts ? true : false,
                        'nextRun'   => $cron_expire_ts ? wp_date('Y-m-d H:i', $cron_expire_ts) : '',
                        'nextRunTs' => $cron_expire_ts ? intval($cron_expire_ts) : 0,
                    ),
                    'reconcile' => array(
                        'scheduled' => $cron_reconcile_ts ? true : false,
                        'nextRun'   => $cron_reconcile_ts ? wp_date('Y-m-d H:i', $cron_reconcile_ts) : '',
                        'nextRunTs' => $cron_reconcile_ts ? intval($cron_reconcile_ts) : 0,
                    ),
                ),
                'slots' => $slot_stats,
                'db' => array(
                    'version' => get_option('zibll_ad_db_version'),
                ),
                'settings' => $settings_snapshot,
            ),
        );

        return $this->success_response($data);
    }

    /**
     * 获取插件设置
     */
    public function get_settings($request) {
        $this->log_api_call('/settings', 'GET');

        $data = Zibll_Ad_Settings::get_all();
        // 附带主题支付方式列表，便于前端渲染
        $data['available_payment_methods'] = Zibll_Ad_Settings::get_theme_payment_methods();
        // 附带允许的图片类型
        $data['available_image_types'] = Zibll_Ad_Settings::allowed_image_types();

        return $this->success_response($data);
    }

    /**
     * 获取广告列表（正在投放）
     */
    public function get_ads($request) {
        global $wpdb;

        $table_units  = $wpdb->prefix . 'zibll_ad_units';
        $table_posts  = $wpdb->posts;
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';

        $page     = max(1, absint($request->get_param('page')));
        $per_page = max(1, absint($request->get_param('per_page')));
        $keyword  = trim((string) $request->get_param('keyword'));
        $slot_id  = absint($request->get_param('slot_id'));
        $orderby  = in_array($request->get_param('orderby'), array('ends_at','starts_at','id'), true) ? $request->get_param('orderby') : 'ends_at';
        $order    = strtoupper($request->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';

        $now = time();

        $where   = array("u.status = 'paid'", '(u.ends_at IS NULL OR u.ends_at = 0 OR u.ends_at > %d)');
        $params  = array($now);

        if (!empty($keyword)) {
            $kw = '%' . $wpdb->esc_like($keyword) . '%';
            $where[] = '(u.customer_name LIKE %s OR u.website_name LIKE %s OR u.website_url LIKE %s OR u.target_url LIKE %s)';
            array_push($params, $kw, $kw, $kw, $kw);
        }
        if (!empty($slot_id)) {
            $where[] = 'u.slot_id = %d';
            $params[] = $slot_id;
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        // 统计总数
        $sql_total = "SELECT COUNT(*) FROM {$table_units} u {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($sql_total, $params));

        $offset = ($page - 1) * $per_page;

        // 查询数据，附带广告位标题以及最近一次下单用户 ID（若存在）
        $sql = "SELECT u.*, p.post_title AS slot_title,
            (SELECT o.user_id FROM {$table_orders} o WHERE o.unit_id = u.id AND o.pay_status IN ('paid','takedown') ORDER BY o.paid_at DESC, o.id DESC LIMIT 1) AS user_id,
            (SELECT COUNT(*) FROM {$table_orders} o2 WHERE o2.unit_id = u.id AND o2.pay_status IN ('paid','takedown')) AS order_count
            FROM {$table_units} u
            LEFT JOIN {$table_posts} p ON p.ID = u.slot_id
            {$where_sql}
            ORDER BY u.{$orderby} {$order}
            LIMIT %d OFFSET %d";
        $query_params = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        $ads = array();
        foreach ($rows as $r) {
            $item = $r;
            $item['is_active'] = ($r['status'] === 'paid' && (empty($r['ends_at']) || intval($r['ends_at']) > $now));
            // 用户名
            $has_order = isset($r['order_count']) && intval($r['order_count']) > 0;
            if ($has_order) {
                // 前台购买（包括游客购买 user_id=0 的情况）
                if ($r['user_id'] && intval($r['user_id']) > 0) {
                    $u = get_userdata(intval($r['user_id']));
                    $item['user_name'] = $u ? ($u->display_name ?: $u->user_login) : __('未登录用户', 'zibll-ad');
                } else {
                    // 游客购买
                    $item['user_name'] = __('未登录用户', 'zibll-ad');
                }
            } else {
                // 后台手动创建
                $item['user_name'] = __('后台手动创建', 'zibll-ad');
            }

            // 计算unit_index（在排序后数组中的实际位置）
            if (!empty($r['slot_id']) && isset($r['unit_key'])) {
                $unit_index = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_units}
                    WHERE slot_id = %d AND unit_key < %d",
                    intval($r['slot_id']),
                    intval($r['unit_key'])
                ));
                $item['unit_index'] = $unit_index;
            } else {
                $item['unit_index'] = 0;
            }

            // 获取per_row配置
            if (!empty($r['slot_id'])) {
                $display_layout = get_post_meta(intval($r['slot_id']), 'display_layout', true);
                if (is_array($display_layout) && isset($display_layout['per_row'])) {
                    $item['slot_per_row'] = intval($display_layout['per_row']);
                } else {
                    $item['slot_per_row'] = 3;
                }
            } else {
                $item['slot_per_row'] = 3;
            }

            $ads[] = $item;
        }

        return $this->success_response(array(
            'ads' => $ads,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ));
    }

    /**
     * 广告详情（按单元）
     */
    public function get_ad($request) {
        global $wpdb;
        $unit_id = absint($request->get_param('id'));
        if ($unit_id <= 0) {
            return $this->error_response('invalid_param', __('无效的广告 ID', 'zibll-ad'), 400);
        }

        $unit = class_exists('Zibll_Ad_Unit_Model') ? Zibll_Ad_Unit_Model::get($unit_id) : null;
        if (!$unit) {
            return $this->error_response('ad_not_found', __('广告不存在', 'zibll-ad'), 404);
        }

        $post = get_post($unit['slot_id']);
        $unit['slot_title'] = $post ? $post->post_title : '';

        // 最近一次关联订单用户
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table_orders} WHERE unit_id = %d AND pay_status IN ('paid','takedown') ORDER BY paid_at DESC, id DESC LIMIT 1",
            $unit_id
        ));
        if ($user_id > 0) {
            $u = get_userdata($user_id);
            $unit['user_name'] = $u ? ($u->display_name ?: $u->user_login) : __('未登录用户', 'zibll-ad');
        } else {
            $unit['user_name'] = __('未登录用户', 'zibll-ad');
        }

        // 获取广告位的per_row配置和类型
        if (!empty($unit['slot_id'])) {
            $slot_id = intval($unit['slot_id']);

            // 获取广告位类型
            $slot_type = get_post_meta($slot_id, 'slot_type', true);
            $unit['slot_type'] = $slot_type ?: 'image';

            // 获取广告位挂载方式
            $mount_type = get_post_meta($slot_id, 'mount_type', true);
            $unit['slot_mount_type'] = $mount_type ?: 'widget';

            // 获取图片显示模式
            $image_display_mode = get_post_meta($slot_id, 'image_display_mode', true);
            $unit['slot_image_display_mode'] = $image_display_mode ?: 'grid';

            $display_layout = get_post_meta($slot_id, 'display_layout', true);
            if (is_array($display_layout) && isset($display_layout['per_row'])) {
                $unit['slot_per_row'] = intval($display_layout['per_row']);
            } else {
                $unit['slot_per_row'] = 3; // 默认值
            }

            // 计算unit在数组中的实际索引位置
            if (isset($unit['unit_key'])) {
                $table_units = $wpdb->prefix . 'zibll_ad_units';
                $unit_index = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_units}
                    WHERE slot_id = %d AND unit_key < %d
                    ORDER BY unit_key ASC",
                    $slot_id,
                    intval($unit['unit_key'])
                ));
                $unit['unit_index'] = $unit_index;
            }
        } else {
            $unit['slot_type'] = 'image';
            $unit['slot_per_row'] = 3; // 默认值
            $unit['unit_index'] = 0;
        }

        return $this->success_response($unit);
    }

    /**
     * 新增广告（直接占用可用位置并置为 paid）
     */
    public function create_ad($request) {
        $slot_id = absint($request->get_param('slot_id'));
        $unit_key = absint($request->get_param('unit_key'));
        $duration_months = max(1, absint($request->get_param('duration_months')));

        if ($slot_id <= 0) {
            return $this->error_response('invalid_param', __('无效的广告位 ID', 'zibll-ad'), 400);
        }

        if (!class_exists('Zibll_Ad_Unit_Model')) {
            return $this->error_response('server_error', __('服务不可用：缺少单元模型', 'zibll-ad'), 500);
        }

        $unit = Zibll_Ad_Unit_Model::get_by_slot_and_key($slot_id, $unit_key);
        if (!$unit) {
            return $this->error_response('unit_not_found', __('所选位置不存在', 'zibll-ad'), 404);
        }
        if ($unit['status'] !== 'available') {
            return $this->error_response('unit_unavailable', __('所选位置不可用，请重新选择', 'zibll-ad'), 400);
        }

        // 组装广告数据（允许字段）
        $fields = array('customer_name','website_name','website_url','contact_type','contact_value','color_key','image_id','image_url','text_content','target_url');
        $ad_data = array();
        foreach ($fields as $f) {
            if ($request->has_param($f)) {
                $ad_data[$f] = $request->get_param($f);
            }
        }

        $ok = Zibll_Ad_Unit_Model::set_paid($unit['id'], $ad_data, $duration_months);
        if (!$ok) {
            return $this->error_response('create_failed', __('新增广告失败，请重试', 'zibll-ad'), 500);
        }

        $updated = Zibll_Ad_Unit_Model::get($unit['id']);
        return $this->success_response($updated, 201, __('广告已创建并开始投放', 'zibll-ad'));
    }

    /**
     * 修改广告内容/时长
     */
    public function update_ad($request) {
        $unit_id = absint($request->get_param('id'));
        if ($unit_id <= 0) {
            return $this->error_response('invalid_param', __('无效的广告 ID', 'zibll-ad'), 400);
        }
        if (!class_exists('Zibll_Ad_Unit_Model')) {
            return $this->error_response('server_error', __('服务不可用：缺少单元模型', 'zibll-ad'), 500);
        }
        $unit = Zibll_Ad_Unit_Model::get($unit_id);
        if (!$unit) {
            return $this->error_response('ad_not_found', __('广告不存在', 'zibll-ad'), 404);
        }

        $data = array();
        foreach (array('customer_name','website_name','website_url','contact_type','contact_value','color_key','image_id','image_url','text_content','target_url') as $f) {
            if ($request->has_param($f)) {
                $data[$f] = $request->get_param($f);
            }
        }
        if ($request->has_param('duration_months')) {
            $months = max(1, absint($request->get_param('duration_months')));
            $starts = !empty($unit['starts_at']) ? intval($unit['starts_at']) : time();
            $data['duration_months'] = $months;
            $data['starts_at'] = $starts;
            $data['ends_at'] = $starts + ($months * 30 * DAY_IN_SECONDS);
        }
        // 允许管理员直接设置到期时间（优先于 duration_months）
        if ($request->has_param('ends_at')) {
            $ends_at_raw = $request->get_param('ends_at');
            if (is_numeric($ends_at_raw)) {
                $data['ends_at'] = intval($ends_at_raw);
            } else {
                $ts = strtotime(sanitize_text_field($ends_at_raw));
                if ($ts) {
                    $data['ends_at'] = $ts;
                }
            }
        }

        // 约束：到期时间必须晚于开始时间
        if (isset($data['ends_at'])) {
            $starts_at_ref = isset($data['starts_at']) ? intval($data['starts_at']) : (isset($unit['starts_at']) ? intval($unit['starts_at']) : 0);
            if ($starts_at_ref && intval($data['ends_at']) <= $starts_at_ref) {
                return $this->error_response('invalid_param', __('到期时间必须晚于开始时间', 'zibll-ad'), 400);
            }
        }

        if (empty($data)) {
            return $this->error_response('nothing_to_update', __('无更新内容', 'zibll-ad'), 400);
        }

        $ok = Zibll_Ad_Unit_Model::update($unit_id, $data);
        if (!$ok) {
            return $this->error_response('update_failed', __('更新失败，请重试', 'zibll-ad'), 500);
        }

        $updated = Zibll_Ad_Unit_Model::get($unit_id);
        return $this->success_response($updated, 200, __('广告已更新', 'zibll-ad'));
    }

    /**
     * 下架广告：释放单元为 available，同时同步最近一次订单（如有）为 takedown
     */
    public function takedown_ad($request) {
        global $wpdb;
        $unit_id = absint($request->get_param('id'));
        if ($unit_id <= 0) {
            return $this->error_response('invalid_param', __('无效的广告 ID', 'zibll-ad'), 400);
        }
        if (!class_exists('Zibll_Ad_Unit_Model')) {
            return $this->error_response('server_error', __('服务不可用：缺少单元模型', 'zibll-ad'), 500);
        }
        $unit = Zibll_Ad_Unit_Model::get($unit_id);
        if (!$unit) {
            return $this->error_response('ad_not_found', __('广告不存在', 'zibll-ad'), 404);
        }
        if ($unit['status'] !== 'paid') {
            return $this->error_response('invalid_status', __('当前广告不在投放中', 'zibll-ad'), 400);
        }

        $ok = Zibll_Ad_Unit_Model::set_available($unit_id, true);
        if (!$ok) {
            return $this->error_response('takedown_failed', __('下架失败，请重试', 'zibll-ad'), 500);
        }

        // 同步最近一次订单状态
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $last_order_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_orders} WHERE unit_id = %d AND pay_status = 'paid' ORDER BY paid_at DESC, id DESC LIMIT 1",
            $unit_id
        ));
        if ($last_order_id) {
            $wpdb->update(
                $table_orders,
                array('pay_status' => 'takedown', 'closed_at' => current_time('mysql')),
                array('id' => $last_order_id),
                array('%s','%s'),
                array('%d')
            );
        }

        // 清缓存
        if (!empty($unit['slot_id']) && function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($unit['slot_id']);
        }

        return $this->success_response(array('id' => $unit_id), 200, __('已下架', 'zibll-ad'));
    }

    /**
     * 获取某广告位的可用位置（units）
     */
    public function get_available_units($request) {
        global $wpdb;
        $slot_id = absint($request->get_param('id'));
        if ($slot_id <= 0) {
            return $this->error_response('invalid_param', __('无效的广告位 ID', 'zibll-ad'), 400);
        }

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        // 仅返回“可购买”的唯一位置：
        // 1) 该 unit_key 下不存在已占用记录（paid/pending）
        // 2) 去重同一 unit_key 下的多个 available 记录（取最小 id）
        $sql = "
            SELECT u1.id, u1.unit_key, u1.status
            FROM {$table_units} u1
            WHERE u1.slot_id = %d
              AND u1.status = 'available'
              AND NOT EXISTS (
                SELECT 1 FROM {$table_units} u2
                WHERE u2.slot_id = u1.slot_id
                  AND u2.unit_key = u1.unit_key
                  AND u2.status IN ('paid','pending')
              )
              AND u1.id = (
                SELECT MIN(u3.id) FROM {$table_units} u3
                WHERE u3.slot_id = u1.slot_id
                  AND u3.unit_key = u1.unit_key
                  AND u3.status = 'available'
              )
            ORDER BY u1.unit_key ASC
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $slot_id), ARRAY_A);

        return $this->success_response(array('units' => $rows));
    }

    /**
     * 更新插件设置
     */
    public function update_settings($request) {
        $params = $request->get_json_params();
        $this->log_api_call('/settings', 'PUT', $params);

        if (!is_array($params)) {
            return $this->error_response('invalid_params', __('参数无效', 'zibll-ad'), 400);
        }

        $saved = Zibll_Ad_Settings::update($params);

        // 关键联动：更新超时设置后，前端立刻可见
        // 同时建议清理可能依赖超时设置的 transient（此处按需处理）

        return $this->success_response($saved, 200, __('设置已保存', 'zibll-ad'));
    }

    /**
     * 获取广告位列表
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function get_slots($request) {
        $this->log_api_call('/slots', 'GET', $request->get_params());

        $keyword = $request->get_param('keyword');
        $type = $request->get_param('type');
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $orderby = $request->get_param('orderby');
        $order = $request->get_param('order');

        // 构建查询参数
        $args = array(
            'post_type' => 'zibll_ad_slot',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'order' => $order,
        );

        // 处理关键词搜索（广告位名称）
        if (!empty($keyword)) {
            $args['s'] = $keyword;
        }

        // 处理类型筛选
        if (!empty($type) && in_array($type, array('image', 'text'))) {
            $args['meta_query'] = array(
                array(
                    'key' => 'slot_type',
                    'value' => $type,
                    'compare' => '=',
                ),
            );
        }

        // 处理排序字段
        if ($orderby === 'sort_order') {
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = 'sort_order';
        } else {
            $args['orderby'] = $orderby;
        }

        // 获取广告位列表
        $slots = Zibll_Ad_Slot_Model::get_all($args);

        // 为每个 slot 添加统计信息
        $enriched_slots = array();
        foreach ($slots as $slot) {
            // 获取 units 统计
            $units_stats = $this->get_slot_units_stats($slot['id']);
            $slot['units_stats'] = $units_stats;

            $enriched_slots[] = $slot;
        }

        // 获取总数（用于分页）- 使用相同的筛选条件
        $total_args = array(
            'post_type' => 'zibll_ad_slot',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );

        // 应用相同的筛选条件
        if (!empty($keyword)) {
            $total_args['s'] = $keyword;
        }

        if (!empty($type) && in_array($type, array('image', 'text'))) {
            $total_args['meta_query'] = array(
                array(
                    'key' => 'slot_type',
                    'value' => $type,
                    'compare' => '=',
                ),
            );
        }

        $total_query = new WP_Query($total_args);
        $total = $total_query->found_posts;

        return $this->success_response(array(
            'slots' => $enriched_slots,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ));
    }

    /**
     * 获取订单列表
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_orders($request) {
        $this->log_api_call('/orders', 'GET', $request->get_params());

        $args = array(
            'page'           => max(1, intval($request->get_param('page'))),
            'per_page'       => max(1, intval($request->get_param('per_page'))),
            'keyword'        => sanitize_text_field($request->get_param('keyword')),
            'slot_id'        => intval($request->get_param('slot_id')),
            'pay_status'     => sanitize_text_field($request->get_param('pay_status')),
            'payment_method' => sanitize_text_field($request->get_param('payment_method')),
            'date_from'      => sanitize_text_field($request->get_param('date_from')),
            'date_to'        => sanitize_text_field($request->get_param('date_to')),
            'orderby'        => sanitize_text_field($request->get_param('orderby')),
            'order'          => sanitize_text_field($request->get_param('order')),
        );

        $result = Zibll_Ad_Order_Model::query_orders($args);
        $summary = Zibll_Ad_Order_Model::count_by_status($args);

        return $this->success_response(array(
            'orders'   => $result['orders'],
            'total'    => $result['total'],
            'page'     => $args['page'],
            'per_page' => $args['per_page'],
            'summary'  => $summary,
        ));
    }

    /**
     * 获取订单详情
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_order($request) {
        $order_id = intval($request['id']);
        $this->log_api_call('/orders/' . $order_id, 'GET');

        if ($order_id <= 0) {
            return $this->error_response('invalid_param', __('无效的订单 ID', 'zibll-ad'), 400);
        }

        $order = Zibll_Ad_Order_Model::get($order_id);
        if (!$order) {
            return $this->error_response('order_not_found', __('订单不存在', 'zibll-ad'), 404);
        }

        return $this->success_response($order);
    }

    /**
     * 手动下架订单
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function takedown_order($request) {
        $order_id = intval($request['id']);
        $this->log_api_call('/orders/' . $order_id . '/takedown', 'POST');

        if ($order_id <= 0) {
            return $this->error_response('invalid_param', __('无效的订单 ID', 'zibll-ad'), 400);
        }

        $result = Zibll_Ad_Order_Model::takedown($order_id);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success_response(array('id' => $order_id), 200, __('已下架', 'zibll-ad'));
    }

    /**
     * 删除订单
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_order($request) {
        $order_id = intval($request['id']);
        $this->log_api_call('/orders/' . $order_id, 'DELETE');

        if ($order_id <= 0) {
            return $this->error_response('invalid_param', __('无效的订单 ID', 'zibll-ad'), 400);
        }

        $result = Zibll_Ad_Order_Model::delete($order_id);
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->success_response(array('id' => $order_id), 200, __('订单已删除', 'zibll-ad'));
    }

    /**
     * 订单列表参数定义
     *
     * @return array
     */
    private function get_order_list_args() {
        return array(
            'page' => array(
                'description' => __('页码', 'zibll-ad'),
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'description' => __('每页数量', 'zibll-ad'),
                'type' => 'integer',
                'default' => 20,
                'sanitize_callback' => 'absint',
            ),
            'keyword' => array(
                'description' => __('关键词（订单号/客户/网站）', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'slot_id' => array(
                'description' => __('广告位 ID', 'zibll-ad'),
                'type' => 'integer',
                'default' => 0,
                'sanitize_callback' => 'absint',
            ),
            'pay_status' => array(
                'description' => __('支付状态', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'payment_method' => array(
                'description' => __('支付方式', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_from' => array(
                'description' => __('开始日期 (created_at)', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_to' => array(
                'description' => __('结束日期 (created_at)', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'orderby' => array(
                'description' => __('排序字段', 'zibll-ad'),
                'type' => 'string',
                'default' => 'created_at',
                'enum' => array('id', 'created_at', 'paid_at', 'total_price'),
            ),
            'order' => array(
                'description' => __('排序方向', 'zibll-ad'),
                'type' => 'string',
                'default' => 'DESC',
                'enum' => array('ASC', 'DESC'),
            ),
        );
    }

    /**
     * 获取单个广告位
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function get_slot($request) {
        $slot_id = $request->get_param('id');

        $this->log_api_call('/slots/' . $slot_id, 'GET');

        $slot = Zibll_Ad_Slot_Model::get($slot_id);

        if (!$slot) {
            return $this->error_response(
                'slot_not_found',
                __('广告位不存在', 'zibll-ad'),
                404
            );
        }

        // 添加 units 统计和详细信息
        $slot['units_stats'] = $this->get_slot_units_stats($slot_id);
        $slot['units'] = Zibll_Ad_Unit_Model::get_by_slot($slot_id);

        return $this->success_response($slot);
    }

    /**
     * 创建广告位
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function create_slot($request) {
        $this->log_api_call('/slots', 'POST', $request->get_params());

        // 准备数据
        $data = $this->prepare_slot_data($request);

        // 默认购买须知：如未提供，使用插件设置中的默认值
        if ((!isset($data['purchase_notice']) || $data['purchase_notice'] === '') && function_exists('zibll_ad_get_option')) {
            $default_notice = zibll_ad_get_option('default_purchase_notice', '');
            if (!empty($default_notice)) {
                $data['purchase_notice'] = $default_notice;
            }
        }

        // 验证必需字段
        $validation = $this->validate_required_params($request, array('title', 'slot_type'));
        if (is_wp_error($validation)) {
            return $validation;
        }

        // 验证 slot_type
        $slot_type_validation = $this->validate_enum(
            $data['slot_type'],
            array('image', 'text'),
            'slot_type'
        );
        if (is_wp_error($slot_type_validation)) {
            return $slot_type_validation;
        }

        // 验证 display_layout
        if (isset($data['display_layout'])) {
            $layout_validation = $this->validate_display_layout($data['display_layout']);
            if (is_wp_error($layout_validation)) {
                return $layout_validation;
            }
        }

        // 创建广告位
        $slot_id = Zibll_Ad_Slot_Model::create($data);

        if (!$slot_id) {
            return $this->error_response(
                'create_failed',
                __('创建广告位失败', 'zibll-ad'),
                500
            );
        }

        // 处理 Widget 自动挂载
        if (isset($data['widget_bindings']) && !empty($data['widget_bindings'])) {
            $mount_result = $this->auto_mount_widgets($slot_id, array(), $data['widget_bindings']);
            if (is_wp_error($mount_result)) {
                // 挂载失败记录日志，但不影响创建成功
                zibll_ad_log('Widget auto-mount failed for slot ' . $slot_id, array(
                    'error' => $mount_result->get_error_message(),
                ));
            }
        }

        // 获取完整的 slot 数据返回
        $created_slot = Zibll_Ad_Slot_Model::get($slot_id);
        $created_slot['units_stats'] = $this->get_slot_units_stats($slot_id);

        return $this->success_response($created_slot, 201);
    }

    /**
     * 更新广告位
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function update_slot($request) {
        $slot_id = $request->get_param('id');

        $this->log_api_call('/slots/' . $slot_id, 'PUT', $request->get_params());

        // 检查广告位是否存在
        $old_slot = Zibll_Ad_Slot_Model::get($slot_id);
        if (!$old_slot) {
            return $this->error_response(
                'slot_not_found',
                __('广告位不存在', 'zibll-ad'),
                404
            );
        }

        // 准备更新数据
        $data = $this->prepare_slot_data($request, false);

        // 验证 slot_type（如果提供）
        if (isset($data['slot_type'])) {
            $slot_type_validation = $this->validate_enum(
                $data['slot_type'],
                array('image', 'text'),
                'slot_type'
            );
            if (is_wp_error($slot_type_validation)) {
                return $slot_type_validation;
            }
        }

        // 验证 display_layout（如果提供）
        if (isset($data['display_layout'])) {
            $layout_validation = $this->validate_display_layout($data['display_layout']);
            if (is_wp_error($layout_validation)) {
                return $layout_validation;
            }
        }

        // 检查 widget_bindings 变化
        $old_bindings = isset($old_slot['widget_bindings']) ? $old_slot['widget_bindings'] : array();
        $new_bindings = isset($data['widget_bindings']) ? $data['widget_bindings'] : $old_bindings;

        // 更新广告位
        $update_result = Zibll_Ad_Slot_Model::update($slot_id, $data);

        if (!$update_result) {
            return $this->error_response(
                'update_failed',
                __('更新广告位失败', 'zibll-ad'),
                500
            );
        }

        // 处理 Widget 自动挂载变化
        if ($old_bindings !== $new_bindings) {
            $mount_result = $this->auto_mount_widgets($slot_id, $old_bindings, $new_bindings);
            if (is_wp_error($mount_result)) {
                // 挂载失败记录日志，但不影响更新成功
                zibll_ad_log('Widget auto-mount update failed for slot ' . $slot_id, array(
                    'error' => $mount_result->get_error_message(),
                ));
            }
        }

        // 清除缓存
        zibll_ad_clear_slot_cache($slot_id);

        // 获取更新后的 slot 数据返回
        $updated_slot = Zibll_Ad_Slot_Model::get($slot_id);
        $updated_slot['units_stats'] = $this->get_slot_units_stats($slot_id);

        return $this->success_response($updated_slot);
    }

    /**
     * 删除广告位
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response|WP_Error
     */
    public function delete_slot($request) {
        $slot_id = $request->get_param('id');

        $this->log_api_call('/slots/' . $slot_id, 'DELETE');

        // 检查广告位是否存在
        $slot = Zibll_Ad_Slot_Model::get($slot_id);
        if (!$slot) {
            return $this->error_response(
                'slot_not_found',
                __('广告位不存在', 'zibll-ad'),
                404
            );
        }

        // 卸载所有 widget
        $old_bindings = isset($slot['widget_bindings']) ? $slot['widget_bindings'] : array();
        if (!empty($old_bindings)) {
            $this->auto_mount_widgets($slot_id, $old_bindings, array());
        }

        // 删除广告位
        $delete_result = Zibll_Ad_Slot_Model::delete($slot_id);

        if (!$delete_result) {
            return $this->error_response(
                'delete_failed',
                __('删除广告位失败', 'zibll-ad'),
                500
            );
        }

        return $this->success_response(array(
            'deleted' => true,
            'message' => __('广告位已删除', 'zibll-ad'),
        ));
    }

    /**
     * 获取可用的 sidebars
     *
     * @param WP_REST_Request $request 请求对象
     * @return WP_REST_Response
     */
    public function get_sidebars($request) {
        global $wp_registered_sidebars;

        $sidebars = array();

        foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
            $sidebars[] = array(
                'id' => $sidebar_id,
                'name' => $sidebar['name'],
                'description' => isset($sidebar['description']) ? $sidebar['description'] : '',
            );
        }

        return $this->success_response($sidebars);
    }

    /**
     * Widget 自动挂载/卸载逻辑（使用 Widget Manager）
     *
     * 此方法现在委托给专门的 Widget Manager 类处理
     * Widget Manager 提供了更完善的功能：
     * - 单例模式确保状态一致
     * - 事务性操作（支持回滚）
     * - 完善的验证和错误处理
     * - 详细的日志记录
     * - 钩子支持
     *
     * @param int   $slot_id      广告位 ID
     * @param array $old_bindings 旧的 sidebar 绑定
     * @param array $new_bindings 新的 sidebar 绑定
     * @return bool|WP_Error 成功返回 true，失败返回 WP_Error
     */
    private function auto_mount_widgets($slot_id, $old_bindings, $new_bindings) {
        // 使用 Widget Manager 处理挂载逻辑
        $widget_manager = Zibll_Ad_Widget_Manager::instance();
        return $widget_manager->auto_mount($slot_id, $old_bindings, $new_bindings);
    }

    /**
     * 获取 slot units 统计信息
     *
     * @param int $slot_id 广告位 ID
     * @return array 统计数据
     */
    private function get_slot_units_stats($slot_id) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
            FROM $table_units
            WHERE slot_id = %d
            GROUP BY status",
            $slot_id
        ), ARRAY_A);

        $stats_map = array(
            'available' => 0,
            'pending' => 0,
            'paid' => 0,
            'expired' => 0,
        );

        foreach ($stats as $stat) {
            $stats_map[$stat['status']] = intval($stat['count']);
        }

        // 基于 ends_at 的实时纠正：到期但尚未被清理任务置为 expired 的 paid 也不应计入"已售"
        $now_ts = time();
        $expired_paid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_units WHERE slot_id = %d AND status = 'paid' AND ends_at IS NOT NULL AND ends_at > 0 AND ends_at <= %d",
            $slot_id,
            $now_ts
        ));

        if ($expired_paid > 0) {
            $stats_map['paid'] = max(0, $stats_map['paid'] - $expired_paid);
            $stats_map['expired'] = $stats_map['expired'] + $expired_paid;
        }

        $stats_map['total'] = array_sum($stats_map);

        return $stats_map;
    }

    /**
     * 准备 slot 数据（从请求中提取）
     *
     * @param WP_REST_Request $request   请求对象
     * @param bool            $is_create 是否是创建操作
     * @return array 准备好的数据
     */
    private function prepare_slot_data($request, $is_create = true) {
        $data = array();

        // 深度思考：WordPress REST API 的参数处理机制
        // 1. has_param() 只检查参数是否存在，不检查值是否为空
        // 2. get_param() 会应用参数定义中的 sanitize_callback
        // 3. 对于数组类型，即使前端发送空数组，也应该被保存
        // 4. 关键修复：使用 get_param() 的第二个参数设置默认值，确保不会返回 null

        // 记录原始请求数据（用于调试）
        zibll_ad_log('prepare_slot_data called', array(
            'is_create' => $is_create,
            'all_params' => $request->get_params(),
            'body_params' => $request->get_body_params(),
        ));

        // 基础字段
        if ($request->has_param('title')) {
            $data['title'] = sanitize_text_field($request->get_param('title'));
        }

        if ($request->has_param('widget_title')) {
            $data['widget_title'] = sanitize_text_field($request->get_param('widget_title'));
        }

        if ($request->has_param('slot_type')) {
            $data['slot_type'] = sanitize_text_field($request->get_param('slot_type'));
        }

        if ($request->has_param('image_display_mode')) {
            $data['image_display_mode'] = sanitize_text_field($request->get_param('image_display_mode'));
            zibll_ad_log('image_display_mode extracted', $data['image_display_mode']);
        }

        if ($request->has_param('mount_type')) {
            $data['mount_type'] = sanitize_text_field($request->get_param('mount_type'));
            zibll_ad_log('mount_type extracted', $data['mount_type']);
        }

        if ($request->has_param('enabled')) {
            $data['enabled'] = (bool) $request->get_param('enabled');
        }

        if ($request->has_param('device_display')) {
            $data['device_display'] = sanitize_text_field($request->get_param('device_display'));
            zibll_ad_log('device_display extracted', $data['device_display']);
        }

        if ($request->has_param('sort_order')) {
            $data['sort_order'] = intval($request->get_param('sort_order'));
        }

        // 复杂字段（数组/对象）- 关键修复：使用 offsetExists 而不是 has_param
        // has_param 对于空数组可能返回 false，导致数据丢失
        $params = $request->get_params();

        if (array_key_exists('display_layout', $params)) {
            $data['display_layout'] = $request->get_param('display_layout');
            zibll_ad_log('display_layout extracted', $data['display_layout']);
        }

        if (array_key_exists('widget_bindings', $params)) {
            $bindings = $request->get_param('widget_bindings');
            $data['widget_bindings'] = is_array($bindings) ? $bindings : array();
            zibll_ad_log('widget_bindings extracted', $data['widget_bindings']);
        }

        if (array_key_exists('pricing_packages', $params)) {
            $packages = $request->get_param('pricing_packages');
            $data['pricing_packages'] = is_array($packages) ? $packages : array();
            zibll_ad_log('pricing_packages extracted', $data['pricing_packages']);
        }

        if (array_key_exists('pricing_single_month', $params)) {
            $data['pricing_single_month'] = floatval($request->get_param('pricing_single_month'));
            zibll_ad_log('pricing_single_month extracted', $data['pricing_single_month']);
        }

        // 幻灯片价格差异配置
        if ($request->has_param('carousel_price_diff_enabled')) {
            $data['carousel_price_diff_enabled'] = (bool) $request->get_param('carousel_price_diff_enabled');
        }
        if ($request->has_param('carousel_price_diff_type')) {
            $data['carousel_price_diff_type'] = sanitize_text_field($request->get_param('carousel_price_diff_type'));
        }
        if ($request->has_param('carousel_price_diff_amount')) {
            $data['carousel_price_diff_amount'] = floatval($request->get_param('carousel_price_diff_amount'));
        }

        if (array_key_exists('text_color_options', $params)) {
            $colors = $request->get_param('text_color_options');
            $data['text_color_options'] = is_array($colors) ? $colors : array();
            zibll_ad_log('text_color_options extracted', $data['text_color_options']);
        }

        if (array_key_exists('text_length_range', $params)) {
            $range = $request->get_param('text_length_range');
            $data['text_length_range'] = is_array($range) ? $range : array('min' => 2, 'max' => 8);
            zibll_ad_log('text_length_range extracted', $data['text_length_range']);
        }

        if (array_key_exists('image_aspect_ratio', $params)) {
            $ratio = $request->get_param('image_aspect_ratio');
            $data['image_aspect_ratio'] = is_array($ratio) ? $ratio : array('width' => 8, 'height' => 1);
            zibll_ad_log('image_aspect_ratio extracted', $data['image_aspect_ratio']);
        }

        if (array_key_exists('default_media', $params)) {
            $media = $request->get_param('default_media');
            $data['default_media'] = is_array($media) ? $media : array();
            zibll_ad_log('default_media extracted', $data['default_media']);
        }

        if (array_key_exists('purchase_notice', $params)) {
            $data['purchase_notice'] = sanitize_textarea_field($request->get_param('purchase_notice'));
            zibll_ad_log('purchase_notice extracted', $data['purchase_notice']);
        }

        if (array_key_exists('payment_methods_override', $params)) {
            $methods = $request->get_param('payment_methods_override');
            $data['payment_methods_override'] = is_array($methods) ? $methods : array();
            zibll_ad_log('payment_methods_override extracted', $data['payment_methods_override']);
        }

        // 记录最终准备的数据
        zibll_ad_log('prepare_slot_data result', $data);

        return $data;
    }

    /**
     * 验证 display_layout
     *
     * @param mixed $layout 布局数据
     * @return bool|WP_Error
     */
    private function validate_display_layout($layout) {
        if (!is_array($layout)) {
            return $this->error_response(
                'invalid_display_layout',
                __('display_layout 必须是对象', 'zibll-ad'),
                400
            );
        }

        // 验证 max_items
        if (isset($layout['max_items'])) {
            $max_items = intval($layout['max_items']);
            if ($max_items <= 0) {
                return $this->error_response(
                    'invalid_max_items',
                    __('max_items 必须大于 0', 'zibll-ad'),
                    400
                );
            }
        }

        // 验证 per_row
        if (isset($layout['per_row'])) {
            $per_row = intval($layout['per_row']);
            if ($per_row < 1 || $per_row > 8) {
                return $this->error_response(
                    'invalid_per_row',
                    __('per_row 必须在 1-8 之间', 'zibll-ad'),
                    400
                );
            }
        }

        return true;
    }

    /**
     * 获取创建广告位的参数定义
     *
     * @return array 参数定义
     */
    private function get_slot_create_args() {
        return array(
            'title' => array(
                'description' => __('广告位名称', 'zibll-ad'),
                'type' => 'string',
                'required' => true,
                'sanitize_callback' => array($this, 'sanitize_text_param'),
            ),
            'widget_title' => array(
                'description' => __('Widget 标题', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => array($this, 'sanitize_text_param'),
            ),
            'slot_type' => array(
                'description' => __('广告位类型', 'zibll-ad'),
                'type' => 'string',
                'required' => true,
                'enum' => array('image', 'text'),
            ),
            'enabled' => array(
                'description' => __('是否启用', 'zibll-ad'),
                'type' => 'boolean',
                'default' => true,
            ),
            'device_display' => array(
                'description' => __('显示终端', 'zibll-ad'),
                'type' => 'string',
                'default' => 'all',
                'enum' => array('all', 'pc', 'mobile'),
            ),
            'sort_order' => array(
                'description' => __('排序', 'zibll-ad'),
                'type' => 'integer',
                'default' => 0,
            ),
            'display_layout' => array(
                'description' => __('布局配置', 'zibll-ad'),
                'type' => 'object',
                'default' => array(
                    'rows' => 1,
                    'per_row' => 3,
                    'max_items' => 3,
                ),
            ),
            'widget_bindings' => array(
                'description' => __('Widget 绑定', 'zibll-ad'),
                'type' => 'array',
                'default' => array(),
                'items' => array('type' => 'string'),
            ),
            'pricing_packages' => array(
                'description' => __('定价套餐', 'zibll-ad'),
                'type' => 'array',
                'default' => array(),
            ),
            'pricing_single_month' => array(
                'description' => __('单月价格', 'zibll-ad'),
                'type' => 'number',
                'default' => 0,
            ),
            'text_color_options' => array(
                'description' => __('文字颜色选项', 'zibll-ad'),
                'type' => 'array',
                'default' => array(),
            ),
            'text_length_range' => array(
                'description' => __('文字长度范围', 'zibll-ad'),
                'type' => 'object',
                'default' => array(
                    'min' => 2,
                    'max' => 8,
                ),
            ),
            'default_media' => array(
                'description' => __('默认展示内容', 'zibll-ad'),
                'type' => 'object',
                'default' => array(),
            ),
            'purchase_notice' => array(
                'description' => __('购买须知', 'zibll-ad'),
                'type' => 'string',
                'default' => '',
                'sanitize_callback' => array($this, 'sanitize_textarea_param'),
            ),
            'payment_methods_override' => array(
                'description' => __('支付方式限制', 'zibll-ad'),
                'type' => 'array',
                'default' => array(),
            ),
        );
    }

    /**
     * 获取更新广告位的参数定义
     *
     * @return array 参数定义
     */
    private function get_slot_update_args() {
        // ✨ 关键修复：更新操作不应该有默认值！
        // 默认值会导致缺失的参数被自动填充，从而覆盖数据库中的现有值
        return array(
            'id' => array(
                'description' => __('广告位 ID', 'zibll-ad'),
                'type' => 'integer',
                'required' => true,
            ),
            'title' => array(
                'description' => __('广告位名称', 'zibll-ad'),
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => array($this, 'sanitize_text_param'),
            ),
            'widget_title' => array(
                'description' => __('Widget 标题', 'zibll-ad'),
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => array($this, 'sanitize_text_param'),
            ),
            'slot_type' => array(
                'description' => __('广告位类型', 'zibll-ad'),
                'type' => 'string',
                'required' => false,
                'enum' => array('image', 'text'),
            ),
            'enabled' => array(
                'description' => __('是否启用', 'zibll-ad'),
                'type' => 'boolean',
                'required' => false,
                // ❌ 移除 default: true
            ),
            'device_display' => array(
                'description' => __('显示终端', 'zibll-ad'),
                'type' => 'string',
                'required' => false,
                'enum' => array('all', 'pc', 'mobile'),
                // ❌ 移除 default
            ),
            'sort_order' => array(
                'description' => __('排序', 'zibll-ad'),
                'type' => 'integer',
                'required' => false,
                // ❌ 移除 default: 0
            ),
            'display_layout' => array(
                'description' => __('布局配置', 'zibll-ad'),
                'type' => 'object',
                'required' => false,
                // ❌ 移除 default
            ),
            'widget_bindings' => array(
                'description' => __('Widget 绑定', 'zibll-ad'),
                'type' => 'array',
                'required' => false,
                'items' => array('type' => 'string'),
                // ❌ 移除 default: array()
            ),
            'pricing_packages' => array(
                'description' => __('定价套餐', 'zibll-ad'),
                'type' => 'array',
                'required' => false,
                // ❌ 移除 default: array()
            ),
            'pricing_single_month' => array(
                'description' => __('单月价格', 'zibll-ad'),
                'type' => 'number',
                'required' => false,
                // ❌ 移除 default: 0
            ),
            'text_color_options' => array(
                'description' => __('文字颜色选项', 'zibll-ad'),
                'type' => 'array',
                'required' => false,
                // ❌ 移除 default: array()
            ),
            'text_length_range' => array(
                'description' => __('文字长度范围', 'zibll-ad'),
                'type' => 'object',
                'required' => false,
                // ❌ 移除 default
            ),
            'default_media' => array(
                'description' => __('默认展示内容', 'zibll-ad'),
                'type' => 'object',
                'required' => false,
                // ❌ 移除 default: array()
            ),
            'purchase_notice' => array(
                'description' => __('购买须知', 'zibll-ad'),
                'type' => 'string',
                'required' => false,
                'sanitize_callback' => array($this, 'sanitize_textarea_param'),
                // ❌ 移除 default: ''
            ),
            'payment_methods_override' => array(
                'description' => __('支付方式限制', 'zibll-ad'),
                'type' => 'array',
                'required' => false,
                // ❌ 移除 default: array()
            ),
        );
    }
}
