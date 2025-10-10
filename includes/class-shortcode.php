<?php
/**
 * 广告位短代码处理类
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Shortcode {

    /**
     * 构造函数
     */
    public function __construct() {
        // 注册短代码
        add_shortcode('zibll_ad_slot', array($this, 'render_slot'));
    }

    /**
     * 渲染广告位短代码
     *
     * @param array $atts 短代码属性
     * @return string 渲染的 HTML
     */
    public function render_slot($atts) {
        // 解析短代码参数
        $atts = shortcode_atts(array(
            'id' => 0,
        ), $atts, 'zibll_ad_slot');

        $slot_id = intval($atts['id']);

        if ($slot_id <= 0) {
            if (current_user_can('manage_options')) {
                return '<p style="color: red; border: 1px solid red; padding: 10px;">广告位短代码错误：缺少有效的广告位 ID</p>';
            }
            return '';
        }

        // 获取广告位数据
        if (!class_exists('Zibll_Ad_Slot_Model')) {
            return '';
        }

        $slot = Zibll_Ad_Slot_Model::get($slot_id);

        if (!$slot) {
            if (current_user_can('manage_options')) {
                return '<p style="color: red; border: 1px solid red; padding: 10px;">广告位短代码错误：广告位 ID ' . $slot_id . ' 不存在</p>';
            }
            return '';
        }

        // 检查广告位是否启用
        if (!isset($slot['enabled']) || !$slot['enabled']) {
            if (current_user_can('manage_options')) {
                return '<p style="color: orange; border: 1px solid orange; padding: 10px;">广告位已禁用（仅管理员可见）</p>';
            }
            return '';
        }

        // 检查设备显示设置
        $device_display = isset($slot['device_display']) ? $slot['device_display'] : 'all';
        $is_mobile = wp_is_mobile();
        
        // 根据设备显示设置决定是否渲染
        if ($device_display === 'pc' && $is_mobile) {
            // 仅PC端显示，但当前是移动设备
            if (current_user_can('manage_options')) {
                return '<p style="color: orange; border: 1px solid orange; padding: 10px;">此广告位设置为"仅在PC端显示"，移动端不显示（仅管理员可见）</p>';
            }
            return '';
        } elseif ($device_display === 'mobile' && !$is_mobile) {
            // 仅移动端显示，但当前是PC设备
            if (current_user_can('manage_options')) {
                return '<p style="color: orange; border: 1px solid orange; padding: 10px;">此广告位设置为"仅在移动端显示"，PC端不显示（仅管理员可见）</p>';
            }
            return '';
        }
        // device_display === 'all' 时，不做任何限制

        // 检查挂载方式（兼容未设置的情况，允许 shortcode 或空值）
        $mount_type = isset($slot['mount_type']) ? $slot['mount_type'] : '';
        
        // 调试信息（仅管理员可见）
        if (current_user_can('manage_options') && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Zibll Ad Shortcode Debug - Slot ID: $slot_id, mount_type: '$mount_type'");
        }
        
        // 只有明确设置为 widget 时才拒绝
        if (!empty($mount_type) && $mount_type === 'widget') {
            if (current_user_can('manage_options')) {
                return '<p style="color: orange; border: 1px solid orange; padding: 10px;">此广告位的挂载方式是"挂载到小工具位置"，不是"PHP短代码挂载"（仅管理员可见）<br>当前值：' . esc_html($mount_type) . '</p>';
            }
            return '';
        }

        // 使用 Widget 类渲染广告位
        if (!class_exists('Zibll_Ad_Widget')) {
            return '';
        }

        // 开始输出缓冲
        ob_start();

        // 创建临时 Widget 实例进行渲染
        $widget = new Zibll_Ad_Widget();
        
        // 准备 Widget 参数
        $args = array(
            'before_widget' => '<div class="zibll-ad-slot-shortcode">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        );

        // Widget 实例设置
        $instance = array(
            'slot_id' => $slot_id,
            'title'   => isset($slot['widget_title']) ? $slot['widget_title'] : '',
        );

        // 渲染 Widget
        $widget->widget($args, $instance);

        // 获取输出内容
        $output = ob_get_clean();

        return $output;
    }
}

