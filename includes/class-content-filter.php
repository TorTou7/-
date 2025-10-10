<?php
/**
 * 内容过滤器 - 让主题的文章插入内容和小工具支持短代码
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Content_Filter {

    /**
     * 防止递归的标志
     */
    private static $processing = false;

    /**
     * 构造函数
     */
    public function __construct() {
        // 方案1：通过 WordPress 核心过滤器处理主题选项中的短代码
        // 在主题从数据库读取选项后立即处理
        add_filter('option_zibll_options', array($this, 'process_theme_options'), 10, 1);
        
        // 方案2：为WordPress小工具文本添加短代码支持
        add_filter('widget_text', 'do_shortcode');
        add_filter('widget_text_content', 'do_shortcode');
        
        // 方案3：为自定义HTML小工具添加短代码支持
        add_filter('widget_custom_html_content', 'do_shortcode');
    }

    /**
     * 处理主题选项数组，为文章插入内容添加短代码支持
     *
     * @param array $options 主题所有选项
     * @return array 处理后的选项
     */
    public function process_theme_options($options) {
        // 防止递归调用（避免无限循环）
        if (self::$processing) {
            return $options;
        }

        if (!is_array($options)) {
            return $options;
        }

        // 设置处理标志
        self::$processing = true;

        // 处理文章前后插入内容的字段
        $shortcode_fields = array(
            'post_front_content',    // 文章前内容
            'post_after_content',    // 文章后内容
        );

        foreach ($shortcode_fields as $field) {
            if (isset($options[$field]) && !empty($options[$field]) && is_string($options[$field])) {
                // 处理短代码
                $options[$field] = do_shortcode($options[$field]);
            }
        }

        // 重置处理标志
        self::$processing = false;

        return $options;
    }
}

