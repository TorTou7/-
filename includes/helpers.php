<?php
/**
 * 工具函数
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 读取插件设置
 *
 * @param string $key     设置键
 * @param mixed  $default 默认值
 * @return mixed 设置值
 */
function zibll_ad_get_option($key, $default = null) {
    $settings = get_option('zibll_ad_settings', array());

    if (isset($settings[$key])) {
        return $settings[$key];
    }

    return $default;
}

/**
 * 更新插件设置
 *
 * @param string $key   设置键
 * @param mixed  $value 设置值
 * @return bool 是否成功
 */
function zibll_ad_update_option($key, $value) {
    $settings = get_option('zibll_ad_settings', array());
    $settings[$key] = $value;

    return update_option('zibll_ad_settings', $settings);
}

/**
 * 删除特定 slot 的渲染缓存（兼容 Redis + 旧键格式）
 *
 * 新策略（推荐）：缓存键为 zibll_ad_slot_render_{slot_id}
 * - 在使用对象缓存（如 Redis Object Cache）时，transient 存储于缓存而非数据库
 * - 必须通过 delete_transient 精准删除，直接删数据库无效
 *
 * 兼容策略：旧版本键含哈希后缀 zibll_ad_slot_render_{slot_id}_{hash}
 * - 若站点未启用对象缓存（无 Redis），继续通过 SQL 通配删除旧键
 * - 若启用对象缓存，旧键会自然过期，且新版本不再读取旧键
 *
 * @param int $slot_id 广告位 ID
 * @return void
 */
function zibll_ad_clear_slot_cache($slot_id) {
    global $wpdb;

    $slot_id = intval($slot_id);

    // 1) 删除新键（Redis/DB 都适用）
    $simple_key = 'zibll_ad_slot_render_' . $slot_id;
    delete_transient($simple_key);

    // 1.1) 尝试删除“当前版本”哈希键（如果存在）
    $current_hash = function_exists('zibll_ad_generate_cache_hash') ? zibll_ad_generate_cache_hash($slot_id) : '';
    if ($current_hash) {
        delete_transient($simple_key . '_' . $current_hash);
        // 同时删除对象缓存中的键（以防部分环境 delete_transient 未处理到）
        wp_cache_delete($simple_key . '_' . $current_hash, 'transient');
        wp_cache_delete('timeout_' . $simple_key . '_' . $current_hash, 'transient');
        wp_cache_delete($simple_key . '_' . $current_hash, 'site-transient');
        wp_cache_delete('timeout_' . $simple_key . '_' . $current_hash, 'site-transient');
    }

    // 2) DB 模式下，清理旧版带哈希的历史键，防止堆积
    if (!wp_using_ext_object_cache()) {
        $like_pattern         = '_transient_zibll_ad_slot_render_' . $slot_id . '_%';
        $like_timeout_pattern = '_transient_timeout_zibll_ad_slot_render_' . $slot_id . '_%';

        // 额外清理可能存在的无哈希新键（DB transient 存在于 options 表）
        $exact_key            = '_transient_zibll_ad_slot_render_' . $slot_id;
        $exact_timeout_key    = '_transient_timeout_zibll_ad_slot_render_' . $slot_id;

        // 注意：不能使用 prepare 绑定多个不同的 LIKE 与 = 混合，只能分两次
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like_pattern,
                $like_timeout_pattern
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s OR option_name = %s",
                $exact_key,
                $exact_timeout_key
            )
        );
    }

    // 可选：记录日志，便于排查 Redis 场景下的缓存问题
    if (function_exists('zibll_ad_log')) {
        zibll_ad_log('Clear slot render cache', array(
            'slot_id' => $slot_id,
            'key' => $simple_key,
            'ext_object_cache' => wp_using_ext_object_cache() ? 'yes' : 'no',
        ));
    }
}

