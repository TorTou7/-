<?php
/**
 * REST API 基础控制器
 *
 * 提供通用的权限检查、错误处理和响应格式化方法
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API 基础控制器类
 */
class Zibll_Ad_REST_Controller extends WP_REST_Controller {

    /**
     * 命名空间
     *
     * @var string
     */
    protected $namespace = 'zibll-ad/v1';

    /**
     * 检查用户是否有管理权限
     *
     * @return bool|WP_Error 有权限返回 true，无权限返回 WP_Error
     */
    public function check_permission() {
        if (!current_user_can('manage_zibll_ads')) {
            return new WP_Error(
                'rest_forbidden',
                __('抱歉，您没有权限执行此操作。', 'zibll-ad'),
                array('status' => 403)
            );
        }

        return true;
    }

    /**
     * 检查用户是否有查看权限（只读）
     *
     * 可用于需要区分读写权限的场景
     *
     * @return bool|WP_Error
     */
    public function check_read_permission() {
        // 目前和管理权限一致，后续可扩展为更细粒度的权限
        return $this->check_permission();
    }

    /**
     * 检查用户是否有修改权限（写入）
     *
     * @return bool|WP_Error
     */
    public function check_write_permission() {
        return $this->check_permission();
    }

    /**
     * 成功响应
     *
     * 统一包装成标准格式：{ success: true, data: {...}, message: '...' }
     * 这样前端可以统一处理，提高可维护性
     *
     * @param mixed  $data    响应数据
     * @param int    $status  HTTP 状态码
     * @param string $message 成功消息（可选）
     * @return WP_REST_Response
     */
    protected function success_response($data, $status = 200, $message = '') {
        $response_data = array(
            'success' => true,
            'data'    => $data,
        );

        // 如果提供了消息，添加到响应中
        if (!empty($message)) {
            $response_data['message'] = $message;
        }

        return new WP_REST_Response($response_data, $status);
    }

    /**
     * 错误响应
     *
     * 统一包装成标准格式：{ success: false, code: '...', message: '...', data: {...} }
     * 兼容 WordPress REST API 的 WP_Error 机制
     *
     * @param string $code    错误代码
     * @param string $message 错误消息
     * @param int    $status  HTTP 状态码
     * @param array  $data    额外数据
     * @return WP_Error
     */
    protected function error_response($code, $message, $status = 400, $data = array()) {
        return new WP_Error(
            $code,
            $message,
            array_merge(array('status' => $status), $data)
        );
    }

    /**
     * 验证必需参数
     *
     * @param WP_REST_Request $request 请求对象
     * @param array           $required_params 必需参数列表
     * @return bool|WP_Error 验证通过返回 true，失败返回 WP_Error
     */
    protected function validate_required_params($request, $required_params) {
        foreach ($required_params as $param) {
            if (!$request->has_param($param) || empty($request->get_param($param))) {
                return $this->error_response(
                    'missing_required_param',
                    sprintf(__('缺少必需参数：%s', 'zibll-ad'), $param),
                    400
                );
            }
        }

        return true;
    }

    /**
     * 清理字符串参数
     *
     * @param mixed           $value   参数值
     * @param WP_REST_Request $request 请求对象
     * @param string          $param   参数名
     * @return string
     */
    public function sanitize_text_param($value, $request, $param) {
        return sanitize_text_field($value);
    }

    /**
     * 清理 textarea 参数（保留换行）
     *
     * @param mixed           $value   参数值
     * @param WP_REST_Request $request 请求对象
     * @param string          $param   参数名
     * @return string
     */
    public function sanitize_textarea_param($value, $request, $param) {
        return sanitize_textarea_field($value);
    }

    /**
     * 验证枚举参数
     *
     * @param mixed  $value   参数值
     * @param array  $allowed 允许的值列表
     * @param string $param   参数名
     * @return bool|WP_Error
     */
    protected function validate_enum($value, $allowed, $param) {
        if (!in_array($value, $allowed, true)) {
            return $this->error_response(
                'invalid_param',
                sprintf(
                    __('参数 %s 的值无效，允许的值：%s', 'zibll-ad'),
                    $param,
                    implode(', ', $allowed)
                ),
                400
            );
        }

        return true;
    }

    /**
     * 验证正整数
     *
     * @param mixed  $value 参数值
     * @param string $param 参数名
     * @return bool|WP_Error
     */
    protected function validate_positive_integer($value, $param) {
        $int_value = intval($value);

        if ($int_value <= 0) {
            return $this->error_response(
                'invalid_param',
                sprintf(__('参数 %s 必须是正整数', 'zibll-ad'), $param),
                400
            );
        }

        return true;
    }

    /**
     * 记录 API 调用日志（调试用）
     *
     * @param string $endpoint 端点名称
     * @param string $method   HTTP 方法
     * @param array  $params   请求参数
     */
    protected function log_api_call($endpoint, $method, $params = array()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            zibll_ad_log(
                sprintf('REST API: %s %s', $method, $endpoint),
                array(
                    'user_id' => get_current_user_id(),
                    'params' => $params,
                )
            );
        }
    }
}
