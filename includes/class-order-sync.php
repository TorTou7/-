<?php
/**
 * ZibPay 订单同步类
 *
 * 职责:
 * 1. 处理订单类型 31(广告位购买)的数据过滤和价格计算
 * 2. 监听支付成功事件,更新广告单元状态
 * 3. 处理订单关闭/退款,释放广告位
 * 4. 过滤支付方式,实现业务规则(如未登录用户禁用余额支付)
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Order_Sync {

    /**
     * 构造函数
     */
    public function __construct() {
        $this->init_hooks();
        zibll_ad_log('Order_Sync initialized and hooks registered');
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 订单类型 31 过滤器 (不同版本兼容)
        add_filter('initiate_order_data_type_31', array($this, 'filter_order_data'), 10, 2);
        add_filter('initiate_order_data_type31', array($this, 'filter_order_data'), 10, 2); // 兼容无下划线版本
        add_filter('zibpay_initiate_order_data_type_31', array($this, 'filter_order_data'), 10, 2); // 兼容加前缀版本
        zibll_ad_log('Order_Sync: registered initiate_order_data filters');

        // 支付成功回调 (不同版本/主题兼容)
        add_action('payment_order_success', array($this, 'on_payment_success'), 10, 1);
        add_action('payment_success', array($this, 'on_payment_success'), 10, 1);
        add_action('zibpay_payment_success', array($this, 'on_payment_success'), 10, 1);
        add_action('zibpay_order_success', array($this, 'on_payment_success'), 10, 1);
        add_action('balance_pay_success', array($this, 'on_payment_success'), 10, 1); // 兼容余额支付事件
        add_action('zibpay_balance_pay_success', array($this, 'on_payment_success'), 10, 1);
        zibll_ad_log('Order_Sync: registered payment success actions');

        // 订单关闭/退款回调 (兼容)
        add_action('order_closed', array($this, 'on_order_closed'), 10, 1);
        add_action('order_refunded', array($this, 'on_order_closed'), 10, 1);
        add_action('zibpay_order_closed', array($this, 'on_order_closed'), 10, 1);
        add_action('zibpay_order_refunded', array($this, 'on_order_closed'), 10, 1);
        zibll_ad_log('Order_Sync: registered order closed/refunded actions');

        // 支付方式过滤器 (可选,用于业务规则控制)
        add_filter('zibpay_payment_methods', array($this, 'filter_payment_methods'), 10, 2);
        zibll_ad_log('Order_Sync: registered payment methods filter');

        // 订单创建：尽早拿到 order_num（待支付也有）
        add_action('order_created', array($this, 'on_order_created'), 10, 1);
        // 兼容可能的命名
        add_action('zibpay_order_created', array($this, 'on_order_created'), 10, 1);
    }

    /**
     * 过滤订单数据(订单类型 31)
     *
     * 此方法在 ZibPay 创建订单前被调用,负责:
     * 1. 验证临时订单数据的有效性
     * 2. 重新计算价格防止前端篡改
     * 3. 填充订单数据结构供 ZibPay 使用
     *
     * 安全考量:
     * - 所有价格必须后端重新计算
     * - 验证 unit 状态是否允许购买
     * - 验证支付方式合法性
     *
     * @param array $__data    ZibPay 订单数据模板
     * @param array $post_data 用户提交的表单数据 ($_POST)
     * @return array|WP_Error  处理后的订单数据,失败返回 WP_Error
     */
    public function filter_order_data($__data, $post_data) {
        zibll_ad_log('Order_Sync::filter_order_data called', array(
            'post_data_keys' => array_keys($post_data),
        ));

        // ============================================
        // 第1步: 提取并验证基础参数
        // ============================================
        $slot_id   = isset($post_data['slot_id']) ? intval($post_data['slot_id']) : 0;
        $unit_key  = isset($post_data['unit_key']) ? intval($post_data['unit_key']) : 0;
        $token_key = isset($post_data['order_token']) ? sanitize_text_field($post_data['order_token']) : '';

        if (!$slot_id || !is_numeric($unit_key)) {
            return new WP_Error('invalid_params', __('无效的广告位参数', 'zibll-ad'));
        }

        // ============================================
        // 第2步: 读取并验证临时订单数据（兼容两种方案）
        // ============================================
        $transient_key   = '';
        $temp_order_data = false;

        if ($token_key) {
            $transient_key   = $token_key;
            $temp_order_data = get_transient($transient_key);
        }

        // 兼容旧方案（根据 slot_id/unit_key 拼接）
        if (!$temp_order_data || !is_array($temp_order_data)) {
            $fallback_key    = "zibll_ad_order_temp_{$slot_id}_{$unit_key}";
            $temp_order_data = get_transient($fallback_key);
            if ($temp_order_data && is_array($temp_order_data)) {
                $transient_key = $fallback_key;
            }
        }

        if (!$temp_order_data || !is_array($temp_order_data)) {
            zibll_ad_log('Temporary order data not found or expired', array(
                'token_key'     => $token_key,
                'fallback_key'  => isset($fallback_key) ? $fallback_key : '',
            ));
            return new WP_Error('order_expired', __('订单已过期,请重新下单', 'zibll-ad'));
        }

        // 校验签名（若存在）
        if (isset($temp_order_data['signature'])) {
            $data_to_verify = $temp_order_data;
            $received_sign  = $data_to_verify['signature'];
            unset($data_to_verify['signature']);

            $serialized = maybe_serialize($data_to_verify);
            if (defined('AUTH_KEY') && AUTH_KEY) {
                $secret_key = AUTH_KEY;
            } else {
                global $wpdb;
                $secret_key = $wpdb->prefix . 'zibll_ad_secret';
            }
            $expected_sign = hash_hmac('sha256', $serialized, $secret_key);
            if (!hash_equals($expected_sign, $received_sign)) {
                zibll_ad_log('Order token signature invalid', array('transient_key' => $transient_key));
                return new WP_Error('order_invalid', __('订单数据校验失败，请重试', 'zibll-ad'));
            }
        }

        // 交叉校验关键字段
        if (intval($temp_order_data['slot_id']) !== $slot_id || intval($temp_order_data['unit_key']) !== $unit_key) {
            zibll_ad_log('Order temp data mismatched with request', array(
                'req_slot_id' => $slot_id,
                'req_unit_key' => $unit_key,
                'tmp_slot_id' => isset($temp_order_data['slot_id']) ? $temp_order_data['slot_id'] : null,
                'tmp_unit_key' => isset($temp_order_data['unit_key']) ? $temp_order_data['unit_key'] : null,
            ));
            return new WP_Error('order_mismatch', __('订单数据不匹配，请重试', 'zibll-ad'));
        }

        // ============================================
        // 第3步: 加载 Slot 配置并验证
        // ============================================
        $slot_data = Zibll_Ad_Slot_Model::get($slot_id);
        if (!$slot_data) {
            return new WP_Error('slot_not_found', __('广告位不存在', 'zibll-ad'));
        }
        if (isset($slot_data['enabled']) && !$slot_data['enabled']) {
            return new WP_Error('slot_disabled', __('此广告位已禁用', 'zibll-ad'));
        }

        // ============================================
        // 第4步: 验证 Unit 状态
        // ============================================
        $unit = Zibll_Ad_Unit_Model::get_by_slot_and_key($slot_id, $unit_key);
        if (!$unit) {
            return new WP_Error('unit_not_found', __('广告位单元不存在', 'zibll-ad'));
        }
        if ($unit['status'] !== 'pending') {
            return new WP_Error('unit_not_available', __('此广告位已被占用', 'zibll-ad'));
        }

        // ============================================
        // 第5步: 验证支付方式（与主题一致）
        // ============================================
        $payment_method = isset($post_data['payment_method']) ? sanitize_text_field($post_data['payment_method']) : '';
        if (!$payment_method) {
            return new WP_Error('invalid_payment', __('请选择支付方式', 'zibll-ad'));
        }
        $allowed_methods = $this->get_allowed_payment_methods($slot_data);
        if (!isset($allowed_methods[$payment_method])) {
            return new WP_Error('invalid_payment', __('不支持的支付方式', 'zibll-ad'));
        }

        // ============================================
        // 第6步: 使用临时订单数据填充订单（已在后端计算价格并签名）
        // ============================================
        $total_price    = isset($temp_order_data['total_price']) ? round((float) $temp_order_data['total_price'], 2) : 0;
        $base_price     = isset($temp_order_data['base_price']) ? round((float) $temp_order_data['base_price'], 2) : 0;
        $color_price    = isset($temp_order_data['color_price']) ? round((float) $temp_order_data['color_price'], 2) : 0;
        $duration_months = isset($temp_order_data['duration_months']) ? (int) $temp_order_data['duration_months'] : 1;
        $plan_type      = isset($temp_order_data['plan_type']) ? $temp_order_data['plan_type'] : '';
        $ad_data        = isset($temp_order_data['ad_data']) ? $temp_order_data['ad_data'] : array();

        if ($total_price <= 0) {
            return new WP_Error('invalid_price', __('价格计算失败', 'zibll-ad'));
        }

        $__data['order_price'] = $total_price;
        $__data['product_id']  = "{$slot_id}:{$unit_key}";
        $__data['post_id']     = $slot_id;

        if (empty($__data['order_name'])) {
            $__data['order_name'] = sprintf(
                __('购买广告位 - %s (位置 %d)', 'zibll-ad'),
                isset($slot_data['title']) ? $slot_data['title'] : '',
                $unit_key + 1
            );
        }

        // 组装联系方式快照
        $customer_contact = array();
        if (!empty($ad_data['contact_type']) && !empty($ad_data['contact_value'])) {
            $customer_contact = array(
                'type'  => sanitize_text_field($ad_data['contact_type']),
                'value' => sanitize_text_field($ad_data['contact_value']),
            );
        }

        $__data['other']['zibll_ad_request'] = array(
            'slot_id'        => $slot_id,
            'unit_id'        => $unit['id'],
            'unit_key'       => $unit_key,
            'slot_title'     => isset($slot_data['title']) ? $slot_data['title'] : '',
            'slot_type'      => isset($slot_data['slot_type']) ? $slot_data['slot_type'] : '',
            'price_detail'   => array(
                'base_price'   => $base_price,
                'color_price'  => $color_price,
                'total_price'  => $total_price,
                'duration_months' => $duration_months,
                'plan_type'    => $plan_type,
            ),
            'total_price'    => $total_price,
            'duration_months'=> $duration_months,
            'plan_type'      => $plan_type,
            'ad_data'        => $ad_data,
            'customer_contact' => $customer_contact,
            'transient_key'  => $transient_key,
        );

        zibll_ad_log('Order data filled successfully', array(
            'order_price' => $__data['order_price'],
            'product_id'  => $__data['product_id'],
            'post_id'     => $__data['post_id'],
            'order_name'  => $__data['order_name'],
        ));

        // PHP 7.2 兼容性诊断：打印 other 字段的详细信息
        zibll_ad_log('Order other field diagnostic (PHP ' . PHP_VERSION . ')', array(
            'has_other' => isset($__data['other']),
            'other_type' => isset($__data['other']) ? gettype($__data['other']) : 'not_set',
            'other_is_array' => isset($__data['other']) && is_array($__data['other']),
            'other_keys' => isset($__data['other']) && is_array($__data['other']) ? array_keys($__data['other']) : 'n/a',
            'has_zibll_ad_request' => isset($__data['other']['zibll_ad_request']),
            'zibll_ad_request_keys' => isset($__data['other']['zibll_ad_request']) && is_array($__data['other']['zibll_ad_request']) ? array_keys($__data['other']['zibll_ad_request']) : 'n/a',
            'other_serialized' => isset($__data['other']) ? maybe_serialize($__data['other']) : 'not_set',
            'other_serialized_length' => isset($__data['other']) ? strlen(maybe_serialize($__data['other'])) : 0,
        ));

        return $__data;
    }

    /**
     * 支付成功回调
     *
     * 当订单支付成功时被调用,负责:
     * 1. 更新 unit 状态为 paid
     * 2. 填充广告内容到 unit
     * 3. 插入 orders 表记录
     * 4. 清除缓存
     * 5. 删除临时订单数据
     *
     * @param object $order ZibPay 订单对象
     */
    public function on_payment_success($order) {
        zibll_ad_log('on_payment_success triggered (raw)', array(
            'type' => is_object($order) ? 'object' : (is_array($order) ? 'array' : gettype($order)),
        ));
        // 兼容不同触发方：可能传递 order 对象、数组或订单ID
        $order = $this->normalize_order_param($order);
        if (!$order) {
            zibll_ad_log('Payment success callback received but order is invalid');
            return;
        }

        // 判定是否为广告订单：优先依据 order_type，其次依据 other 中的 ad_request 或 product_id 形态
        $is_ad_type = (isset($order->order_type) && intval($order->order_type) === 31);
        $maybe_other = isset($order->other) ? maybe_unserialize($order->other) : array();
        $has_ad_request = is_array($maybe_other) && isset($maybe_other['zibll_ad_request']);
        $has_product_id_like = isset($order->product_id) && is_string($order->product_id) && preg_match('/^\d+:\d+$/', $order->product_id);
        if (!$is_ad_type && !$has_ad_request && !$has_product_id_like) {
            // 非广告订单，忽略
            return;
        }

        zibll_ad_log('Payment success callback triggered', array(
            'order_id' => $order->id,
            'order_num' => isset($order->order_num) ? $order->order_num : '',
            'order_type' => $order->order_type,
        ));

        // ============================================
        // 第1步: 提取广告业务数据
        // ============================================
        // 兼容主题：$order->other 为序列化字符串，需要反序列化
        $other = isset($order->other) ? maybe_unserialize($order->other) : array();
        $other = is_array($other) ? $other : array();
        $ad_request = isset($other['zibll_ad_request']) ? $other['zibll_ad_request'] : array();

        // PHP 7.2 兼容性诊断：打印从订单中读取到的 other 字段
        zibll_ad_log('Payment success: order.other diagnostic (PHP ' . PHP_VERSION . ')', array(
            'order_id' => $order->id,
            'has_other_raw' => isset($order->other),
            'other_raw_type' => isset($order->other) ? gettype($order->other) : 'not_set',
            'other_raw_length' => isset($order->other) && is_string($order->other) ? strlen($order->other) : (isset($order->other) ? 'not_string' : 0),
            'other_raw_preview' => isset($order->other) && is_string($order->other) ? substr($order->other, 0, 200) : (isset($order->other) ? gettype($order->other) : 'not_set'),
            'other_unserialized_type' => gettype($other),
            'other_is_array' => is_array($other),
            'other_keys' => is_array($other) ? array_keys($other) : 'not_array',
            'has_zibll_ad_request' => isset($other['zibll_ad_request']),
            'ad_request_type' => gettype($ad_request),
            'ad_request_empty' => empty($ad_request),
        ));

        if (empty($ad_request)) {
            // Fallback: 从 product_id 或 post_id 推导 slot/unit，并读取 pending 单元数据
            $product_id = isset($order->product_id) ? $order->product_id : '';
            $post_slot_id = isset($order->post_id) ? intval($order->post_id) : 0;
            $slot_id = 0;
            $unit_key = null;
            if ($product_id && strpos($product_id, ':') !== false) {
                list($slot_part, $unit_part) = explode(':', $product_id, 2);
                $slot_id = intval($slot_part);
                $unit_key = intval($unit_part);
            } elseif ($post_slot_id) {
                $slot_id = $post_slot_id;
            }

            if ($slot_id && $unit_key !== null) {
                $unit = Zibll_Ad_Unit_Model::get_by_slot_and_key($slot_id, $unit_key);
            if ($unit) {
                $fallback_duration = isset($unit['duration_months']) ? intval($unit['duration_months']) : 1;
                $unit_update_data = array(
                    'order_id' => isset($order->id) ? intval($order->id) : 0,
                    'order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
                    'price' => isset($unit['price']) ? floatval($unit['price']) : 0,
                    'duration_months' => $fallback_duration,
                    'customer_name' => isset($unit['customer_name']) ? sanitize_text_field($unit['customer_name']) : '',
                    'website_name' => isset($unit['website_name']) ? sanitize_text_field($unit['website_name']) : '',
                    'website_url' => isset($unit['website_url']) ? esc_url_raw($unit['website_url']) : '',
                    'contact_type' => isset($unit['contact_type']) ? sanitize_text_field($unit['contact_type']) : '',
                    'contact_value' => isset($unit['contact_value']) ? sanitize_text_field($unit['contact_value']) : '',
                    'color_key' => isset($unit['color_key']) ? sanitize_text_field($unit['color_key']) : '',
                    'image_id' => isset($unit['image_id']) ? intval($unit['image_id']) : 0,
                    'image_url' => isset($unit['image_url']) ? esc_url_raw($unit['image_url']) : '',
                    'text_content' => isset($unit['text_content']) ? sanitize_text_field($unit['text_content']) : '',
                    'target_url' => isset($unit['target_url']) ? esc_url_raw($unit['target_url']) : '',
                );

                $set_result = Zibll_Ad_Unit_Model::set_paid($unit['id'], $unit_update_data, $fallback_duration);
                zibll_ad_log('Fallback set_paid used (no ad_request found)', array(
                    'slot_id' => $slot_id,
                    'unit_key' => $unit_key,
                    'unit_id' => $unit['id'],
                    'result' => $set_result,
                ));

                if ($set_result) {
                    // 写入订单记录（基础信息）
                    global $wpdb;
                    $table_orders = $wpdb->prefix . 'zibll_ad_orders';

                    $total_price = 0;
                    if (isset($order->order_price)) {
                        $total_price = floatval($order->order_price);
                    } elseif (isset($order->pay_price)) {
                        $total_price = floatval($order->pay_price);
                    } elseif (isset($unit['price'])) {
                        $total_price = floatval($unit['price']);
                    }

                    $customer_snapshot = array(
                        'customer_name' => $unit_update_data['customer_name'],
                        'contact_type'  => $unit_update_data['contact_type'],
                        'contact_value' => $unit_update_data['contact_value'],
                        'website_name'  => $unit_update_data['website_name'],
                        'website_url'   => $unit_update_data['website_url'],
                    );

                    // 去重：如已有相同 zibpay_order_id 则不重复插入
                    $zibpay_id = isset($order->id) ? intval($order->id) : 0;
                    $exists = 0;
                    if ($zibpay_id) {
                        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_orders} WHERE zibpay_order_id = %d", $zibpay_id));
                    }

                    if ($exists === 0) {
                        $wpdb->insert($table_orders, array(
                            'unit_id'          => $unit['id'],
                            'slot_id'          => $slot_id,
                            'zibpay_order_id'  => $zibpay_id,
                            'zibpay_payment_id'=> isset($order->pay_id) ? intval($order->pay_id) : 0,
                            'zibpay_order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
                            'user_id'          => isset($order->user_id) ? intval($order->user_id) : 0,
                            'customer_snapshot'=> maybe_serialize($customer_snapshot),
                            'plan_type'        => 'custom',
                            'duration_months'  => $fallback_duration,
                            'base_price'       => $total_price,
                            'color_price'      => 0,
                            'total_price'      => $total_price,
                            'payment_method'   => isset($order->pay_type) ? sanitize_text_field($order->pay_type) : '',
                            'pay_status'       => 'paid',
                            'created_at'       => current_time('mysql'),
                            'paid_at'          => current_time('mysql'),
                        ));

                        zibll_ad_log('Order record inserted via fallback path', array(
                            'insert_id' => $wpdb->insert_id,
                            'zibpay_order_id' => $zibpay_id,
                        ));
                    } else {
                        // 若记录已存在（多数为我们之前在 order_created 阶段写入的 pending），此处应更新为已支付
                        $update_fields = array(
                            'zibpay_payment_id'=> isset($order->pay_id) ? intval($order->pay_id) : 0,
                            'zibpay_order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
                            'payment_method'   => isset($order->pay_type) ? sanitize_text_field($order->pay_type) : '',
                            'pay_status'       => 'paid',
                            'paid_at'          => current_time('mysql'),
                        );
                        $wpdb->update($table_orders, $update_fields, array('zibpay_order_id' => $zibpay_id));
                        zibll_ad_log('Order record updated to paid (fallback)', array('zibpay_order_id' => $zibpay_id));
                    }

                    // 清除缓存
                    zibll_ad_clear_slot_cache($slot_id);
                }
                } else {
                    zibll_ad_log('Fallback unit not found by product_id', array(
                        'slot_id' => $slot_id,
                        'unit_key' => $unit_key,
                        'product_id' => $product_id,
                    ));
                }
            } else {
                zibll_ad_log('Ad request data not found in order and no fallback available', array(
                    'order_id' => $order->id,
                    'order_other' => isset($order->other) ? $order->other : null,
                    'product_id' => $product_id,
                    'post_id' => $post_slot_id,
                ));
            }
            return;
        }

        $slot_id = isset($ad_request['slot_id']) ? intval($ad_request['slot_id']) : 0;
        $unit_id = isset($ad_request['unit_id']) ? intval($ad_request['unit_id']) : 0;
        $unit_key = isset($ad_request['unit_key']) ? intval($ad_request['unit_key']) : 0;
        $duration_months = isset($ad_request['duration_months']) ? intval($ad_request['duration_months']) : 1;

        if (!$slot_id || !$unit_id) {
            zibll_ad_log('Invalid slot_id or unit_id in ad_request', array(
                'slot_id' => $slot_id,
                'unit_id' => $unit_id,
            ));
            return;
        }

        // ============================================
        // 第2步: 准备广告内容数据
        // ============================================
        $ad_data = isset($ad_request['ad_data']) ? $ad_request['ad_data'] : array();

        $unit_update_data = array(
            // 订单信息
            'order_id' => isset($order->id) ? intval($order->id) : 0,
            'order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
            'price' => isset($ad_request['total_price']) ? floatval($ad_request['total_price']) : 0,
            'duration_months' => $duration_months,

            // 客户信息
            'customer_name' => isset($ad_data['customer_name']) ? sanitize_text_field($ad_data['customer_name']) : '',
            'website_name' => isset($ad_data['website_name']) ? sanitize_text_field($ad_data['website_name']) : '',
            'website_url' => isset($ad_data['website_url']) ? esc_url_raw($ad_data['website_url']) : '',

            // 联系方式
            'contact_type' => isset($ad_data['contact_type']) ? sanitize_text_field($ad_data['contact_type']) : '',
            'contact_value' => isset($ad_data['contact_value']) ? sanitize_text_field($ad_data['contact_value']) : '',

            // 广告内容(根据 slot_type 选择性填充)
            'color_key' => isset($ad_data['color_key']) ? sanitize_text_field($ad_data['color_key']) : '',
            'image_id' => isset($ad_data['image_id']) ? intval($ad_data['image_id']) : 0,
            'image_url' => isset($ad_data['image_url']) ? esc_url_raw($ad_data['image_url']) : '',
            'text_content' => isset($ad_data['text_content']) ? sanitize_text_field($ad_data['text_content']) : '',
            'target_url' => isset($ad_data['target_url']) ? esc_url_raw($ad_data['target_url']) : '',
        );

        // ============================================
        // 第3步: 更新 unit 状态为 paid
        // ============================================
        $result = Zibll_Ad_Unit_Model::set_paid($unit_id, $unit_update_data, $duration_months);

        if (!$result) {
            zibll_ad_log('Failed to update unit to paid status', array(
                'unit_id' => $unit_id,
                'unit_update_data' => $unit_update_data,
            ));
            return;
        }

        zibll_ad_log('Unit updated to paid status', array(
            'unit_id' => $unit_id,
            'duration_months' => $duration_months,
        ));

        // ============================================
        // 第4步: 更新/插入 orders 表记录
        // ============================================
        global $wpdb;
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';

        $customer_contact = isset($ad_request['customer_contact']) ? $ad_request['customer_contact'] : array();

        // 兼容：价格、套餐信息可能保存于 price_detail 中
        $price_detail = isset($ad_request['price_detail']) && is_array($ad_request['price_detail']) ? $ad_request['price_detail'] : array();
        $base_price_v  = isset($ad_request['base_price']) ? floatval($ad_request['base_price']) : (isset($price_detail['base_price']) ? floatval($price_detail['base_price']) : 0);
        $color_price_v = isset($ad_request['color_price']) ? floatval($ad_request['color_price']) : (isset($price_detail['color_price']) ? floatval($price_detail['color_price']) : 0);
        $plan_type_v   = isset($ad_request['plan_type']) ? sanitize_text_field($ad_request['plan_type']) : (isset($price_detail['plan_type']) ? sanitize_text_field($price_detail['plan_type']) : 'custom');

        $order_record = array(
            'unit_id' => $unit_id,
            'slot_id' => $slot_id,
            'zibpay_order_id' => isset($order->id) ? intval($order->id) : 0,
            'zibpay_payment_id' => isset($order->pay_id) ? intval($order->pay_id) : 0,
            'zibpay_order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
            'user_id' => isset($order->user_id) ? intval($order->user_id) : 0,

            // 客户快照(序列化存储,包含联系方式等隐私信息)
            'customer_snapshot' => maybe_serialize(array(
                'customer_name' => $unit_update_data['customer_name'],
                'contact_type' => $unit_update_data['contact_type'],
                'contact_value' => $unit_update_data['contact_value'],
                'website_name' => $unit_update_data['website_name'],
                'website_url' => $unit_update_data['website_url'],
            )),

            // 套餐信息
            'plan_type' => $plan_type_v,
            'duration_months' => $duration_months,

            // 价格明细
            'base_price' => $base_price_v,
            'color_price' => $color_price_v,
            'total_price' => isset($ad_request['total_price']) ? floatval($ad_request['total_price']) : 0,

            // 支付信息
            'payment_method' => isset($order->pay_type) ? sanitize_text_field($order->pay_type) : '',
            'pay_status' => 'paid',

            // 时间戳
            'created_at' => current_time('mysql'),
            'paid_at' => current_time('mysql'),
        );
        $attempt_token = isset($ad_request['transient_key']) ? sanitize_text_field($ad_request['transient_key']) : '';
        $updated = 0;
        if (!empty($attempt_token)) {
            $update_data = $order_record;
            $update_data['paid_at'] = current_time('mysql');
            // 使用 attempt_token 精确命中当次下单的 pending 记录
            $updated = $wpdb->update(
                $table_orders,
                $update_data,
                array('attempt_token' => $attempt_token, 'pay_status' => 'pending')
            );
            if ($updated) {
                zibll_ad_log('Pending order matched and updated to paid', array(
                    'attempt_token' => $attempt_token,
                    'affected_rows' => $updated,
                ));
            } else {
                zibll_ad_log('No pending order matched by attempt_token, will insert new', array(
                    'attempt_token' => $attempt_token,
                    'wpdb_error' => $wpdb->last_error,
                ));
            }
        }

        if (!$updated) {
            // 避免重复插入（兼容多钩子或重复回调）
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_orders} WHERE zibpay_order_id = %d", isset($order->id) ? intval($order->id) : 0));
            if ($exists > 0) {
                // 更新已存在但仍为 pending 的记录为已支付
                $update_record = $order_record;
                // 保留原始 created_at，不覆盖
                unset($update_record['created_at']);
                $update_record['paid_at'] = current_time('mysql');
                $wpdb->update($table_orders, $update_record, array('zibpay_order_id' => intval($order->id)));
                zibll_ad_log('Order record exists, updated to paid', array('zibpay_order_id' => isset($order->id) ? $order->id : 0));
            } else {
                $insert_result = $wpdb->insert($table_orders, $order_record);

                if ($insert_result === false) {
                    zibll_ad_log('Failed to insert order record', array(
                        'wpdb_error' => $wpdb->last_error,
                        'order_record' => $order_record,
                    ));
                } else {
                    zibll_ad_log('Order record inserted successfully', array(
                        'order_record_id' => $wpdb->insert_id,
                    ));
                }
            }
        }

        // ============================================
        // 第5步: 清除缓存
        // ============================================
        zibll_ad_clear_slot_cache($slot_id);

        // ============================================
        // 第6步: 删除临时订单数据
        // ============================================
        if (isset($ad_request['transient_key'])) {
            delete_transient($ad_request['transient_key']);
            zibll_ad_log('Temporary order data deleted', array(
                'transient_key' => $ad_request['transient_key'],
            ));
        }

        // ============================================
        // 第7步: (可选) 发送通知
        // ============================================
        // 这里可以集成主题的 ZibMsg::send() 或 WordPress 邮件
        // 通知管理员有新的广告订单
        do_action('zibll_ad_payment_success', array(
            'order' => $order,
            'slot_id' => $slot_id,
            'unit_id' => $unit_id,
            'ad_request' => $ad_request,
        ));

        zibll_ad_log('Payment success callback completed', array(
            'order_id' => $order->id,
            'slot_id' => $slot_id,
            'unit_id' => $unit_id,
        ));
    }

    /**
     * 归一化不同来源的 $order 参数
     *
     * @param mixed $order 订单对象/数组/ID
     * @return object|null 标准对象，最少包含 id/order_num/order_type/other/pay_id/pay_type/user_id
     */
    private function normalize_order_param($order) {
        // 对象：直接返回
        if (is_object($order)) {
            return $order;
        }

        // 数组：转对象
        if (is_array($order)) {
            return (object) $order;
        }

        // 数字：尝试从 ZibPay 订单表读取
        if (is_numeric($order)) {
            global $wpdb;
            $table = $wpdb->prefix . 'zibpay_order';
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", intval($order)));
            if ($row) {
                // 兼容字段命名差异
                if (!isset($row->order_type) && isset($row->type)) {
                    $row->order_type = $row->type;
                }
                if (!isset($row->pay_type) && isset($row->payment)) {
                    $row->pay_type = $row->payment;
                }
                return $row;
            }
        }

        return null;
    }

    /**
     * 订单关闭/退款回调
     *
     * 当订单被关闭或退款时被调用,负责:
     * 1. 释放 pending 状态的 unit
     * 2. 处理 paid 状态的退款(恢复 available)
     * 3. 更新 orders 表状态
     * 4. 清除缓存
     *
     * @param object $order ZibPay 订单对象
     */
    public function on_order_closed($order) {
        // 兼容不同触发方：可能传递 order 对象、数组或订单ID
        $order = $this->normalize_order_param($order);
        if (!$order) {
            return;
        }

        // 非广告订单忽略（若能通过 ad_request/product_id 判定也接受）
        $is_ad_type = (isset($order->order_type) && intval($order->order_type) === 31);
        $maybe_other = isset($order->other) ? maybe_unserialize($order->other) : array();
        $has_ad_request = is_array($maybe_other) && isset($maybe_other['zibll_ad_request']);
        $has_product_id_like = isset($order->product_id) && is_string($order->product_id) && preg_match('/^\d+:\d+$/', $order->product_id);
        if (!$is_ad_type && !$has_ad_request && !$has_product_id_like) {
            return;
        }

        zibll_ad_log('Order closed callback triggered', array(
            'order_id' => isset($order->id) ? $order->id : 'unknown',
            'order_num' => isset($order->order_num) ? $order->order_num : '',
        ));

        // 提取广告业务数据
        $other = isset($order->other) ? maybe_unserialize($order->other) : array();
        $other = is_array($other) ? $other : array();
        $ad_request = isset($other['zibll_ad_request']) ? $other['zibll_ad_request'] : array();

        if (empty($ad_request)) {
            zibll_ad_log('Ad request data not found in closed order');
            return;
        }

        $slot_id = isset($ad_request['slot_id']) ? intval($ad_request['slot_id']) : 0;
        $unit_id = isset($ad_request['unit_id']) ? intval($ad_request['unit_id']) : 0;

        if (!$unit_id) {
            zibll_ad_log('Invalid unit_id in closed order', array('ad_request' => $ad_request));
            return;
        }

        // 获取当前 unit 状态
        $unit = Zibll_Ad_Unit_Model::get($unit_id);

        if (!$unit) {
            zibll_ad_log('Unit not found for closed order', array('unit_id' => $unit_id));
            return;
        }

        // 根据当前状态处理
        if ($unit['status'] === 'pending') {
            // 未支付的订单关闭,释放锁定
            Zibll_Ad_Unit_Model::set_available($unit_id, true);
            zibll_ad_log('Pending unit released due to order closed', array(
                'unit_id' => $unit_id,
                'status' => 'pending -> available',
            ));
        } elseif ($unit['status'] === 'paid') {
            // 已支付的订单关闭(退款场景),恢复为可用
            Zibll_Ad_Unit_Model::set_available($unit_id, true);

            // 更新 orders 表记录
            global $wpdb;
            $table_orders = $wpdb->prefix . 'zibll_ad_orders';

            $wpdb->update(
                $table_orders,
                array(
                    'pay_status' => 'refunded',
                    'closed_at' => current_time('mysql'),
                ),
                array('zibpay_order_id' => isset($order->id) ? intval($order->id) : 0),
                array('%s', '%s'),
                array('%d')
            );

            zibll_ad_log('Paid unit refunded and released', array(
                'unit_id' => $unit_id,
                'status' => 'paid -> available',
                'order_id' => isset($order->id) ? $order->id : 'unknown',
            ));
        }

        // 清除缓存
        if ($slot_id) {
            zibll_ad_clear_slot_cache($slot_id);
        }

        // 删除临时数据
        if (isset($ad_request['transient_key'])) {
            delete_transient($ad_request['transient_key']);
        }

        do_action('zibll_ad_order_closed', array(
            'order' => $order,
            'slot_id' => $slot_id,
            'unit_id' => $unit_id,
        ));
    }

    /**
     * 过滤支付方式
     *
     * 实现业务规则:
     * 1. 未登录用户禁用余额支付
     * 2. 支持 slot 级别的支付方式白名单限制
     *
     * @param array $methods  支付方式数组
     * @param int   $pay_type 订单类型
     * @return array 过滤后的支付方式
     */
    public function filter_payment_methods($methods, $pay_type) {
        // 只处理广告订单
        if (intval($pay_type) !== 31) {
            return $methods;
        }

        zibll_ad_log('Filtering payment methods for ad order', array(
            'original_methods' => array_keys($methods),
            'pay_type' => $pay_type,
            'is_user_logged_in' => is_user_logged_in(),
        ));

        // ============================================
        // 规则1: 未登录用户禁用余额支付
        // ============================================
        if (!is_user_logged_in() && isset($methods['balance'])) {
            unset($methods['balance']);
            zibll_ad_log('Balance payment disabled for guest user');
        }

        // ============================================
        // 规则2: Slot 级别的支付方式限制
        // ============================================
        // 从 $_POST 中读取 slot_id (此时还在订单创建前)
        $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;

        if ($slot_id) {
            $slot_data = Zibll_Ad_Slot_Model::get($slot_id);

            if ($slot_data && !empty($slot_data['payment_methods_override']) && is_array($slot_data['payment_methods_override'])) {
                $allowed_methods = $slot_data['payment_methods_override'];

                // 只保留白名单中的支付方式
                $methods = array_intersect_key($methods, array_flip($allowed_methods));

                zibll_ad_log('Payment methods restricted by slot configuration', array(
                    'slot_id' => $slot_id,
                    'allowed_methods' => $allowed_methods,
                    'filtered_methods' => array_keys($methods),
                ));
            }
        }

        return $methods;
    }

    /**
     * 获取允许的支付方式列表
     *
     * 结合主题配置和 slot 配置,返回最终允许的支付方式
     *
     * @param array $slot_data Slot 配置数据
     * @return array 允许的支付方式
     */
    private function get_allowed_payment_methods($slot_data) {
        // 获取主题全局支持的支付方式
        $global_methods = array();

        if (function_exists('zibpay_get_payment_methods')) {
            $global_methods = zibpay_get_payment_methods(31);
        }

        // 插件全局：允许余额支付（若关闭则移除 balance）
        if (function_exists('zibll_ad_get_option')) {
            $allow_balance = (bool) zibll_ad_get_option('allow_balance_payment', true);
            if (!$allow_balance && isset($global_methods['balance'])) {
                unset($global_methods['balance']);
            }
        }

        // 如果 slot 有支付方式限制,取交集
        if (!empty($slot_data['payment_methods_override']) && is_array($slot_data['payment_methods_override'])) {
            $global_methods = array_intersect_key(
                $global_methods,
                array_flip($slot_data['payment_methods_override'])
            );
        }

        // 未登录用户移除余额支付
        if (!is_user_logged_in() && isset($global_methods['balance'])) {
            unset($global_methods['balance']);
        }

        return $global_methods;
    }

    /**
     * 订单创建回调：在创建时即补齐订单号/订单ID到插件表的 pending 记录
     *
     * @param mixed $order 订单对象/数组/ID
     */
    public function on_order_created($order) {
        // 归一化到订单对象（含 id/order_num/order_type/other/user_id/order_price）
        $order = $this->normalize_order_param($order);
        if (!$order) {
            return;
        }

        // 仅处理广告订单或带有我们标记的订单
        $is_ad_type = (isset($order->order_type) && intval($order->order_type) === 31);
        $other = isset($order->other) ? maybe_unserialize($order->other) : array();
        $has_ad_request = is_array($other) && isset($other['zibll_ad_request']);
        $has_product_id_like = isset($order->product_id) && is_string($order->product_id) && preg_match('/^\d+:\d+$/', $order->product_id);
        if (!$is_ad_type && !$has_ad_request && !$has_product_id_like) {
            return;
        }

        $ad_request = $has_ad_request ? $other['zibll_ad_request'] : array();
        $attempt_token = isset($ad_request['transient_key']) ? sanitize_text_field($ad_request['transient_key']) : '';
        $slot_id = isset($ad_request['slot_id']) ? intval($ad_request['slot_id']) : (isset($order->post_id) ? intval($order->post_id) : 0);
        $unit_id = isset($ad_request['unit_id']) ? intval($ad_request['unit_id']) : 0;

        // 更新插件订单表中的 pending 记录，补齐 order_id 与 order_num
        global $wpdb;
        $table_orders = $wpdb->prefix . 'zibll_ad_orders';

        $update = array(
            'zibpay_order_id'  => isset($order->id) ? intval($order->id) : 0,
            'zibpay_order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
            'user_id'          => isset($order->user_id) ? intval($order->user_id) : 0,
        );

        $updated_rows = 0;
        if (!empty($attempt_token)) {
            $updated_rows = $wpdb->update(
                $table_orders,
                $update,
                array('attempt_token' => $attempt_token, 'pay_status' => 'pending')
            );
        }

        if (!$updated_rows) {
            // Fallback：按 unit_id/slot_id 匹配最近 pending 记录
            $where_parts = array("pay_status = 'pending'");
            $params = array();
            if ($unit_id) { $where_parts[] = 'unit_id = %d'; $params[] = $unit_id; }
            if ($slot_id) { $where_parts[] = 'slot_id = %d'; $params[] = $slot_id; }
            $where = implode(' AND ', $where_parts);
            $sql_id = "SELECT id FROM {$table_orders} WHERE {$where} ORDER BY id DESC LIMIT 1";
            $row_id = $params ? $wpdb->get_var($wpdb->prepare($sql_id, $params)) : $wpdb->get_var($sql_id);
            if ($row_id) {
                $wpdb->update($table_orders, $update, array('id' => intval($row_id)));
                $updated_rows = $wpdb->rows_affected;
            }
        }

        if (!$updated_rows) {
            // 若无待支付记录（异常流），插入一条 pending 记录用于后台可见与统计
            $ad = isset($ad_request['ad_data']) && is_array($ad_request['ad_data']) ? $ad_request['ad_data'] : array();
            $cs = array(
                'customer_name' => isset($ad['website_name']) ? sanitize_text_field($ad['website_name']) : '',
                'contact_type'  => isset($ad['contact_type']) ? sanitize_text_field($ad['contact_type']) : '',
                'contact_value' => isset($ad['contact_value']) ? sanitize_text_field($ad['contact_value']) : '',
                'website_name'  => isset($ad['website_name']) ? sanitize_text_field($ad['website_name']) : '',
                'website_url'   => isset($ad['website_url']) ? esc_url_raw($ad['website_url']) : '',
            );
            $insert = array(
                'unit_id'          => $unit_id,
                'slot_id'          => $slot_id,
                'zibpay_order_id'  => isset($order->id) ? intval($order->id) : 0,
                'zibpay_order_num' => isset($order->order_num) ? sanitize_text_field($order->order_num) : '',
                'user_id'          => isset($order->user_id) ? intval($order->user_id) : 0,
                'customer_snapshot'=> maybe_serialize($cs),
                'plan_type'        => 'custom',
                'duration_months'  => isset($ad_request['duration_months']) ? intval($ad_request['duration_months']) : 1,
                'base_price'       => isset($order->order_price) ? floatval($order->order_price) : 0,
                'color_price'      => 0,
                'total_price'      => isset($order->order_price) ? floatval($order->order_price) : 0,
                'payment_method'   => isset($order->pay_type) ? sanitize_text_field($order->pay_type) : '',
                'pay_status'       => 'pending',
                'created_at'       => current_time('mysql'),
            );
            $wpdb->insert($table_orders, $insert);
        }

        zibll_ad_log('Order created synced to plugin table', array(
            'order_id'  => isset($order->id) ? $order->id : null,
            'order_num' => isset($order->order_num) ? $order->order_num : null,
            'updated_rows' => $updated_rows,
        ));
    }
}

// 实例化并初始化
new Zibll_Ad_Order_Sync();
