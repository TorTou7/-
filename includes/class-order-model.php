<?php
/**
 * 订单数据模型类
 *
 * 封装订单查询、详情、手动下架等后台管理所需的方法。
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Order_Model {

    /**
     * 查询订单列表（带过滤、排序、分页）
     *
     * 支持过滤：keyword、slot_id、pay_status、payment_method、date_from、date_to
     * 支持排序：orderby（id|created_at|paid_at|total_price），order（ASC|DESC）
     *
     * @param array $args
     * @return array { orders: [], total: int }
     */
    public static function query_orders($args = array()) {
        global $wpdb;

        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $table_units  = $wpdb->prefix . 'zibll_ad_units';
        $table_posts  = $wpdb->posts;
        $table_users  = $wpdb->users;

        $defaults = array(
            'page'           => 1,
            'per_page'       => 20,
            'keyword'        => '',
            'slot_id'        => 0,
            'pay_status'     => '',
            'payment_method' => '',
            'date_from'      => '', // created_at >= date_from
            'date_to'        => '', // created_at <= date_to
            'orderby'        => 'created_at',
            'order'          => 'DESC',
        );

        $args = wp_parse_args($args, $defaults);

        $page     = max(1, absint($args['page']));
        $per_page = max(1, absint($args['per_page']));

        $where   = array();
        $params  = array();

        $where[] = '1=1';

        if (!empty($args['keyword'])) {
            $kw = '%' . $wpdb->esc_like($args['keyword']) . '%';
            $where[] = "(o.zibpay_order_num LIKE %s
                OR u.customer_name LIKE %s
                OR u.website_name LIKE %s
                OR u.website_url LIKE %s
                OR usr.display_name LIKE %s
                OR usr.user_login LIKE %s)";
            array_push($params, $kw, $kw, $kw, $kw, $kw, $kw);
        }

        if (!empty($args['slot_id'])) {
            $where[] = 'o.slot_id = %d';
            $params[] = absint($args['slot_id']);
        }

        if (!empty($args['pay_status'])) {
            $where[] = 'o.pay_status = %s';
            $params[] = sanitize_text_field($args['pay_status']);
        }

        if (!empty($args['payment_method'])) {
            $where[] = 'o.payment_method = %s';
            $params[] = sanitize_text_field($args['payment_method']);
        }

        if (!empty($args['date_from'])) {
            $where[] = 'o.created_at >= %s';
            $params[] = sanitize_text_field($args['date_from']);
        }
        if (!empty($args['date_to'])) {
            $where[] = 'o.created_at <= %s';
            $params[] = sanitize_text_field($args['date_to']);
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $allowed_orderby = array('id', 'created_at', 'paid_at', 'total_price');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql_total = "SELECT COUNT(*) FROM {$table_orders} o
            LEFT JOIN {$table_units} u ON u.id = o.unit_id
            {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($sql_total, $params));

        $offset = ($page - 1) * $per_page;

        $sql = "SELECT 
                o.*,
                u.unit_key,
                u.status AS unit_status,
                u.starts_at,
                u.ends_at,
                u.price AS unit_price,
                u.customer_name,
                u.website_name,
                u.website_url,
                usr.display_name AS user_display_name,
                usr.user_login AS user_login,
                p.post_title AS slot_title
            FROM {$table_orders} o
            LEFT JOIN {$table_units} u ON u.id = o.unit_id
            LEFT JOIN {$table_users} usr ON usr.ID = o.user_id
            LEFT JOIN {$table_posts} p ON p.ID = o.slot_id
            {$where_sql}
            ORDER BY o.{$orderby} {$order}
            LIMIT %d OFFSET %d";

        $query_params = $params;
        $query_params[] = $per_page;
        $query_params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $query_params), ARRAY_A);

        $orders = array();
        foreach ($rows as $row) {
            $orders[] = self::normalize_order_row($row);
        }

        return array(
            'orders' => $orders,
            'total'  => $total,
        );
    }

    /**
     * 获取订单详情
     *
     * @param int $order_id
     * @return array|null
     */
    public static function get($order_id) {
        global $wpdb;

        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $table_units  = $wpdb->prefix . 'zibll_ad_units';
        $table_posts  = $wpdb->posts;
        $table_users  = $wpdb->users;

        $sql = "SELECT 
                o.*,
                u.unit_key,
                u.status AS unit_status,
                u.starts_at,
                u.ends_at,
                u.price AS unit_price,
                u.customer_name,
                u.website_name,
                u.website_url,
                u.target_url,
                u.color_key,
                u.image_id,
                u.image_url,
                u.text_content,
                usr.display_name AS user_display_name,
                usr.user_login AS user_login,
                p.post_title AS slot_title
            FROM {$table_orders} o
            LEFT JOIN {$table_units} u ON u.id = o.unit_id
            LEFT JOIN {$table_users} usr ON usr.ID = o.user_id
            LEFT JOIN {$table_posts} p ON p.ID = o.slot_id
            WHERE o.id = %d";

        $row = $wpdb->get_row($wpdb->prepare($sql, absint($order_id)), ARRAY_A);

        if (!$row) {
            return null;
        }

        return self::normalize_order_row($row, true);
    }

    /**
     * 手动下架订单（提前结束投放）
     *
     * 规则：
     * - 仅允许对已支付且对应单元仍为 paid 的订单执行
     * - 将单元状态置为 available（清空内容）
     * - 更新订单 pay_status = 'takedown'，closed_at = now
     *
     * @param int $order_id
     * @return bool|WP_Error
     */
    public static function takedown($order_id) {
        global $wpdb;

        $order = self::get($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', __('订单不存在', 'zibll-ad'));
        }

        if ($order['pay_status'] !== 'paid') {
            return new WP_Error('invalid_status', __('仅允许对“已支付”的订单进行下架', 'zibll-ad'));
        }

        if (empty($order['unit_id'])) {
            return new WP_Error('invalid_order', __('订单缺少投放单元信息', 'zibll-ad'));
        }

        // 单元必须处于 paid 状态
        if ($order['unit_status'] !== 'paid') {
            return new WP_Error('invalid_unit_status', __('当前投放单元不在投放中，无需下架', 'zibll-ad'));
        }

        // 下架：重置单元为 available
        $ok = Zibll_Ad_Unit_Model::set_available($order['unit_id'], true);
        if (!$ok) {
            return new WP_Error('takedown_failed', __('下架失败，请重试', 'zibll-ad'));
        }

        // 更新订单状态
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $wpdb->update(
            $table_orders,
            array(
                'pay_status' => 'takedown',
                'closed_at'  => current_time('mysql'),
            ),
            array('id' => absint($order_id)),
            array('%s', '%s'),
            array('%d')
        );

        // 清除缓存
        if (!empty($order['slot_id']) && function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($order['slot_id']);
        }

        return true;
    }

    /**
     * 删除订单
     *
     * 注意：删除订单记录不会影响广告位状态
     * 🔧 修复：记录已删除订单的 zibpay_order_id，防止对账补录任务恢复
     *
     * @param int $order_id 订单 ID
     * @return bool|WP_Error 成功返回 true，失败返回 WP_Error
     */
    public static function delete($order_id) {
        global $wpdb;

        $order = self::get($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', __('订单不存在', 'zibll-ad'));
        }

        // 🔧 记录 zibpay_order_id，防止对账补录任务恢复此订单
        $zibpay_order_id = isset($order['zibpay_order_id']) ? intval($order['zibpay_order_id']) : 0;
        if ($zibpay_order_id > 0) {
            $deleted_order_ids = get_option('zibll_ad_deleted_zibpay_orders', array());
            if (!is_array($deleted_order_ids)) {
                $deleted_order_ids = array();
            }
            
            // 添加到已删除列表（去重）
            if (!in_array($zibpay_order_id, $deleted_order_ids, true)) {
                $deleted_order_ids[] = $zibpay_order_id;
                
                // 限制列表大小，保留最近 1000 条（避免无限增长）
                if (count($deleted_order_ids) > 1000) {
                    $deleted_order_ids = array_slice($deleted_order_ids, -1000, 1000, true);
                }
                
                update_option('zibll_ad_deleted_zibpay_orders', $deleted_order_ids, false);
            }
        }

        // 删除订单记录
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $deleted = $wpdb->delete(
            $table_orders,
            array('id' => absint($order_id)),
            array('%d')
        );

        if ($deleted === false) {
            return new WP_Error('delete_failed', __('删除失败，请重试', 'zibll-ad'));
        }

        // 记录日志
        zibll_ad_log('订单已删除', array(
            'order_id' => $order_id,
            'zibpay_order_id' => $zibpay_order_id,
            'zibpay_order_num' => $order['zibpay_order_num'] ?? '',
        ));

        return true;
    }

    /**
     * 统计各状态数量（用于列表顶部统计）
     *
     * @param array $filters 与 query_orders 相同过滤参数（忽略 pay_status）
     * @return array 形如 [ 'paid' => 10, 'refunded' => 2, 'takedown' => 1, 'pending' => 3 ]
     */
    public static function count_by_status($filters = array()) {
        global $wpdb;

        $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        $table_units  = $wpdb->prefix . 'zibll_ad_units';
        $table_users  = $wpdb->users;

        $where  = array('1=1');
        $params = array();

        // 关键词
        if (!empty($filters['keyword'])) {
            $kw = '%' . $wpdb->esc_like($filters['keyword']) . '%';
            $where[] = "(o.zibpay_order_num LIKE %s
                OR u.customer_name LIKE %s
                OR u.website_name LIKE %s
                OR u.website_url LIKE %s
                OR usr.display_name LIKE %s
                OR usr.user_login LIKE %s)";
            array_push($params, $kw, $kw, $kw, $kw, $kw, $kw);
        }

        if (!empty($filters['slot_id'])) {
            $where[] = 'o.slot_id = %d';
            $params[] = absint($filters['slot_id']);
        }

        if (!empty($filters['payment_method'])) {
            $where[] = 'o.payment_method = %s';
            $params[] = sanitize_text_field($filters['payment_method']);
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'o.created_at >= %s';
            $params[] = sanitize_text_field($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'o.created_at <= %s';
            $params[] = sanitize_text_field($filters['date_to']);
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT o.pay_status, COUNT(*) AS cnt
            FROM {$table_orders} o
            LEFT JOIN {$table_units} u ON u.id = o.unit_id
            LEFT JOIN {$table_users} usr ON usr.ID = o.user_id
            {$where_sql}
            GROUP BY o.pay_status";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $result = array();
        foreach ($rows as $row) {
            $result[$row['pay_status']] = intval($row['cnt']);
        }

        return $result;
    }

    /**
     * 规范化订单行：反序列化部分字段，格式化数值
     *
     * @param array $row
     * @param bool $include_snapshot 返回 customer_snapshot 解析
     * @return array
     */
    private static function normalize_order_row($row, $include_snapshot = false) {
        global $wpdb;

        // 价格字段规范化
        $row['base_price']  = isset($row['base_price']) ? (float) $row['base_price'] : 0.0;
        $row['color_price'] = isset($row['color_price']) ? (float) $row['color_price'] : 0.0;
        $row['total_price'] = isset($row['total_price']) ? (float) $row['total_price'] : 0.0;

        // 解析快照（用于回退显示）
        $snapshot = array();
        if (isset($row['customer_snapshot'])) {
            $snapshot_val = maybe_unserialize($row['customer_snapshot']);
            if (is_array($snapshot_val)) {
                $snapshot = $snapshot_val;
            }
        }
        if ($include_snapshot) {
            $row['customer_snapshot'] = $snapshot;
        } else {
            unset($row['customer_snapshot']);
        }

        // 计算衍生字段（基于时间的状态纠正）
        $ends_ts = isset($row['ends_at']) ? intval($row['ends_at']) : 0;
        $now_ts  = time();

        // 如果数据库仍为 paid 但已过期，则在展示层纠正为 expired
        if (isset($row['unit_status']) && $row['unit_status'] === 'paid' && $ends_ts && $ends_ts > 0 && $ends_ts <= $now_ts) {
            $row['unit_status'] = 'expired';
        }

        // is_active 仅在未过期的 paid 情况下为 true
        $row['is_active'] = (isset($row['unit_status']) && $row['unit_status'] === 'paid');

        // 规范化用户显示名
        if (!empty($row['user_id'])) {
            $name = '';
            if (!empty($row['user_display_name'])) {
                $name = $row['user_display_name'];
            } elseif (!empty($row['user_login'])) {
                $name = $row['user_login'];
            } else {
                $u = get_userdata(intval($row['user_id']));
                if ($u) {
                    $name = $u->display_name ? $u->display_name : $u->user_login;
                }
            }
            $row['user_name'] = $name !== '' ? $name : ('用户#' . intval($row['user_id']));
        } else {
            $row['user_name'] = '未登录用户';
        }

        // 使用下单快照回退展示广告内容（当单元被清空或字段为空时）
        $ad = (isset($snapshot['ad_data']) && is_array($snapshot['ad_data'])) ? $snapshot['ad_data'] : array();
        if (empty($row['customer_name'])) {
            $row['customer_name'] = isset($snapshot['customer_name']) ? $snapshot['customer_name'] : (isset($ad['customer_name']) ? $ad['customer_name'] : (isset($ad['website_name']) ? $ad['website_name'] : ''));
        }
        if (empty($row['website_name'])) {
            $row['website_name'] = isset($snapshot['website_name']) ? $snapshot['website_name'] : (isset($ad['website_name']) ? $ad['website_name'] : '');
        }
        if (empty($row['website_url'])) {
            $row['website_url'] = isset($snapshot['website_url']) ? $snapshot['website_url'] : (isset($ad['website_url']) ? $ad['website_url'] : '');
        }
        if (empty($row['contact_type'])) {
            $row['contact_type'] = isset($snapshot['contact_type']) ? $snapshot['contact_type'] : (isset($ad['contact_type']) ? $ad['contact_type'] : '');
        }
        if (empty($row['contact_value'])) {
            $row['contact_value'] = isset($snapshot['contact_value']) ? $snapshot['contact_value'] : (isset($ad['contact_value']) ? $ad['contact_value'] : '');
        }

        // 获取广告位的per_row配置
        if (!empty($row['slot_id'])) {
            $display_layout = get_post_meta(intval($row['slot_id']), 'display_layout', true);
            if (is_array($display_layout) && isset($display_layout['per_row'])) {
                $row['slot_per_row'] = intval($display_layout['per_row']);
            } else {
                $row['slot_per_row'] = 3; // 默认值
            }

            // 计算unit在数组中的实际索引位置
            if (isset($row['unit_key'])) {
                $table_units = $wpdb->prefix . 'zibll_ad_units';
                $unit_index = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_units}
                    WHERE slot_id = %d AND unit_key < %d
                    ORDER BY unit_key ASC",
                    intval($row['slot_id']),
                    intval($row['unit_key'])
                ));
                $row['unit_index'] = $unit_index;
            }
        } else {
            $row['slot_per_row'] = 3; // 默认值
            $row['unit_index'] = 0;
        }

        return $row;
    }
}
