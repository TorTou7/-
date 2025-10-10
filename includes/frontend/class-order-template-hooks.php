<?php
/**
 * 订单模板后端修复（广告位订单缩略图）
 *
 * 通过主题公开过滤器 user_order_list_card，
 * 为广告订单（order_type=31）提供稳定的缩略图与统一风格的卡片 HTML，
 * 避免出现 <img src=""> 导致的裂图问题。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Order_Template_Hooks {

    public function __construct() {
        add_filter('user_order_list_card', array($this, 'render_ad_order_card'), 10, 3);
    }

    /**
     * 渲染广告订单列表卡片（后端根治缩略图 src 为空的问题）
     *
     * @param string $html  主题默认传入的 HTML，占位为空字符串
     * @param int    $type  订单类型
     * @param array  $order 订单数组
     * @return string 自定义 HTML 或原值
     */
    public function render_ad_order_card($html, $type, $order) {
        if ((int) $type !== 31 || !is_array($order)) {
            return $html;
        }

        // 基础数据
        $order = (array) $order;
        $status = function_exists('zibpay_get_order_status') ? zibpay_get_order_status($order) : (int) ($order['status'] ?? 0);
        $order_data = class_exists('ZibPay') ? ZibPay::get_meta($order['id'], 'order_data') : array();
        $post_id = isset($order['post_id']) ? (int) $order['post_id'] : 0;
        $post = $post_id ? get_post($post_id) : null;

        // 标题
        $title = '';
        if ($post && !empty($post->post_title)) {
            $title = $post->post_title;
        }
        if (!$title) {
            $title = __('广告位购买', 'zibll-ad');
        }

        // 缩略图（使用插件内置 SVG 资源，避免 src 为空）
        $thumb_url = ZIBLL_AD_URL . 'includes/frontend/assets/img/ad-order.svg';
        $img_html = '<img class="radius8 fit-cover" src="' . esc_url($thumb_url) . '" alt="' . esc_attr($title) . '">';

        // 单价与总价
        $is_points = isset($order['pay_type']) && $order['pay_type'] === 'points';
        $mark_html = '<span class="pay-mark px12">' . ($is_points && function_exists('zibpay_get_points_mark') ? zibpay_get_points_mark() : (function_exists('zibpay_get_pay_mark') ? zibpay_get_pay_mark() : '¥')) . '</span>';

        if (isset($order_data['prices']['unit_price'])) {
            $unit_price = $order_data['prices']['unit_price'];
        } else {
            $unit_price = isset($order['order_price']) ? $order['order_price'] : 0;
        }

        if (isset($order_data['prices']['pay_price'])) {
            $total_price = $order_data['prices']['pay_price'];
        } else {
            $total_price = isset($order['pay_price']) && $order['pay_price'] ? $order['pay_price'] : $unit_price;
        }

        $unit_price_html = $mark_html . '<b>' . esc_html($unit_price) . '</b>';
        $total_price_html = $total_price && $total_price !== $unit_price ? $mark_html . '<b>' . esc_html($total_price) . '</b>' : '';

        // 状态与倒计时（严格对齐主题交互）
        if ((int)$status === 0) {
            $status_html = '<span class="c-red">' . __('待支付', 'zibll-ad') . '</span>';
            if (function_exists('zibpay_get_order_pay_over_time')) {
                $order_ref = $order; // 避免修改原数组
                $remain = zibpay_get_order_pay_over_time($order_ref);
                if ($remain === 'over') {
                    $status = -1;
                    $status_html = '<span class="c-red">' . __('交易已关闭', 'zibll-ad') . '</span>';
                } elseif ($remain) {
                    $time_str = date('m/d/Y H:i:s', (int)$remain);
                    $status_html = '<span class="c-red">' . __('待支付', 'zibll-ad') . ' <span class="c-yellow px12 badg badg-sm countdown-box" int-second="1" data-over-text="' . esc_attr__('交易已关闭', 'zibll-ad') . '" data-countdown="' . esc_attr($time_str) . '"></span></span>';
                }
            }
        } elseif ((int)$status === 1) {
            $status_html = '<span class="c-green">' . __('已支付', 'zibll-ad') . '</span>';
        } else {
            $status_html = '<span class="c-red">' . __('交易已关闭', 'zibll-ad') . '</span>';
        }

        // 下单/支付时间
        $time_text = '';
        if ($status == 1 && !empty($order['pay_time'])) {
            $time_text = $order['pay_time'];
        } elseif (!empty($order['create_time'])) {
            $time_text = $order['create_time'];
        }

        // 选项副标题
        $opt_name = isset($order_data['options_active_name']) ? $order_data['options_active_name'] : '';
        $opt_html = $opt_name ? '<div class="muted-color em09">' . esc_html($opt_name) . '</div>' : '';

        // 数量
        $count = isset($order_data['count']) ? (int) $order_data['count'] : 1;

        // 操作按钮
        $btns = '';
        if ($status == 0) {
            if (function_exists('zibpay_get_order_close_link')) {
                $btns .= zibpay_get_order_close_link($order, 'but mr6', __('关闭订单', 'zibll-ad'));
            }
            if (function_exists('zibpay_get_order_pay_link')) {
                $btns .= zibpay_get_order_pay_link($order, 'but c-red', __('立即支付', 'zibll-ad'));
            }
        }
        $btns_box = $btns ? '<div class="text-right mt10">' . $btns . '</div>' : '';

        // 金额小结
        $total_box = $total_price_html ? '<div class="text-right"><span class="muted-2-color em09 mr3">' . ($status == 1 ? __('实付', 'zibll-ad') : __('应付', 'zibll-ad')) . '</span>' . $total_price_html . '</div>' : '';

        // 底部信息
        $footer_html = '';
        if ($total_box || $btns_box) {
            $footer_html = '<div class="order-footer mt10">' . $total_box . $btns_box . '</div>';
        }

        // 广告订单不显示作者信息，保持与其他订单一致
        $author_html = '';

        // 组装卡片（与主题默认结构一致，核心差异：缩略图使用稳定资源）
        $card = '
        <div class="zib-widget ajax-item mb10 order-item user-order-item order-type-31">
            ' . $author_html . '
            <div class="order-content flex show-order-modal pointer" data-order-id="' . (int) $order['id'] . '">
                <div class="order-thumb mr10">' . $img_html . '</div>
                <div class="flex1 flex jsb xx">
                    <div class="flex1 flex jsb">
                        <div class="flex1 mr10">
                            <div class="order-title">' . esc_html($title) . '</div>
                            ' . $opt_html . '
                            <div class="muted-color em09 mt6">' . esc_html($time_text) . '</div>
                        </div>
                        <div class="flex xx ab">
                            ' . ($author_html ? '' : '<div class="mb10">' . $status_html . '</div>') . '
                            <div class="unit-price">' . $unit_price_html . '</div>
                            ' . ($count > 1 ? '<div class="count mt6 muted-color">x' . (int) $count . '</div>' : '') . '
                        </div>
                    </div>
                </div>
            </div>
            ' . $footer_html . '
        </div>';

        return $card;
    }
}