/**
 * 计算广告价格
 *
 * 支持两种定价模式：
 * 1. 预设套餐：从 pricing_packages 中匹配套餐名称，返回固定价格
 * 2. 自定义月数：使用 pricing_single_month 单价 × 月数
 *
 * 附加价格：文字广告支持颜色附加价（从 text_color_options 中查找）
 *
 * @param array  $slot_meta 广告位元数据（包含 pricing_packages, pricing_single_month, text_color_options）
 * @param mixed  $plan      套餐标识（套餐名称字符串 或 自定义月数整数）
 * @param string $color_key 颜色键（可选，仅文字广告）
 * @return array 价格详情 array(
 *     'base_price' => float,      // 基础价格
 *     'color_price' => float,     // 颜色附加价
 *     'total_price' => float,     // 总价
 *     'duration_months' => int,   // 投放月数
 *     'plan_type' => string       // 套餐类型：'package' 或 'custom'
 * )
 */
function zibll_ad_calculate_price($slot_meta, $plan, $color_key = '') {
    $base_price = 0;
    $color_price = 0;
    $duration_months = 0;
    $plan_type = 'custom';

    // 1. 计算基础价格
    // 先检查是否为预设套餐（套餐名称通常是字符串，如 "3个月优惠套餐"）
    $is_package = false;

    if (isset($slot_meta['pricing_packages']) && is_array($slot_meta['pricing_packages'])) {
        foreach ($slot_meta['pricing_packages'] as $package) {
            // 套餐匹配：支持按 label 或 按完整匹配
            $package_label = isset($package['label']) ? $package['label'] : '';

            // 如果 $plan 是字符串且与套餐名称匹配
            if (is_string($plan) && $package_label === $plan) {
                $base_price = isset($package['price']) ? floatval($package['price']) : 0;
                $duration_months = isset($package['months']) ? intval($package['months']) : 1;
                $plan_type = 'package';
                $is_package = true;
                break;
            }
        }
    }

    // 如果不是预设套餐，按自定义月数计算
    if (!$is_package) {
        // $plan 应该是月数（整数或数字字符串）
        $duration_months = intval($plan);

        // 月数至少为 1
        if ($duration_months <= 0) {
            $duration_months = 1;
        }

        // 获取单月价格
        $single_month_price = isset($slot_meta['pricing_single_month'])
            ? floatval($slot_meta['pricing_single_month'])
            : 0;

        $base_price = $single_month_price * $duration_months;
        $plan_type = 'custom';
    }

    // 2. 计算颜色附加价（仅文字广告且指定了颜色）
    if (!empty($color_key) && isset($slot_meta['text_color_options']) && is_array($slot_meta['text_color_options'])) {
        foreach ($slot_meta['text_color_options'] as $color_option) {
            $option_key = isset($color_option['key']) ? $color_option['key'] : '';

            if ($option_key === $color_key) {
                $color_price = isset($color_option['price']) ? floatval($color_option['price']) : 0;
                break;
            }
        }
    }

    // 3. 计算总价（保留两位小数）
    $total_price = round($base_price + $color_price, 2);

    return array(
        'base_price' => round($base_price, 2),
        'color_price' => round($color_price, 2),
        'total_price' => $total_price,
        'duration_months' => $duration_months,
        'plan_type' => $plan_type,
    );
}

/**
 * 校验 URL
 *
 * 安全要求：
 * 1. 必须是合法的 URL 格式
 * 2. 必须使用 HTTP 或 HTTPS 协议
 *
 * 兼容性：兼容 PHP 7.2（不使用 str_starts_with）
 *
 * @param string $url URL 地址
 * @return bool 是否有效
 */
function zibll_ad_validate_url($url) {
    // 空值检查
    if (empty($url) || !is_string($url)) {
        return false;
    }

    // 基本 URL 格式校验
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    // 必须是 HTTP 或 HTTPS 协议（兼容 PHP 7.2，不使用 str_starts_with）
    if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
        return false;
    }

    return true;
}

/**
 * 校验联系方式
 *
 * 支持的类型及规则：
 * - qq: 1-9开头，至少5位数字
 * - wechat: 字母开头，6-20位（字母、数字、下划线、横线）
 * - email: 使用 WordPress 内置 is_email() 验证
 *
 * @param string $type  联系方式类型（qq/wechat/email）
 * @param string $value 联系方式值
 * @return bool 是否有效
 */
