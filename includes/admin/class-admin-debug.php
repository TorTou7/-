<?php
/**
 * 管理端调试控制器（仅开发/管理员使用）
 *
 * @package Zibll_Ad
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once ZIBLL_AD_PATH . 'includes/rest/class-rest-controller.php';

class Zibll_Ad_Admin_Debug_REST extends Zibll_Ad_REST_Controller {

    public function register_routes() {
        register_rest_route($this->namespace, '/orders/debug/simulate-success', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'simulate_success'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'order_id' => array(
                        'description' => __('ZibPay 订单ID', 'zibll-ad'),
                        'type' => 'integer',
                        'required' => true,
                    ),
                ),
            ),
        ));

        register_rest_route($this->namespace, '/orders/debug/reconcile', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'reconcile'),
                'permission_callback' => array($this, 'check_write_permission'),
                'args' => array(
                    'limit' => array(
                        'description' => __('最多处理条数', 'zibll-ad'),
                        'type' => 'integer',
                        'default' => 50,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ));
    }

    public function simulate_success($request) {
        $order_id = intval($request->get_param('order_id'));
        if ($order_id <= 0) {
            return $this->error_response('invalid_param', __('无效的 ZibPay 订单ID', 'zibll-ad'), 400);
        }

        if (!class_exists('Zibll_Ad_Order_Sync')) {
            require_once ZIBLL_AD_PATH . 'includes/class-order-sync.php';
        }

        zibll_ad_log('Debug simulate success called', array('order_id' => $order_id));

        $sync = new Zibll_Ad_Order_Sync();
        $sync->on_payment_success($order_id);

        return $this->success_response(array(
            'simulated' => true,
            'order_id' => $order_id,
        ));
    }

    public function reconcile($request) {
        $limit = absint($request->get_param('limit')) ?: 50;
        if (!class_exists('Zibll_Ad_Order_Reconcile')) {
            require_once ZIBLL_AD_PATH . 'includes/class-order-reconcile.php';
        }
        zibll_ad_log('Debug reconcile called', array('limit' => $limit));
        Zibll_Ad_Order_Reconcile::run($limit);
        return $this->success_response(array('reconciled' => true, 'limit' => $limit));
    }
}
