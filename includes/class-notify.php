<?php
/**
 * 支付成功通知（站内消息 + 邮件）
 *
 * 在广告订单支付成功后，向网站管理员发送站内系统消息，并使用子比主题邮件模板发送邮件通知。
 * 依赖子比主题函数：ZibMsg::add()、zib_mail_to_admin()
 *
 * @package Zibll_Ad
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Notify {

    public function __construct() {
        add_action('zibll_ad_payment_success', array($this, 'handle_payment_success'), 10, 1);
    }

    /**
     * 处理广告订单支付成功事件
     *
     * @param array $payload 包含 order、slot_id、unit_id、ad_request
     * @return void
     */
    public function handle_payment_success($payload) {
        if (!is_array($payload)) return;

        $order      = isset($payload['order']) ? $payload['order'] : null;
        $slot_id    = isset($payload['slot_id']) ? absint($payload['slot_id']) : 0;
        $unit_id    = isset($payload['unit_id']) ? absint($payload['unit_id']) : 0;
        $ad_request = isset($payload['ad_request']) && is_array($payload['ad_request']) ? $payload['ad_request'] : array();

        $slot = Zibll_Ad_Slot_Model::get($slot_id);
        $unit = Zibll_Ad_Unit_Model::get($unit_id);
        if (!$slot || !$unit) return;

        $slot_title   = isset($slot['title']) ? $slot['title'] : '';
        $per_row      = isset($slot['display_layout']['per_row']) ? (int) $slot['display_layout']['per_row'] : 1;
        $unit_key     = isset($ad_request['unit_key']) ? intval($ad_request['unit_key']) : (isset($unit['unit_key']) ? intval($unit['unit_key']) : 0);
        $row          = floor($unit_key / max(1, $per_row)) + 1;
        $col          = ($unit_key % max(1, $per_row)) + 1;
        $position_txt = sprintf('第 %d 行 第 %d 列（#%d）', $row, $col, $unit_key + 1);

        $order_num    = isset($order->order_num) ? sanitize_text_field($order->order_num) : '';
        $payment      = isset($order->pay_type) ? sanitize_text_field($order->pay_type) : '';

        $duration     = isset($ad_request['duration_months']) ? intval($ad_request['duration_months']) : (isset($unit['duration_months']) ? intval($unit['duration_months']) : 0);
        $total_price  = isset($ad_request['total_price']) ? floatval($ad_request['total_price']) : (isset($order->order_price) ? floatval($order->order_price) : 0.0);

        $customer_name = isset($ad_request['ad_data']['customer_name']) ? sanitize_text_field($ad_request['ad_data']['customer_name']) : (isset($unit['customer_name']) ? sanitize_text_field($unit['customer_name']) : '');
        $website_name  = isset($ad_request['ad_data']['website_name']) ? sanitize_text_field($ad_request['ad_data']['website_name']) : (isset($unit['website_name']) ? sanitize_text_field($unit['website_name']) : '');
        $website_url   = isset($ad_request['ad_data']['website_url']) ? esc_url_raw($ad_request['ad_data']['website_url']) : (isset($unit['website_url']) ? esc_url_raw($unit['website_url']) : '');
        $contact_type  = isset($ad_request['ad_data']['contact_type']) ? sanitize_text_field($ad_request['ad_data']['contact_type']) : (isset($unit['contact_type']) ? sanitize_text_field($unit['contact_type']) : '');
        $contact_value = isset($ad_request['ad_data']['contact_value']) ? sanitize_text_field($ad_request['ad_data']['contact_value']) : (isset($unit['contact_value']) ? sanitize_text_field($unit['contact_value']) : '');

        $title = sprintf('有新广告订单已支付：%s', $slot_title);

        $message  = '您好，网站管理员：<br>';
        $message .= '有新的广告订单完成了支付：<br>';
        $message .= '广告位：' . esc_html($slot_title) . '<br>';
        $message .= '位置：' . esc_html($position_txt) . '<br>';
        if ($customer_name) {
            $message .= '广告主：' . esc_html($customer_name) . '<br>';
        }
        if ($website_name) {
            if ($website_url) {
                $message .= '站点：<a href="' . esc_url($website_url) . '" target="_blank">' . esc_html($website_name) . '</a><br>';
            } else {
                $message .= '站点：' . esc_html($website_name) . '<br>';
            }
        }
        if ($contact_type && $contact_value) {
            $map = array('email' => '邮箱', 'qq' => 'QQ', 'wechat' => '微信');
            $label = isset($map[$contact_type]) ? $map[$contact_type] : $contact_type;
            $message .= '联系方式：' . esc_html($label . '：' . $contact_value) . '<br>';
        }
        if ($order_num) {
            $message .= '订单号：' . esc_html($order_num) . '<br>';
        }
        if ($total_price) {
            $message .= '金额：¥' . esc_html(number_format_i18n($total_price, 2)) . '<br>';
        }
        if ($duration) {
            $message .= '投放时长：' . esc_html($duration) . ' 个月<br>';
        }

        $admin_link = admin_url('admin.php?page=' . \Zibll_Ad_Admin_Menu::MENU_SLUG . '#/ads');
        $message   .= '您可以点击下方按钮前往管理：<br>';
        $message   .= '<a target="_blank" style="margin-top: 20px;padding:5px 20px" class="but jb-blue" href="' . esc_url($admin_link) . '">前往广告管理</a><br>';

        if (function_exists('_pz') && _pz('message_s', true) && class_exists('ZibMsg')) {
            \ZibMsg::add(array(
                'send_user'    => 'admin',
                'receive_user' => 'admin', // 全站管理员
                'type'         => 'system',
                'title'        => $title,
                'content'      => $message,
            ));
        }

        if (function_exists('zib_mail_to_admin')) {
            @zib_mail_to_admin($title, $message);
        } else {
            $admin_email = get_option('admin_email');
            if ($admin_email) {
                @wp_mail($admin_email, '[' . get_bloginfo('name') . '] ' . $title, $message);
            }
        }
    }
}

// 实例化
new Zibll_Ad_Notify();