function zibll_ad_validate_contact($type, $value) {
    // 空值检查
    if (empty($value) || !is_string($value)) {
        return false;
    }

    // 去除首尾空格
    $value = trim($value);

    if (empty($value)) {
        return false;
    }

    switch ($type) {
        case 'qq':
            // QQ 号：1-9开头，后续至少4位数字（总共至少5位）
            // 例如：10000, 123456789
            return preg_match('/^[1-9][0-9]{4,}$/', $value) === 1;

        case 'wechat':
            // 微信号：字母开头，6-20位，可包含字母数字下划线横线
            // 例如：abc_123, MyWechat-ID
            return preg_match('/^[a-zA-Z][-_a-zA-Z0-9]{5,19}$/', $value) === 1;

        case 'email':
            // 使用 WordPress 内置邮箱验证函数
            // 返回验证后的邮箱地址或 false
            return is_email($value) !== false;

        default:
            // 不支持的联系方式类型
            return false;
    }
}

/**
 * 生成缓存键的 hash 值
 *
 * 基于 slot 配置和当前 units 状态生成唯一 hash
 * 当配置或内容变化时，hash 会改变，确保缓存失效
 *
 * @param int   $slot_id   广告位 ID
 * @param array $slot_data 广告位数据（可选）
 * @return string hash 值
 */
function zibll_ad_generate_cache_hash($slot_id, $slot_data = null) {
    global $wpdb;

    // 如果未提供 slot_data，从数据库读取
    if (null === $slot_data) {
        if (class_exists('Zibll_Ad_Slot_Model')) {
            $slot_data = Zibll_Ad_Slot_Model::get($slot_id);
        }
    }

    // 获取 units 的最后更新时间作为版本标识
    $table_units = $wpdb->prefix . 'zibll_ad_units';
    $last_updated = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(UNIX_TIMESTAMP(updated_at)) FROM $table_units WHERE slot_id = %d",
        $slot_id
    ));

    // 组合关键数据生成 hash
    $hash_data = array(
        'slot_id' => $slot_id,
        'last_updated' => $last_updated,
        'slot_type' => isset($slot_data['slot_type']) ? $slot_data['slot_type'] : '',
        'display_layout' => isset($slot_data['display_layout']) ? $slot_data['display_layout'] : array(),
        // 将关键设置纳入哈希，确保切换“链接重定向”等设置后会重新渲染缓存
        'link_redirect' => (bool) zibll_ad_get_option('link_redirect', true),
    );

    return md5(maybe_serialize($hash_data));
}

/**
 * 格式化价格显示
 *
 * 复用主题的货币符号和格式
 *
 * @param float $price 价格
 * @return string 格式化后的价格字符串
 */
function zibll_ad_format_price($price) {
    // 如果主题提供了价格格式化函数，优先使用
    if (function_exists('zibpay_get_pay_mark')) {
        return zibpay_get_pay_mark($price);
    }

    // 否则使用简单格式化
    return '¥' . number_format($price, 2, '.', '');
}

/**
 * 获取订单超时时间（分钟）
 *
 * 优先从插件设置读取，其次从主题设置读取，最后使用默认值 30 分钟
 *
 * @return int 超时时间（分钟）
 */
function zibll_ad_get_order_timeout() {
    // 按主题设置跟随；不再使用插件独立设置
    if (function_exists('_pz')) {
        $theme_timeout = _pz('order_pay_max_minutes');
        if ($theme_timeout && $theme_timeout > 0) {
            return intval($theme_timeout);
        }
    }

    // 默认 30 分钟
    return 30;
}

/**
 * 记录调试日志（开发调试用）
 *
 * 仅在 WP_DEBUG 启用时记录
 *
 * @param string $message 日志消息
 * @param array  $context 上下文数据
 */
