<?php
/**
 * 广告单元数据模型类
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Unit_Model {

    /**
     * 获取单个广告单元
     *
     * @param int $unit_id 单元 ID
     * @return array|null 单元数据，失败返回 null
     */
    public static function get($unit_id) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $unit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_units WHERE id = %d",
            $unit_id
        ), ARRAY_A);

        return $unit ? $unit : null;
    }

    /**
     * 根据 slot_id 和 unit_key 获取单元
     *
     * @param int $slot_id  广告位 ID
     * @param int $unit_key 单元键
     * @return array|null 单元数据，失败返回 null
     */
    public static function get_by_slot_and_key($slot_id, $unit_key) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $unit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_units WHERE slot_id = %d AND unit_key = %d",
            $slot_id,
            $unit_key
        ), ARRAY_A);

        return $unit ? $unit : null;
    }

    /**
     * 创建广告单元
     *
     * @param array $data 单元数据
     * @return int|false 成功返回单元 ID，失败返回 false
     */
    public static function create($data) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $defaults = array(
            'status' => 'available',
            'created_at' => current_time('mysql'),
        );

        $data = wp_parse_args($data, $defaults);

        $result = $wpdb->insert($table_units, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * 更新广告单元
     *
     * @param int   $unit_id 单元 ID
     * @param array $data    更新数据
     * @return bool 是否成功
     */
    public static function update($unit_id, $data) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        // 自动更新 updated_at
        $data['updated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $table_units,
            $data,
            array('id' => $unit_id),
            null,
            array('%d')
        );

        return $result !== false;
    }

    /**
     * 锁定单元为 pending 状态
     *
     * @param int $slot_id         广告位 ID
     * @param int $unit_key        单元键
     * @param int $expires_minutes 过期时间（分钟）
     * @return bool 是否成功
     */
    public static function set_pending($slot_id, $unit_key, $expires_minutes = 30) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $unit = self::get_by_slot_and_key($slot_id, $unit_key);

        if (!$unit) {
            return false;
        }

        // 检查当前状态是否允许锁定
        if ($unit['status'] !== 'available') {
            // 如果是 pending 状态，检查是否超时
            if ($unit['status'] === 'pending') {
                $current_time = time();
                if ($unit['pending_expires_at'] && $unit['pending_expires_at'] < $current_time) {
                    // 已超时，可以重新锁定
                } else {
                    // 未超时，不能锁定
                    return false;
                }
            } else {
                // 其他状态（paid, expired）不能锁定
                return false;
            }
        }

        $pending_expires_at = time() + ($expires_minutes * 60);

        $result = $wpdb->update(
            $table_units,
            array(
                'status' => 'pending',
                'pending_expires_at' => $pending_expires_at,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $unit['id']),
            array('%s', '%d', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * 设置单元为 paid 状态并填充广告内容
     *
     * @param int   $unit_id         单元 ID
     * @param array $ad_data         广告数据
     * @param int   $duration_months 投放时长（月）
     * @return bool 是否成功
     */
    public static function set_paid($unit_id, $ad_data, $duration_months) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $starts_at = time();
        $ends_at = $starts_at + ($duration_months * 30 * 24 * 3600);

        $update_data = array(
            'status' => 'paid',
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
            'pending_expires_at' => null,
            'updated_at' => current_time('mysql'),
        );

        // 填充广告内容字段
        $ad_fields = array(
            'customer_name',
            'website_name',
            'website_url',
            'contact_type',
            'contact_value',
            'color_key',
            'image_id',
            'image_url',
            'text_content',
            'target_url',
            'order_id',
            'order_num',
            'price',
            'duration_months',
        );

        foreach ($ad_fields as $field) {
            if (isset($ad_data[$field])) {
                $update_data[$field] = $ad_data[$field];
            }
        }

        $result = $wpdb->update(
            $table_units,
            $update_data,
            array('id' => $unit_id),
            null,
            array('%d')
        );

        // 清除缓存
        $unit = self::get($unit_id);
        if ($unit && function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($unit['slot_id']);
        }

        return $result !== false;
    }

    /**
     * 设置单元为 expired 状态
     *
     * @param int $unit_id 单元 ID
     * @return bool 是否成功
     */
    public static function set_expired($unit_id) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $result = $wpdb->update(
            $table_units,
            array(
                'status' => 'expired',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $unit_id),
            array('%s', '%s'),
            array('%d')
        );

        // 清除缓存
        $unit = self::get($unit_id);
        if ($unit && function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($unit['slot_id']);
        }

        return $result !== false;
    }

    /**
     * 设置单元为 available 状态（释放/重置）
     *
     * @param int  $unit_id      单元 ID
     * @param bool $clear_content 是否清空广告内容
     * @return bool 是否成功
     */
    public static function set_available($unit_id, $clear_content = true) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $update_data = array(
            'status' => 'available',
            'pending_expires_at' => null,
            'updated_at' => current_time('mysql'),
        );

        // 清空广告内容
        if ($clear_content) {
            $clear_fields = array(
                'customer_name',
                'website_name',
                'website_url',
                'contact_type',
                'contact_value',
                'color_key',
                'image_id',
                'image_url',
                'text_content',
                'target_url',
                'order_id',
                'order_num',
                'starts_at',
                'ends_at',
            );

            foreach ($clear_fields as $field) {
                $update_data[$field] = null;
            }

            $update_data['price'] = 0;
            $update_data['duration_months'] = 0;
        }

        $result = $wpdb->update(
            $table_units,
            $update_data,
            array('id' => $unit_id),
            null,
            array('%d')
        );

        // 清除缓存
        $unit = self::get($unit_id);
        if ($unit && function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($unit['slot_id']);
        }

        return $result !== false;
    }

    /**
     * 清理超时的 pending 单元
     *
     * @return int 清理的数量
     */
    public static function cleanup_expired_pending() {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';
        $current_time = time();

        // 查找超时的 pending 单元
        $expired_units = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $table_units
            WHERE status = 'pending'
            AND pending_expires_at IS NOT NULL
            AND pending_expires_at < %d",
            $current_time
        ), ARRAY_A);

        $count = 0;

        foreach ($expired_units as $unit) {
            if (self::set_available($unit['id'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 获取某个 slot 的所有单元
     *
     * @param int   $slot_id 广告位 ID
     * @param array $args    查询参数
     * @return array 单元列表
     */
    public static function get_by_slot($slot_id, $args = array()) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        $where = $wpdb->prepare("WHERE slot_id = %d", $slot_id);

        // 支持状态筛选
        if (isset($args['status'])) {
            $where .= $wpdb->prepare(" AND status = %s", $args['status']);
        }

        // 排序
        $orderby = isset($args['orderby']) ? $args['orderby'] : 'unit_key';
        $order = isset($args['order']) ? $args['order'] : 'ASC';

        $units = $wpdb->get_results(
            "SELECT * FROM $table_units $where ORDER BY $orderby $order",
            ARRAY_A
        );

        return $units ? $units : array();
    }

    /**
     * 删除单元
     *
     * @param int $unit_id 单元 ID
     * @return bool 是否成功
     */
    public static function delete($unit_id) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        // 获取单元信息用于清除缓存
        $unit = self::get($unit_id);

        $result = $wpdb->delete(
            $table_units,
            array('id' => $unit_id),
            array('%d')
        );

        // 清除缓存
        if ($unit && function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($unit['slot_id']);
        }

        return $result !== false;
    }
}
