<?php
/**
 * 前端资源加载管理类
 *
 * 职责：
 * ====
 * 1. 加载Vue 3（复用主题资源）
 * 2. 加载Element Plus（复用主题资源）
 * 3. 加载WordPress媒体库（图片上传）
 * 4. 加载插件自定义JS和CSS
 * 5. 传递配置数据到前端
 *
 * 性能优化：
 * ========
 * 1. 仅在有广告位Widget的页面加载
 * 2. 复用主题已加载的Vue和Element Plus资源
 * 3. 使用wp_localize_script传递配置
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Frontend_Assets {

    /**
     * 构造函数 - 注册钩子
     */
    public function __construct() {
        // 提高优先级，确保在主题脚本之后加载（避免被 Vue 2 覆盖）
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 99);
    }

    /**
     * 加载前端资源
     */
    public function enqueue_assets() {
        // 检查当前页面是否有广告位Widget
        if (!$this->has_active_widgets()) {
            return;
        }

        // ========================================
        // 第一步：加载 Vue 3
        // ========================================
        $this->enqueue_vue();

        // ========================================
        // 第二步：加载 Element Plus
        // ========================================
        $this->enqueue_element_plus();

        // ========================================
        // 第三步：加载 WordPress 媒体库（图片上传）
        // ========================================
        wp_enqueue_media();

        // ========================================
        // 第四步：加载插件自定义CSS
        // ========================================
        wp_enqueue_style(
            'zibll-ad-frontend',
            ZIBLL_AD_URL . 'includes/frontend/assets/css/frontend.css',
            array(),
            ZIBLL_AD_VERSION,
            'all'
        );

        // ========================================
        // 第五步：加载插件自定义JS（步骤3.2）
        // ========================================
        wp_enqueue_script(
            'zibll-ad-frontend',
            ZIBLL_AD_URL . 'includes/frontend/assets/js/frontend.js',
            array('jquery', 'zibll-ad-vue', 'zibll-ad-element-plus'), // 强制依赖 Vue3 与 Element Plus
            ZIBLL_AD_VERSION,
            true // 在footer加载
        );

        // ========================================
        // 第六步：传递配置数据到前端
        // ========================================
        wp_localize_script(
            'zibll-ad-frontend',
            'zibllAdConfig',
            array(
                // AJAX URL
                'ajaxUrl' => admin_url('admin-ajax.php'),

                // REST API URL
                'restUrl' => esc_url_raw(rest_url('zibll-ad/v1')),

                // Nonce
                'nonce' => wp_create_nonce('wp_rest'),

                // 订单超时时间（分钟）
                'orderTimeout' => zibll_ad_get_order_timeout(),

                // 当前用户信息
                'currentUser' => array(
                    'loggedIn' => is_user_logged_in(),
                    'id' => get_current_user_id(),
                ),

                // 策略开关：是否允许上传图片（所有用户）
                'allowImageUpload' => (function(){
                    if (function_exists('zibll_ad_get_option')) {
                        return (bool) zibll_ad_get_option('allow_image_upload', true);
                    }
                    return true;
                })(),

                // 策略开关：是否允许游客上传图片（控制图片广告在未登录状态下的上传权限）
                'allowGuestImageUpload' => (function(){
                    if (function_exists('zibll_ad_get_option')) {
                        return (bool) zibll_ad_get_option('allow_guest_image_upload', true);
                    }
                    return true;
                })(),

                // 调试模式
                'debug' => (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE),
                'isDevMode' => (defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE),

                // 插件版本
                'version' => ZIBLL_AD_VERSION,

                // 插件资源：广告订单占位图（用于少数主题无钩子区域的兜底）
                'adOrderSvg' => ZIBLL_AD_URL . 'includes/frontend/assets/img/ad-order.svg',

                // 多语言文本
                'i18n' => array(
                    'purchaseTitle' => __('购买广告位', 'zibll-ad'),
                    'loading' => __('加载中...', 'zibll-ad'),
                    'error' => __('错误', 'zibll-ad'),
                    'success' => __('成功', 'zibll-ad'),
                    'confirm' => __('确认', 'zibll-ad'),
                    'cancel' => __('取消', 'zibll-ad'),
                    'submit' => __('提交订单', 'zibll-ad'),
                ),

                // 上传配置（允许的图片类型供前端使用）
                'upload' => (function() {
                    if (!function_exists('zibll_ad_get_option')) {
                        return array(
                            'allowedTypes' => array('jpg','jpeg','png','gif','webp'),
                            'accept' => '.jpg,.jpeg,.png,.gif,.webp',
                        );

                    }

                    $types = zibll_ad_get_option('image_allowed_types', array('jpg','jpeg','png','gif','webp'));
                    $types = is_array($types) ? array_values(array_unique(array_map('strtolower', $types))) : array();
                    if (empty($types)) {
                        $types = array('jpg','jpeg','png','gif','webp');
                    }
                    // 生成 accept 字符串（包含 .jpeg 兜底）
                    $accepts = array();
                    foreach ($types as $ext) {
                        if ($ext === 'jpg') {
                            $accepts[] = '.jpg';
                            $accepts[] = '.jpeg';
                        } else {
                            $accepts[] = '.' . $ext;
                        }
                    }
                    return array(
                        'allowedTypes' => $types,
                        'accept' => implode(',', array_unique($accepts)),
                    );
                })(),
            )
        );
        if (function_exists('wp_add_inline_script')) {
            $quiet_console_js = <<<'JS'
(function(){
    try {
        var cfg = window.zibllAdConfig || {};
        if (cfg.isDevMode || window.__zibllAdConsolePatched) {
            return;
        }
        var search = '';
        try { search = window.location.search || ''; } catch (e) {}
        var params = null;
        try { params = search ? new URLSearchParams(search) : null; } catch (e) {}
        if (params && params.has('zad_debug')) {
            return;
        }
        if (typeof console === 'undefined') {
            return;
        }
        window.__zibllAdConsolePatched = true;
        var prefix = /^\[Zibll Ad/;
        ['log','info','debug','warn'].forEach(function(method){
            var original = console[method];
            if (typeof original !== 'function') {
                return;
            }
            console[method] = function(){
                if (arguments.length && typeof arguments[0] === 'string' && prefix.test(arguments[0])) {
                    return;
                }
                return original.apply(console, arguments);
            };
        });
    } catch (e) {}
})();
JS;
            wp_add_inline_script('zibll-ad-frontend', $quiet_console_js, 'before');
        }

    }

    /**
     * 加载 Vue 3
     *
     * 优先使用主题已加载的Vue，如果主题未加载则从CDN加载
     */
    private function enqueue_vue() {
        // 不再复用主题的 Vue，始终强制加载 Vue 3（避免与主题 Vue 2 冲突）
        // 使用独立的句柄，确保依赖链准确
        // 重要：因为我们的代码使用了 template 字符串，需要运行时编译器
        // 根据环境选择合适的版本：
        // - 开发模式：使用 vue.global.js（开发版，有详细警告便于调试）
        // - 生产模式：使用 vue.global.prod.js（生产版，包含编译器但无警告）
        $is_dev_mode = defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE;
        $vue_file = $is_dev_mode ? 'vue.global.js' : 'vue.global.prod.js';
        
        wp_enqueue_script(
            'zibll-ad-vue',
            ZIBLL_AD_URL . 'includes/vendor/vue/' . $vue_file,
            array(),
            '3.4.0',
            false // Vue 建议在 head 中加载
        );

        // 立即设置别名，确保插件的 Vue 3 实例被保护，不受主题 Vue 2 影响
        // 使用 IIFE 确保在脚本加载后立即执行，并锁定引用
        $alias_js = '(function(){if(typeof window.Vue!=="undefined"&&window.Vue.version&&window.Vue.version.startsWith("3")){window.ZibllAdVue=window.Vue;Object.freeze(window.ZibllAdVue);}})();';
        if (function_exists('wp_add_inline_script')) {
            wp_add_inline_script('zibll-ad-vue', $alias_js, 'after');
        }
    }

    /**
     * 加载 Element Plus
     *
     * 优先使用主题已加载的Element Plus，如果主题未加载则从CDN加载
     */
    private function enqueue_element_plus() {
        // 不再复用主题的 Element Plus，确保版本与 Vue3 匹配
        wp_enqueue_script(
            'zibll-ad-element-plus',
            ZIBLL_AD_URL . 'includes/vendor/element-plus/index.full.min.js',
            array('zibll-ad-vue'),
            '2.3.0',
            false // 在 head 中加载，避免后续脚本引用时报未定义
        );

        // 确保 Element Plus 使用正确的 Vue 3 实例
        // 在加载前临时将 window.Vue 指向插件的 Vue 3
        if (function_exists('wp_add_inline_script')) {
            $before = '(function(){if(window.ZibllAdVue){window.__ZibllAdPrevVue=window.Vue;window.Vue=window.ZibllAdVue;}})();';
            wp_add_inline_script('zibll-ad-element-plus', $before, 'before');

            // 加载后恢复，但保持 ZibllAdVue 可用
            $after = '(function(){if(window.__ZibllAdPrevVue){window.Vue=window.__ZibllAdPrevVue;delete window.__ZibllAdPrevVue;}})();';
            wp_add_inline_script('zibll-ad-element-plus', $after, 'after');
        }

        // Element Plus 样式
        wp_enqueue_style(
            'zibll-ad-element-plus',
            ZIBLL_AD_URL . 'includes/vendor/element-plus/index.min.css',
            array(),
            '2.3.0'
        );
    }

    /**
     * 检查是否有激活的广告位Widget
     *
     * @return bool
     */
    private function has_active_widgets() {
        // 允许通过过滤器强制加载资源
        if (apply_filters('zibll_ad_force_load_assets', false)) {
            return true;
        }

        // 获取所有sidebar的widgets配置
        $sidebars_widgets = wp_get_sidebars_widgets();

        if (empty($sidebars_widgets) || !is_array($sidebars_widgets)) {
            return false;
        }

        // 遍历所有sidebar
        foreach ($sidebars_widgets as $sidebar_id => $widgets) {
            // 跳过特殊键
            if ($sidebar_id === 'wp_inactive_widgets' || $sidebar_id === 'array_version') {
                continue;
            }

            if (empty($widgets) || !is_array($widgets)) {
                continue;
            }

            // 检查是否包含我们的Widget
            foreach ($widgets as $widget_id) {
                if (strpos($widget_id, 'zibll_ad_widget-') === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}