function zibll_ad_log($message, $context = array()) {
    // 开发环境强制启用日志
    $force_log = defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE;

    if (!$force_log && (!defined('WP_DEBUG') || !WP_DEBUG)) {
        return;
    }

    $log_message = '[Zibll Ad] ' . $message;

    if (!empty($context)) {
        $log_message .= ' | Context: ' . print_r($context, true);
    }

    error_log($log_message);

    // 同时写入专用日志文件
    $log_file = WP_CONTENT_DIR . '/zibll-ad-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents(
        $log_file,
        "[{$timestamp}] {$log_message}\n",
        FILE_APPEND
    );
}

/**
 * 发送邮件（优先走子比主题钩子）并记录本次发送结果
 *
 * - 优先尝试主题的邮件发送函数（如存在）：zib_send_mail 或 zib_mail
 * - 否则回退到 wp_mail
 * - 将每次调用的结果写入 $GLOBALS['zibll_ad_mail_log']，便于测试端点输出
 *
 * @param string       $to      收件邮箱
 * @param string       $subject 主题
 * @param string       $body    正文
 * @param string|array $headers 头部
 * @return bool 是否发送成功
 */
function zibll_ad_send_mail($to, $subject, $body, $headers = array()) {
    $sent = false;
    $via  = 'wp_mail';

    // 优先使用子比主题的邮件函数（这些函数可能没有返回值，但会实际发送邮件）
    // 注意：zib_send_email 等函数内部已调用 wp_mail，不需要再次回退
    if (function_exists('zib_send_email')) {
        // zib_send_email 没有返回值，但会实际发送邮件（带站点名前缀）
        try {
            zib_send_email($to, $subject, $body);
            $sent = true; // 假设发送成功（函数内部使用 @ 抑制错误）
            $via  = 'zib_send_email';
        } catch (Throwable $e) {
            // 如果抛出异常，回退到 wp_mail
            $sent = wp_mail($to, $subject, $body, $headers);
            $via  = 'wp_mail (fallback)';
        }
    } elseif (function_exists('zib_send_mail')) {
        try {
            zib_send_mail($to, $subject, $body);
            $sent = true;
            $via  = 'zib_send_mail';
        } catch (Throwable $e) {
            $sent = wp_mail($to, $subject, $body, $headers);
            $via  = 'wp_mail (fallback)';
        }
    } elseif (function_exists('zib_mail')) {
        try {
            zib_mail($to, $subject, $body);
            $sent = true;
            $via  = 'zib_mail';
        } catch (Throwable $e) {
            $sent = wp_mail($to, $subject, $body, $headers);
            $via  = 'wp_mail (fallback)';
        }
    } else {
        // 没有主题函数，使用 WordPress 原生邮件
        $sent = wp_mail($to, $subject, $body, $headers);
        $via  = 'wp_mail';
    }

    if (!isset($GLOBALS['zibll_ad_mail_log']) || !is_array($GLOBALS['zibll_ad_mail_log'])) {
        $GLOBALS['zibll_ad_mail_log'] = array();
    }
    $GLOBALS['zibll_ad_mail_log'][] = array(
        'to'      => $to,
        'subject' => $subject,
        'message' => $body,
        'sent'    => (bool) $sent,
        'via'     => $via,
        'final_subject_est' => ($via === 'zib_send_email') ? ('[' . get_bloginfo('name') . '] ' . $subject) : $subject,
        'time'    => date('Y-m-d H:i:s'),
    );

    return (bool) $sent;
}

/**
 * 获取广告位可公开访问的页面 URL（用于通知、链接重写）
 *
 * 规则（尽量通用且安全）：
 * - 优先根据挂载的 Sidebar 判断：若含 home/index 等关键词，返回站点首页
 * - 否则返回站点首页作为兜底（Widget 常见于首页/全站侧边栏）
 * - 允许通过过滤器 `zibll_ad_slot_public_url` 覆盖
 *
 * @param int $slot_id 广告位 ID
 * @return string 可公开访问的 URL
 */
