<?php
/**
 * 自定义链接重写：将 zibll_ad_slot 的永久链接指向实际挂载页面
 *
 * 解决问题：主题/通知系统会将商品名称链接到 post_id 对应的 permalink。
 * 由于广告位使用非公开的自定义文章类型（public=false），该链接会 404。
 * 通过 post_type_link 过滤器，将其重写为广告位挂载的页面 URL（如首页）。
 *
 * @package Zibll_Ad
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Permalink_Fixes {

    public function __construct() {
        // 在生成链接时重写自定义文章类型 zibll_ad_slot 的链接
        add_filter('post_type_link', array($this, 'filter_slot_permalink'), 10, 3);
    }

    /**
     * 重写广告位自定义文章类型的链接
     *
     * @param string  $permalink  原始链接
     * @param WP_Post $post       文章对象
     * @param bool    $leavename  是否保留名称占位符
     * @return string 可公开访问的页面 URL
     */
    public function filter_slot_permalink($permalink, $post, $leavename) {
        if (!$post || $post->post_type !== 'zibll_ad_slot') {
            return $permalink;
        }

        // 使用助手函数解析挂载页面 URL
        if (function_exists('zibll_ad_get_slot_public_url')) {
            $url = zibll_ad_get_slot_public_url($post->ID);
            if (!empty($url)) {
                return esc_url($url);
            }
        }

        // 兜底：返回首页，避免 404
        return home_url('/');
    }
}
