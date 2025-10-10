<?php
/**
 * 广告位数据模型类
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Slot_Model {

    /**
     * 获取单个广告位
     *
     * @param int $slot_id 广告位 ID
     * @return array|null 广告位数据（包含 post 和 meta），失败返回 null
     */
    public static function get($slot_id) {
        $post = get_post($slot_id);

        if (!$post || $post->post_type !== 'zibll_ad_slot') {
            return null;
        }

        // 获取所有 meta 数据
        $meta = get_post_meta($slot_id);

        // 🔍 调试：记录原始 meta 数据
        zibll_ad_log("Slot_Model::get - 原始meta数据 for slot {$slot_id}", array(
            'meta_keys' => array_keys($meta),
            'pricing_single_month_raw' => isset($meta['pricing_single_month'][0]) ? $meta['pricing_single_month'][0] : 'NOT_SET',
            'pricing_packages_raw' => isset($meta['pricing_packages'][0]) ? $meta['pricing_packages'][0] : 'NOT_SET',
            'widget_bindings_raw' => isset($meta['widget_bindings'][0]) ? $meta['widget_bindings'][0] : 'NOT_SET',
        ));

        // 解析 meta 数据（可能是序列化的）
        $slot_data = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'widget_title' => isset($meta['widget_title'][0]) ? $meta['widget_title'][0] : '',
            'slot_type' => isset($meta['slot_type'][0]) ? $meta['slot_type'][0] : 'image',
            'image_display_mode' => isset($meta['image_display_mode'][0]) ? $meta['image_display_mode'][0] : 'grid',
            'mount_type' => isset($meta['mount_type'][0]) ? $meta['mount_type'][0] : 'widget',
            'device_display' => isset($meta['device_display'][0]) ? $meta['device_display'][0] : 'all',
            'display_layout' => isset($meta['display_layout'][0]) ? maybe_unserialize($meta['display_layout'][0]) : array(
                'rows' => 1,
                'per_row' => 3,
                'max_items' => 3,
                'carousel_count' => 3,
            ),
            'widget_bindings' => isset($meta['widget_bindings'][0]) ? maybe_unserialize($meta['widget_bindings'][0]) : array(),
            'pricing_packages' => isset($meta['pricing_packages'][0]) ? maybe_unserialize($meta['pricing_packages'][0]) : array(),
            'pricing_single_month' => isset($meta['pricing_single_month'][0]) ? floatval($meta['pricing_single_month'][0]) : 0,
            'text_color_options' => isset($meta['text_color_options'][0]) ? maybe_unserialize($meta['text_color_options'][0]) : array(),
            'text_length_range' => isset($meta['text_length_range'][0]) ? maybe_unserialize($meta['text_length_range'][0]) : array(
                'min' => 2,
                'max' => 8,
            ),
            'image_aspect_ratio' => isset($meta['image_aspect_ratio'][0]) ? maybe_unserialize($meta['image_aspect_ratio'][0]) : array(
                'width' => 8,
                'height' => 1,
            ),
            'default_media' => isset($meta['default_media'][0]) ? maybe_unserialize($meta['default_media'][0]) : array(),
            'purchase_notice' => isset($meta['purchase_notice'][0]) ? $meta['purchase_notice'][0] : '',
            'payment_methods_override' => isset($meta['payment_methods_override'][0]) ? maybe_unserialize($meta['payment_methods_override'][0]) : array(),
            'carousel_price_diff_enabled' => isset($meta['carousel_price_diff_enabled'][0]) ? (bool) $meta['carousel_price_diff_enabled'][0] : false,
            'carousel_price_diff_type' => isset($meta['carousel_price_diff_type'][0]) ? $meta['carousel_price_diff_type'][0] : 'decrement',
            'carousel_price_diff_amount' => isset($meta['carousel_price_diff_amount'][0]) ? floatval($meta['carousel_price_diff_amount'][0]) : 0,
            'sort_order' => isset($meta['sort_order'][0]) ? intval($meta['sort_order'][0]) : 0,
            'enabled' => isset($meta['enabled'][0]) ? (bool) $meta['enabled'][0] : true,
        );

        // 🔍 调试：记录解析后的数据
        zibll_ad_log("Slot_Model::get - 解析后数据 for slot {$slot_id}", array(
            'pricing_single_month' => $slot_data['pricing_single_month'],
            'pricing_packages' => $slot_data['pricing_packages'],
            'widget_bindings' => $slot_data['widget_bindings'],
            'enabled' => $slot_data['enabled'],
        ));

        return $slot_data;
    }

    /**
     * 创建广告位
     *
     * @param array $data 广告位数据
     * @return int|false 成功返回 post_id，失败返回 false
     */
    public static function create($data) {
        // 创建 post
        $post_data = array(
            'post_type' => 'zibll_ad_slot',
            'post_title' => isset($data['title']) ? sanitize_text_field($data['title']) : __('新广告位', 'zibll-ad'),
            'post_status' => 'publish',
        );

        $slot_id = wp_insert_post($post_data);

        if (is_wp_error($slot_id) || !$slot_id) {
            return false;
        }

        // 更新 meta 数据
        self::update_meta($slot_id, $data);

        // 根据 max_items 初始化 units
        $layout = isset($data['display_layout']) ? $data['display_layout'] : array();
        $max_items = isset($layout['max_items']) ? intval($layout['max_items']) : 3;

        self::init_units($slot_id, $max_items);

        return $slot_id;
    }

    /**
     * 更新广告位
     *
     * @param int   $slot_id 广告位 ID
     * @param array $data    广告位数据
     * @return bool 是否成功
     */
    public static function update($slot_id, $data) {
        $post = get_post($slot_id);

        if (!$post || $post->post_type !== 'zibll_ad_slot') {
            return false;
        }

        // 更新 post 标题
        if (isset($data['title'])) {
            wp_update_post(array(
                'ID' => $slot_id,
                'post_title' => sanitize_text_field($data['title']),
            ));
        }

        // 更新 meta 数据
        self::update_meta($slot_id, $data);

        // 检查是否需要调整 units 数量
        if (isset($data['display_layout']['max_items'])) {
            $max_items = intval($data['display_layout']['max_items']);
            self::adjust_units($slot_id, $max_items);
        }

        return true;
    }

    /**
     * 删除广告位
     *
     * @param int $slot_id 广告位 ID
     * @return bool 是否成功
     */
    public static function delete($slot_id) {
        global $wpdb;

        $post = get_post($slot_id);

        if (!$post || $post->post_type !== 'zibll_ad_slot') {
            return false;
        }

        // 删除关联的 units
        $table_units = $wpdb->prefix . 'zibll_ad_units';
        $wpdb->delete($table_units, array('slot_id' => $slot_id), array('%d'));

        // 注意：保留关联的 orders 作为历史记录，不随广告位删除而清除
        // $table_orders = $wpdb->prefix . 'zibll_ad_orders';
        // $wpdb->delete($table_orders, array('slot_id' => $slot_id), array('%d'));

        // 删除 post
        wp_delete_post($slot_id, true);

        // 清除缓存
        if (function_exists('zibll_ad_clear_slot_cache')) {
            zibll_ad_clear_slot_cache($slot_id);
        }

        return true;
    }

    /**
     * 获取所有广告位列表
     *
     * @param array $args 查询参数
     * @return array 广告位列表
     */
    public static function get_all($args = array()) {
        $defaults = array(
            'post_type' => 'zibll_ad_slot',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'meta_value_num',
            'meta_key' => 'sort_order',
            'order' => 'ASC',
        );

        $args = wp_parse_args($args, $defaults);

        $posts = get_posts($args);

        $slots = array();
        foreach ($posts as $post) {
            $slots[] = self::get($post->ID);
        }

        return $slots;
    }

    /**
     * 获取用于渲染的 units 数据
     *
     * @param int $slot_id 广告位 ID
     * @return array units 渲染数组
     */
    public static function get_units_for_render($slot_id) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';
        $current_time = time();

        // 查询该 slot 的所有 units，按 unit_key 排序
        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_units
            WHERE slot_id = %d
            ORDER BY unit_key ASC",
            $slot_id
        ), ARRAY_A);

        $render_data = array();

        foreach ($units as $unit) {
            $render_item = array(
                'unit_id' => $unit['id'],
                'unit_key' => $unit['unit_key'],
                'status' => $unit['status'],
            );

            // 如果是 paid 状态且未过期，显示广告内容
            if ($unit['status'] === 'paid' && $unit['ends_at'] && $unit['ends_at'] > $current_time) {
                $render_item['customer_name'] = $unit['customer_name'];
                $render_item['website_name'] = $unit['website_name'];
                $render_item['website_url'] = $unit['website_url'];
                $render_item['image_url'] = $unit['image_url'];
                $render_item['text_content'] = $unit['text_content'];
                $render_item['target_url'] = $unit['target_url'];
                $render_item['color_key'] = $unit['color_key'];
                $render_item['is_empty'] = false;
            } else {
                // 空位或已过期
                $render_item['is_empty'] = true;
            }

            $render_data[] = $render_item;
        }

        return $render_data;
    }

    /**
     * 更新 meta 数据（私有辅助方法）
     *
     * @param int   $slot_id 广告位 ID
     * @param array $data    数据
     */
    private static function update_meta($slot_id, $data) {
        // 深度思考：WordPress post meta 的保存机制
        // 1. update_post_meta() 会自动序列化数组
        // 2. 空数组也应该被保存，而不是忽略
        // 3. 关键修复：记录每个字段的保存情况，便于调试

        $meta_fields = array(
            'widget_title',
            'slot_type',
            'image_display_mode',
            'mount_type',
            'device_display',
            'display_layout',
            'widget_bindings',
            'pricing_packages',
            'pricing_single_month',
            'carousel_price_diff_enabled',
            'carousel_price_diff_type',
            'carousel_price_diff_amount',
            'text_color_options',
            'text_length_range',
            'image_aspect_ratio',
            'default_media',
            'purchase_notice',
            'payment_methods_override',
            'sort_order',
            'enabled',
        );

        zibll_ad_log('update_meta called for slot ' . $slot_id, array(
            'data_keys' => array_keys($data),
            'data' => $data,
        ));

        foreach ($meta_fields as $field) {
            // 关键修复：使用 array_key_exists 而不是 isset
            // isset() 对于 null 值会返回 false，导致数据不被保存
            if (array_key_exists($field, $data)) {
                $value = $data[$field];

                // 记录保存操作
                zibll_ad_log("Saving meta field: {$field}", array(
                    'slot_id' => $slot_id,
                    'value' => $value,
                    'type' => gettype($value),
                ));

                // WordPress 会自动序列化数组，所以不需要特殊处理
                $result = update_post_meta($slot_id, $field, $value);

                // 验证保存结果
                if ($result === false) {
                    zibll_ad_log("Failed to save meta field: {$field}", array(
                        'slot_id' => $slot_id,
                        'value' => $value,
                    ));
                } else {
                    // 读取保存后的值进行验证
                    $saved_value = get_post_meta($slot_id, $field, true);
                    if ($saved_value !== $value) {
                        zibll_ad_log("Meta field value mismatch after save: {$field}", array(
                            'expected' => $value,
                            'actual' => $saved_value,
                        ));
                    }
                }
            }
        }

        zibll_ad_log('update_meta completed for slot ' . $slot_id);
    }

    /**
     * 初始化 units（私有辅助方法）
     *
     * @param int $slot_id   广告位 ID
     * @param int $max_items 最大单元数
     */
    private static function init_units($slot_id, $max_items) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        for ($i = 0; $i < $max_items; $i++) {
            $wpdb->insert(
                $table_units,
                array(
                    'slot_id' => $slot_id,
                    'unit_key' => $i,
                    'status' => 'available',
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s')
            );
        }
    }

    /**
     * 调整 units 数量（私有辅助方法）
     *
     * @param int $slot_id   广告位 ID
     * @param int $max_items 最大单元数
     */
    private static function adjust_units($slot_id, $max_items) {
        global $wpdb;

        $table_units = $wpdb->prefix . 'zibll_ad_units';

        // 获取当前 units 数量
        $current_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_units WHERE slot_id = %d",
            $slot_id
        ));

        if ($max_items > $current_count) {
            // 需要增加 units
            for ($i = $current_count; $i < $max_items; $i++) {
                $wpdb->insert(
                    $table_units,
                    array(
                        'slot_id' => $slot_id,
                        'unit_key' => $i,
                        'status' => 'available',
                        'created_at' => current_time('mysql'),
                    ),
                    array('%d', '%d', '%s', '%s')
                );
            }
        } elseif ($max_items < $current_count) {
            // 需要删除多余的 units（仅删除 available 状态的）
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_units
                WHERE slot_id = %d
                AND status = 'available'
                AND unit_key >= %d
                ORDER BY unit_key DESC
                LIMIT %d",
                $slot_id,
                $max_items,
                $current_count - $max_items
            ));
        }
    }
}
