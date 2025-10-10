<?php
/**
 * 核心插件类
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Plugin {

    /**
     * 单例实例
     *
     * @var Zibll_Ad_Plugin
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Zibll_Ad_Plugin
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数（私有，防止外部实例化）
     */
    private function __construct() {
        // 构造函数为私有，确保单例模式
    }

    /**
     * 初始化插件
     */
    public function init() {
        // 挂载 plugins_loaded 钩子
        add_action('plugins_loaded', array($this, 'plugins_loaded'));
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('Zibll_Ad_Plugin::init registered plugins_loaded');
        }
    }

    /**
     * 插件加载后执行
     */
    public function plugins_loaded() {
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('Zibll_Ad_Plugin::plugins_loaded start');
        }
        // 检查依赖：判断 Zibll 主题是否激活
        if (!$this->check_dependencies()) {
            add_action('admin_notices', array($this, 'dependency_notice'));
            return;
        }

        // 加载文本域（国际化）
        load_plugin_textdomain('zibll-ad', false, dirname(plugin_basename(ZIBLL_AD_PATH)) . '/languages');

        // 数据库升级（如需要）
        if (class_exists('Zibll_Ad_Install') && method_exists('Zibll_Ad_Install', 'maybe_upgrade')) {
            Zibll_Ad_Install::maybe_upgrade();
        }

        // 加载依赖模块
        $this->load_dependencies();
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('Zibll_Ad_Plugin::plugins_loaded dependencies loaded');
        }

        // 注册自定义文章类型
        add_action('init', array($this, 'register_post_types'));
    }

    /**
     * 检查依赖
     *
     * @return bool
     */
    private function check_dependencies() {
        // 检查当前主题是否为 Zibll 主题
        $current_theme = wp_get_theme();
        $theme_name = $current_theme->get('Name');
        $parent_theme = $current_theme->parent();

        // 检查主题名称或父主题名称是否包含 "zibll" 或 "子比"
        if (stripos($theme_name, 'zibll') !== false || stripos($theme_name, '子比') !== false) {
            return true;
        }

        if ($parent_theme && (stripos($parent_theme->get('Name'), 'zibll') !== false || stripos($parent_theme->get('Name'), '子比') !== false)) {
            return true;
        }

        // 检查主题是否定义了关键的 ZibPay 函数
        if (function_exists('_pz') && function_exists('zibpay_get_payment_methods')) {
            return true;
        }

        return false;
    }

    /**
     * 显示依赖提示
     */
    public function dependency_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('子比自助广告位插件', 'zibll-ad'); ?></strong>:
                <?php _e('此插件需要安装并激活子比主题（Zibll Theme）才能正常运行。', 'zibll-ad'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * 加载依赖模块
     */
    private function load_dependencies() {
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('Zibll_Ad_Plugin::load_dependencies');
        }
        // 加载数据模型
        require_once ZIBLL_AD_PATH . 'includes/class-slot-model.php';
        require_once ZIBLL_AD_PATH . 'includes/class-unit-model.php';
        require_once ZIBLL_AD_PATH . 'includes/class-order-model.php';

        // 加载 Widget 管理器
        require_once ZIBLL_AD_PATH . 'includes/class-widget-manager.php';

        // 加载 Widget 类
        require_once ZIBLL_AD_PATH . 'includes/frontend/class-widget.php';

        // 加载短代码处理类
        require_once ZIBLL_AD_PATH . 'includes/class-shortcode.php';

        // 加载内容过滤器（让主题的文章插入内容支持短代码）
        require_once ZIBLL_AD_PATH . 'includes/class-content-filter.php';

        // 加载 REST API
        require_once ZIBLL_AD_PATH . 'includes/admin/class-admin-rest.php';

        // 加载后台菜单和资源管理
        if (is_admin()) {
            require_once ZIBLL_AD_PATH . 'includes/admin/class-admin-menu.php';
            require_once ZIBLL_AD_PATH . 'includes/admin/class-admin-assets.php';
        }

        // 加载前端模块
        require_once ZIBLL_AD_PATH . 'includes/frontend/class-ajax.php';
        require_once ZIBLL_AD_PATH . 'includes/frontend/class-frontend-assets.php';
        // 后端钩子优先用于修复与增强
        require_once ZIBLL_AD_PATH . 'includes/frontend/class-order-template-hooks.php';
        // 悬浮未支付订单区域的最小范围兜底（主题无后端钩子）
        require_once ZIBLL_AD_PATH . 'includes/frontend/class-order-float-fix.php';
        // 订单详情模态框的最小范围兜底（主题无后端钩子）
        require_once ZIBLL_AD_PATH . 'includes/frontend/class-order-modal-fix.php';

        // 注册 REST API 路由
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // 注册 Widget
        add_action('widgets_init', array($this, 'register_widgets'));

        // 初始化后台管理
        if (is_admin()) {
            $this->init_admin();
        }

        // 初始化前端
        $this->init_frontend();

        // 初始化短代码
        new Zibll_Ad_Shortcode();

        // 初始化内容过滤器
        new Zibll_Ad_Content_Filter();

        // 加载订单同步类 (ZibPay 集成)
        require_once ZIBLL_AD_PATH . 'includes/class-order-sync.php';
        // 加载订单对账补录（仅插件内逻辑，不改主题）
        require_once ZIBLL_AD_PATH . 'includes/class-order-reconcile.php';
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            zibll_ad_log('class-order-sync.php required');
        }

        // 加载到期清理 Runner（定时任务处理）
        require_once ZIBLL_AD_PATH . 'includes/cron/class-expire-runner.php';
        add_action('zibll_ad_cron_check_expire', array('Zibll_Ad_Expire_Runner', 'run'));
    }

    /**
     * 注册 REST API 路由
     */
    public function register_rest_routes() {
        $admin_rest = new Zibll_Ad_Admin_REST();
        $admin_rest->register_routes();
        // 调试路由（仅在开发或管理员使用）
        if (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE) {
            if (!class_exists('Zibll_Ad_Admin_Debug_REST')) {
                require_once ZIBLL_AD_PATH . 'includes/admin/class-admin-debug.php';
            }
            $debug_rest = new Zibll_Ad_Admin_Debug_REST();
            $debug_rest->register_routes();
            zibll_ad_log('Debug REST routes registered');
        }
    }

    /**
     * 注册 Widgets
     */
    public function register_widgets() {
        register_widget('Zibll_Ad_Widget');
    }

    /**
     * 初始化后台管理
     */
    private function init_admin() {
        // 初始化后台菜单
        new Zibll_Ad_Admin_Menu();

        // 初始化资源加载
        new Zibll_Ad_Admin_Assets();
    }

    /**
     * 初始化前端
     */
    private function init_frontend() {
        // 初始化前端AJAX处理
        new Zibll_Ad_Frontend_AJAX();

        // 初始化前端资源加载
        new Zibll_Ad_Frontend_Assets();

        // 后端订单卡片模板钩子（为广告订单提供稳定缩略图）
        new Zibll_Ad_Order_Template_Hooks();

        // 悬浮“未支付订单”浮窗的缩略图兜底修复
        new Zibll_Ad_Order_Float_Fix();
        // 订单详情模态框的缩略图兜底修复
        new Zibll_Ad_Order_Modal_Fix();

        // 链接重写（防止广告位 CPT 链接 404）：重写到挂载页面（通常是首页）
        if (!class_exists('Zibll_Ad_Permalink_Fixes')) {
            require_once ZIBLL_AD_PATH . 'includes/class-permalink-fixes.php';
        }
        new Zibll_Ad_Permalink_Fixes();
    }

    /**
     * 注册自定义文章类型
     */
    public function register_post_types() {
        // 注册广告位自定义文章类型
        register_post_type('zibll_ad_slot', array(
            'labels' => array(
                'name' => __('广告位', 'zibll-ad'),
                'singular_name' => __('广告位', 'zibll-ad'),
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ));

        // 注册自定义权限能力
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_zibll_ads');
        }
    }

}
