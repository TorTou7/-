<?php
/**
 * 定时任务：到期清理
 *
 * 每小时执行一次：
 * - 将已到期的 paid 单元标记为 expired（停止展示，允许再次购买）
 * - 清理超时的 pending 锁定
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Expire_Runner {

    /**
     * 运行到期清理
     *
     * @return void
     */
    public static function run() {
        if (!class_exists('Zibll_Ad_Unit_Model')) {
            return;
        }

        global $wpdb;

        $table_units   = $wpdb->prefix . 'zibll_ad_units';
        $current_time  = time();

        // 1) 将已到期且仍为 paid 的单元标记为 expired
        $expired_candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM $table_units\n                 WHERE status = 'paid'\n                   AND ends_at IS NOT NULL\n                   AND ends_at <= %d",
                $current_time
            ),
            ARRAY_A
        );

        $expired_count = 0;
        if ($expired_candidates) {
            foreach ($expired_candidates as $row) {
                if (Zibll_Ad_Unit_Model::set_expired(intval($row['id']))) {
                    $expired_count++;
                }
            }
        }

        // 2) 清理超时的 pending 锁定
        $pending_cleaned = Zibll_Ad_Unit_Model::cleanup_expired_pending();

        // 3) 即将到期提醒（可选）
        self::maybe_send_expiry_notifications();

        if (function_exists('zibll_ad_log')) {
            zibll_ad_log('Cron expire runner executed', array(
                'expired_paid_to_expired' => $expired_count,
                'pending_cleaned' => intval($pending_cleaned),
                'timestamp' => date('Y-m-d H:i:s'),
            ));
        }
    }

    /**
     * 即将到期邮件提醒
     */
    private static function maybe_send_expiry_notifications() {
        // 设置开关
        if (!function_exists('zibll_ad_get_option')) {
            return;
        }

        $enabled = (bool) zibll_ad_get_option('enable_expiry_notification', true);
        if (!$enabled) {
            return;
        }

        global $wpdb;
        $table_units  = $wpdb->prefix . 'zibll_ad_units';
        $days         = max(1, intval(zibll_ad_get_option('expiry_notice_days', 7)));
        $now          = time();
        $deadline_max = $now + ($days * DAY_IN_SECONDS);

        // 查询即将到期（仍为 paid）的单元
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, slot_id, unit_key, customer_name, contact_type, contact_value, website_name, website_url, ends_at
                 FROM {$table_units}
                 WHERE status = 'paid' AND ends_at IS NOT NULL AND ends_at > 0 AND ends_at BETWEEN %d AND %d",
                $now,
                $deadline_max
            ),
            ARRAY_A
        );

        if (!$rows) {
            return;
        }

        // 为每个即将到期的广告单元发送独立的提醒邮件
        foreach ($rows as $row) {
            $unit_id = intval($row['id']);
            $ends_at = intval($row['ends_at']);

            // 防重复：为该 unit 设置一个 transient，生命周期到过期后 + 1天
            $t_key = 'zibll_ad_expiry_notice_' . $unit_id;
            if (get_transient($t_key)) {
                continue; // 已提醒过，跳过
            }

            $to_email = '';
            // 仅当广告主联系方式为邮箱且有效时才发送
            if (!empty($row['contact_type']) && $row['contact_type'] === 'email' && !empty($row['contact_value'])) {
                $email = is_email($row['contact_value']);
                if ($email) {
                    $to_email = $email;
                }
            }

            if ($to_email) {
                // 主题模板将自动在标题前加 [站点名] 前缀，这里不再重复站点名
                $subject = __('到期提醒：您购买的广告即将到期', 'zibll-ad');
                $remaining_days = max(0, ceil(($ends_at - $now) / DAY_IN_SECONDS));
                $slot_title = '';
                if (class_exists('Zibll_Ad_Slot_Model')) {
                    $slot = Zibll_Ad_Slot_Model::get(intval($row['slot_id']));
                    $slot_title = isset($slot['title']) ? $slot['title'] : '';
                }

                // 邮件正文
                $body  = '';
                $body .= sprintf(__('尊敬的客户 %s，您好：', 'zibll-ad'), esc_html($row['customer_name'] ?: '')) . "\n\n";
                $body .= sprintf(__('您在本站购买的广告位"%s"（位置 #%d）将于 %s 到期。', 'zibll-ad'), esc_html($slot_title), intval($row['unit_key']) + 1, date_i18n('Y-m-d H:i', $ends_at)) . "\n";
                $body .= sprintf(__('剩余天数：%d 天。若需续费，请尽快在到期前完成操作。', 'zibll-ad'), $remaining_days) . "\n\n";

                if (!empty($row['website_name']) || !empty($row['website_url'])) {
                    $body .= __('广告信息：', 'zibll-ad') . "\n";
                    if (!empty($row['website_name'])) {
                        $body .= ' - ' . sprintf(__('站点名称：%s', 'zibll-ad'), esc_html($row['website_name'])) . "\n";
                    }
                    if (!empty($row['website_url'])) {
                        $body .= ' - ' . sprintf(__('目标链接：%s', 'zibll-ad'), esc_url($row['website_url'])) . "\n";
                    }
                    $body .= "\n";
                }
                $body .= __('感谢您的支持！', 'zibll-ad') . "\n";
                $body .= get_bloginfo('name') . ' - ' . home_url('/') . "\n";

                // 发送邮件
                if (!function_exists('zibll_ad_send_mail')) {
                    require_once ZIBLL_AD_PATH . 'includes/helpers.php';
                }
                $email_sent = zibll_ad_send_mail($to_email, $subject, $body);

                // 标记已提醒，防止重复发送
                if ($email_sent) {
                    $ttl = max(1, $ends_at - time() + DAY_IN_SECONDS);
                    set_transient($t_key, 1, $ttl);
                }
            }
        }
    }
}