function zibll_ad_get_slot_public_url($slot_id) {
    $slot_id = absint($slot_id);
    if ($slot_id <= 0) {
        return home_url('/');
    }

    // 尝试读取挂载的 sidebars
    $mounted = array();
    if (class_exists('Zibll_Ad_Widget_Manager')) {
        try {
            $mounted = Zibll_Ad_Widget_Manager::instance()->get_mounted_sidebars($slot_id);
        } catch (Throwable $e) {
            $mounted = array();
        }
    }
    if (!is_array($mounted)) {
        $mounted = array();
    }

    // 简单启发式：带有 home/index 的侧边栏 → 首页
    $home_like = false;
    foreach ($mounted as $sid) {
        $sid_l = strtolower((string) $sid);
        if (strpos($sid_l, 'home') !== false || strpos($sid_l, 'index') !== false || strpos($sid_l, 'main') !== false) {
            $home_like = true;
            break;
        }
    }

    $url = $home_like ? home_url('/') : home_url('/');

    /**
     * 允许主题/站点自定义某广告位的公开 URL
     *
     * @param string $url      当前解析到的 URL（默认首页）
     * @param int    $slot_id  广告位 ID
     * @param array  $mounted  已挂载的 Sidebar ID 列表
     */
    $url = apply_filters('zibll_ad_slot_public_url', $url, $slot_id, $mounted);

    return esc_url_raw($url);
}

/**
 * 是否启用广告链接重定向（go.php）
 *
 * 默认开启；读取插件设置（若未保存，返回默认 true）。
 *
 * @return bool
 */
function zibll_ad_should_redirect_links() {
    return (bool) zibll_ad_get_option('link_redirect', true);
}

/**
 * 构建主题 go.php 重定向的链接
 *
 * 优先调用子比主题提供的相关函数（若存在），否则回退到 /go.php?url= 的通用约定。
 * 会在以下情况下回退为原链接：
 * - 目标 URL 无效或非 http/https
 * - 目标 URL 已经是 go.php
 *
 * @param string $url 目标外链
 * @return string 经处理后的链接
 */
function zibll_ad_build_redirect_url($url) {
    $url = (string) $url;
    if (!zibll_ad_validate_url($url)) {
        return $url;
    }

    // 若已是 go.php 或已带 golink 参数，则直接返回，避免二次包装
    $parsed = wp_parse_url($url);
    if (!empty($parsed['path']) && strpos($parsed['path'], 'go.php') !== false) {
        return $url;
    }
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $q);
        if (!empty($q['golink'])) {
            return $url;
        }
    }

    // 主题函数优先（不同版本主题可能函数名不同，做尽可能的兼容）
    if (function_exists('zib_get_gourl')) {
        // 官方主题提供：zib_get_gourl($url)
        try { return (string) zib_get_gourl($url); } catch (Throwable $e) {}
    }
    if (function_exists('zib_get_go_link')) {
        // 常见：zib_get_go_link($url)
        try { return (string) zib_get_go_link($url); } catch (Throwable $e) {}
    }
    if (function_exists('zib_go')) {
        try { return (string) zib_go($url); } catch (Throwable $e) {}
    }
    if (function_exists('zib_go_link')) {
        try { return (string) zib_go_link($url); } catch (Throwable $e) {}
    }
    if (function_exists('zib_get_outlink')) {
        try { return (string) zib_get_outlink($url); } catch (Throwable $e) {}
    }

    // 兜底：?golink=base64(url)[&nonce=]
    $nonce = '';
    if (function_exists('_pz') && _pz('go_link_nonce_s')) {
        $nonce = '&nonce=' . wp_create_nonce('go_link_nonce');
    }
    return esc_url(home_url('?golink=' . base64_encode($url) . $nonce));
}

/**
 * 根据设置决定是否对链接进行 go.php 重定向封装
 *
 * @param string $url 原始链接
 * @return string 处理后的链接
 */
function zibll_ad_maybe_redirect_url($url) {
    if (!zibll_ad_should_redirect_links()) {
        return $url;
    }
    return zibll_ad_build_redirect_url($url);
}
