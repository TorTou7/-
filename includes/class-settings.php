<?php
/**
 * 插件设置管理
 *
 * 负责：
 * - 提供默认设置
 * - 读取/更新/校验设置
 * - 枚举受支持的取值（图片格式、支付方式）
 *
 * @package Zibll_Ad
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Settings {

    /**
     * 获取默认设置
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'global_pause_purchase'    => false,
            'allow_guest_purchase'     => true,
            'default_purchase_notice'  => __('请确保您的网站内容合法合规，广告内容需符合相关法律法规。支付成功后广告将自动上线，到期后自动下线。', 'zibll-ad'),
            // 新增：允许上传图片（所有用户），默认开启
            'allow_image_upload'       => true,
            'image_max_size'           => 10240, // KB (10MB)
            'image_allowed_types'      => array('jpg', 'jpeg', 'png', 'gif', 'webp'),
            'enable_expiry_notification'=> true,
            'expiry_notice_days'       => 7,
            'keep_data_on_uninstall'   => true,
            // 新增：允许余额支付（可开关）
            'allow_balance_payment'    => true,
            // 新增：允许游客上传图片（控制前台图片广告在未登录情况下是否允许上传素材）
            'allow_guest_image_upload' => true,
            // 新增：链接重定向（go.php），默认开启
            'link_redirect'            => true,
        );
    }

    /**
     * 读取全部设置（带默认值回填）
     *
     * @return array
     */
    public static function get_all() {
        $saved = get_option('zibll_ad_settings', array());
        $defaults = self::defaults();

        // 合并（保存的优先）
        $all = array_merge($defaults, is_array($saved) ? $saved : array());

        // 确保类型
        $all['global_pause_purchase'] = (bool) $all['global_pause_purchase'];
        $all['allow_guest_purchase'] = (bool) $all['allow_guest_purchase'];
        $all['allow_image_upload'] = isset($all['allow_image_upload']) ? (bool) $all['allow_image_upload'] : true;
        $all['image_max_size'] = max(1, intval($all['image_max_size']));
        $all['image_allowed_types'] = is_array($all['image_allowed_types']) ? array_values(array_unique(array_map('strtolower', $all['image_allowed_types']))) : array();
        $all['enable_expiry_notification'] = (bool) $all['enable_expiry_notification'];
        $all['expiry_notice_days'] = max(1, intval($all['expiry_notice_days']));
        $all['keep_data_on_uninstall'] = !empty($all['keep_data_on_uninstall']);
        $all['allow_balance_payment'] = isset($all['allow_balance_payment']) ? (bool) $all['allow_balance_payment'] : true;
        $all['allow_guest_image_upload'] = isset($all['allow_guest_image_upload']) ? (bool) $all['allow_guest_image_upload'] : true;
        $all['link_redirect'] = isset($all['link_redirect']) ? (bool) $all['link_redirect'] : true;
        
        // 联动逻辑：如果allow_image_upload关闭，则allow_guest_image_upload自动关闭
        if (!$all['allow_image_upload']) {
            $all['allow_guest_image_upload'] = false;
        }

        return $all;
    }

    /**
     * 更新设置（带校验/清洗）
     *
     * @param array $data 输入数据
     * @return array 保存后的设置
     */
    public static function update($data) {
        $current = self::get_all();
        $clean = self::sanitize($data);
        $merged = array_merge($current, $clean);
        update_option('zibll_ad_settings', $merged);
        return self::get_all();
    }

    /**
     * 清洗/校验输入
     *
     * @param array $data
     * @return array
     */
    public static function sanitize($data) {
        $out = array();

        if (array_key_exists('global_pause_purchase', $data)) {
            $out['global_pause_purchase'] = (bool) $data['global_pause_purchase'];
        }

        if (array_key_exists('allow_guest_purchase', $data)) {
            $out['allow_guest_purchase'] = (bool) $data['allow_guest_purchase'];
        }

        if (array_key_exists('default_purchase_notice', $data)) {
            $out['default_purchase_notice'] = sanitize_textarea_field($data['default_purchase_notice']);
        }

        if (array_key_exists('image_max_size', $data)) {
            // KB，限制 10KB - 10240KB(10MB)
            $kb = intval($data['image_max_size']);
            if ($kb < 10) { $kb = 10; }
            if ($kb > 10240) { $kb = 10240; }
            $out['image_max_size'] = $kb;
        }

        if (array_key_exists('image_allowed_types', $data)) {
            $allowed = self::allowed_image_types();
            $types = is_array($data['image_allowed_types']) ? $data['image_allowed_types'] : array();
            $types = array_values(array_unique(array_map('strtolower', $types)));
            // 只保留合法项
            $types = array_values(array_intersect($types, $allowed));
            if (empty($types)) {
                // 至少保留 jpg/png
                $types = array('jpg', 'png');
            }
            $out['image_allowed_types'] = $types;
        }

        if (array_key_exists('enable_expiry_notification', $data)) {
            $out['enable_expiry_notification'] = (bool) $data['enable_expiry_notification'];
        }

        if (array_key_exists('expiry_notice_days', $data)) {
            $days = intval($data['expiry_notice_days']);
            if ($days < 1) { $days = 1; }
            if ($days > 60) { $days = 60; }
            $out['expiry_notice_days'] = $days;
        }

        if (array_key_exists('keep_data_on_uninstall', $data)) {
            $out['keep_data_on_uninstall'] = (bool) $data['keep_data_on_uninstall'];
        }

        if (array_key_exists('allow_balance_payment', $data)) {
            $out['allow_balance_payment'] = (bool) $data['allow_balance_payment'];
        }

        if (array_key_exists('allow_image_upload', $data)) {
            $out['allow_image_upload'] = (bool) $data['allow_image_upload'];
            // 联动逻辑：如果关闭了allow_image_upload，自动关闭allow_guest_image_upload
            if (!$out['allow_image_upload']) {
                $out['allow_guest_image_upload'] = false;
            }
        }

        if (array_key_exists('allow_guest_image_upload', $data)) {
            $out['allow_guest_image_upload'] = (bool) $data['allow_guest_image_upload'];
        }

        if (array_key_exists('link_redirect', $data)) {
            $out['link_redirect'] = (bool) $data['link_redirect'];
        }

        return $out;
    }

    /**
     * 允许的图片类型枚举
     *
     * @return array
     */
    public static function allowed_image_types() {
        return array('jpg', 'jpeg', 'png', 'gif', 'webp');
    }

    /**
     * 获取主题的支付方式（key=>label）
     *
     * @return array
     */
    public static function get_theme_payment_methods() {
        $list = array();
        if (function_exists('zibpay_get_payment_methods')) {
            $methods = zibpay_get_payment_methods(31);
            if (is_array($methods)) {
                foreach ($methods as $key => $method) {
                    $name = '';
                    if (is_array($method)) {
                        $name = isset($method['name']) ? $method['name'] : '';
                    } else {
                        $name = (string) $method;
                    }
                    $list[sanitize_key($key)] = wp_strip_all_tags($name);
                }
            }
        }
        return $list;
    }
}
