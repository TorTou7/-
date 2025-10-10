<?php
/**
 * 后台资源加载管理类
 *
 * 负责加载后台管理界面所需的 CSS/JS 资源
 *
 * 深度设计思考：
 * 1. 条件加载：仅在插件管理页面加载，避免全局污染
 * 2. 依赖管理：正确处理 Vue、Element Plus 等库的加载顺序
 * 3. 主题复用：优先使用主题已加载的资源，减少重复
 * 4. 版本控制：开发模式使用时间戳，生产模式使用版本号
 * 5. 配置注入：通过 wp_localize_script 注入运行时配置
 * 6. 错误处理：检测资源文件是否存在，提供降级方案
 * 7. 性能优化：合理使用 defer/async、资源压缩
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 后台资源加载类
 */
class Zibll_Ad_Admin_Assets {

    /**
     * 是否为开发模式
     *
     * @var bool
     */
    private $is_dev_mode;

    /**
     * 资源版本号
     *
     * @var string
     */
    private $version;

    /**
     * 构造函数
     */
    public function __construct() {
        // 判断是否为开发模式
        $this->is_dev_mode = defined('ZIBLL_AD_DEV_MODE') && ZIBLL_AD_DEV_MODE;

        // 设置版本号
        $this->version = $this->is_dev_mode ? time() : ZIBLL_AD_VERSION;

        // 注册资源加载钩子
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // 添加内联样式（用于加载动画等）
        add_action('admin_head', array($this, 'admin_head_styles'));

        // 添加内联脚本（用于错误处理等）
        add_action('admin_footer', array($this, 'admin_footer_scripts'));
    }

    /**
     * 加载脚本和样式
     *
     * @param string $hook 当前页面钩子后缀
     */
    public function enqueue_scripts($hook) {
        // 仅在插件管理页面加载
        if (!$this->is_plugin_page($hook)) {
            return;
        }

        // 优先加载 WordPress 媒体库
        // 这是 Vue 组件中使用 wp.media() 的前提条件
        // 必须在其他脚本之前加载，确保 window.wp.media 对象可用
        $this->enqueue_wordpress_media();

        // 加载本地依赖资源（Vue3 / Element Plus / Router）
        $this->enqueue_local_vendor_assets();

        // 加载插件自有资源
        $this->enqueue_plugin_assets();

        // 注入配置数据
        $this->localize_script_data();

        // 移除冲突的脚本/样式（如有必要）
        $this->dequeue_conflicting_assets();
    }

    /**
     * 加载本地供应商资源（Vue3 / Element Plus / Router）
     */
    private function enqueue_local_vendor_assets() {
        // Vue 3 (global build)
        wp_enqueue_script(
            'zibll-ad-vue',
            ZIBLL_AD_URL . 'includes/vendor/vue/vue.global.prod.js',
            array(),
            '3.4.0',
            false
        );

        // Vue Router 4 (global)
        wp_enqueue_script(
            'zibll-ad-vue-router',
            ZIBLL_AD_URL . 'includes/vendor/vue-router/vue-router.global.prod.js',
            array('zibll-ad-vue'),
            '4.2.5',
            false
        );

        // Element Plus 2 (full bundle)
        wp_enqueue_script(
            'zibll-ad-element-plus',
            ZIBLL_AD_URL . 'includes/vendor/element-plus/index.full.min.js',
            array('zibll-ad-vue'),
            '2.3.0',
            false
        );
        // Element Plus locale zh-CN
        wp_enqueue_script(
            'zibll-ad-element-plus-locale-zh-cn',
            ZIBLL_AD_URL . 'includes/vendor/element-plus/locale/zh-cn.min.js',
            array('zibll-ad-element-plus'),
            '2.3.0',
            false
        );
        // Element Plus CSS
        wp_enqueue_style(
            'zibll-ad-element-plus',
            ZIBLL_AD_URL . 'includes/vendor/element-plus/index.min.css',
            array(),
            '2.3.0'
        );

        do_action('zibll_ad_after_enqueue_vendor_assets');
    }

    /**
     * 加载插件自有资源
     */
    private function enqueue_plugin_assets() {
        // 1. 加载插件管理界面 CSS
        wp_enqueue_style(
            'zibll-ad-admin',
            ZIBLL_AD_URL . 'includes/admin/vue-app/dist/admin.css',
            array('zibll-ad-element-plus'), // 依赖本地 Element Plus 样式
            $this->version
        );

        // 2. 加载插件管理界面 JS
        wp_enqueue_script(
            'zibll-ad-admin',
            ZIBLL_AD_URL . 'includes/admin/vue-app/dist/admin.js',
            array('zibll-ad-vue', 'zibll-ad-element-plus', 'zibll-ad-element-plus-locale-zh-cn', 'zibll-ad-vue-router'),
            $this->version,
            true
        );

        // 3. 设置脚本为 ES Module（如果使用 ES6 模块）
        // wp_script_add_data('zibll-ad-admin', 'type', 'module');

        // 允许第三方添加额外的插件资源
        do_action('zibll_ad_after_enqueue_plugin_assets');
    }

    /**
     * 注入配置数据到前端
     */
    private function localize_script_data() {
        // 获取所有侧边栏
        $sidebars_widgets = wp_get_sidebars_widgets();
        global $wp_registered_sidebars;

        $sidebars = array();
        foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
            $sidebars[] = array(
                'id' => $sidebar_id,
                'name' => $sidebar['name'],
                'description' => isset($sidebar['description']) ? $sidebar['description'] : '',
            );
        }

        // 获取支付方式
        $payment_methods = array();
        if (function_exists('zibpay_get_payment_methods')) {
            // 按主题规范获取支付方式；如返回结构为 [ key => '名称' ] 或 [ key => [ 'name' => '名称', 'img' => 'HTML' ] ] 均做兼容
            $methods = zibpay_get_payment_methods();
            if (is_array($methods)) {
                foreach ($methods as $key => $method) {
                    $name = '';
                    if (is_array($method)) {
                        // 主题部分版本会返回包含 name/img 的数组
                        $name = isset($method['name']) ? $method['name'] : '';
                    } else {
                        // 旧版可能直接返回字符串（有时包含 HTML），统一提取纯文本
                        $name = (string) $method;
                    }

                    // 仅保留可读名称，剥离可能的 HTML
                    $name = wp_strip_all_tags($name);

                    $payment_methods[] = array(
                        'key'  => sanitize_key($key),
                        'name' => $name,
                    );
                }
            }
        }

        // 获取订单支付超时时间
        $order_timeout = 30;
        if (function_exists('_pz')) {
            $order_timeout = _pz('order_pay_max_minutes', 30);
        }

        // 构建配置对象
        $config = array(
            // REST API 配置
            'restUrl' => rest_url('zibll-ad/v1'),
            'nonce' => wp_create_nonce('wp_rest'),

            // AJAX URL（用于图片上传等）
            'ajaxUrl' => admin_url('admin-ajax.php'),
            // AJAX Nonce（用于后端自定义 AJAX 接口校验）
            'ajaxNonce' => wp_create_nonce('zibll_ad_admin'),

            // 侧边栏列表
            'sidebars' => $sidebars,

            // 支付方式
            'paymentMethods' => $payment_methods,

            // 订单超时时间（分钟）
            'orderPayTimeout' => intval($order_timeout),

            // 插件信息
            'pluginVersion' => ZIBLL_AD_VERSION,
            'pluginUrl' => ZIBLL_AD_URL,

            // 当前用户信息
            'currentUser' => array(
                'id' => get_current_user_id(),
                'name' => wp_get_current_user()->display_name,
                'canManage' => current_user_can('manage_zibll_ads'),
            ),

            // 环境信息
            'isDevMode' => $this->is_dev_mode,
            'wpVersion' => get_bloginfo('version'),
            'themeVersion' => wp_get_theme()->get('Version'),

            // 国际化字符串（前端常用）
            'i18n' => $this->get_i18n_strings(),

            // 上传配置（允许的图片类型）
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
                // 生成 accept 字符串
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

            // 允许第三方扩展配置
            'custom' => apply_filters('zibll_ad_admin_config', array()),
        );

        // 注入到前端
        wp_localize_script('zibll-ad-admin', 'zibllAdConfig', $config);

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
            wp_add_inline_script('zibll-ad-admin', $quiet_console_js, 'before');
        }


        // 记录日志（开发模式）
        if ($this->is_dev_mode) {
            zibll_ad_log('Admin config injected', array(
                'sidebars_count' => count($sidebars),
                'payment_methods_count' => count($payment_methods),
            ));
        }
    }

    /**
     * 获取国际化字符串
     *
     * 前端常用的翻译字符串
     *
     * @return array
     */
    private function get_i18n_strings() {
        return array(
            // 通用
            'confirm' => __('确认', 'zibll-ad'),
            'cancel' => __('取消', 'zibll-ad'),
            'save' => __('保存', 'zibll-ad'),
            'delete' => __('删除', 'zibll-ad'),
            'edit' => __('编辑', 'zibll-ad'),
            'loading' => __('加载中...', 'zibll-ad'),
            'success' => __('操作成功', 'zibll-ad'),
            'error' => __('操作失败', 'zibll-ad'),

            // 广告位相关
            'slot' => __('广告位', 'zibll-ad'),
            'slots' => __('广告位列表', 'zibll-ad'),
            'createSlot' => __('新建广告位', 'zibll-ad'),
            'editSlot' => __('编辑广告位', 'zibll-ad'),
            'deleteSlot' => __('删除广告位', 'zibll-ad'),
            'slotName' => __('广告位名称', 'zibll-ad'),
            'slotType' => __('广告位类型', 'zibll-ad'),

            // 订单相关
            'order' => __('订单', 'zibll-ad'),
            'orders' => __('订单列表', 'zibll-ad'),
            'orderStatus' => __('订单状态', 'zibll-ad'),
            'orderAmount' => __('订单金额', 'zibll-ad'),

            // 状态
            'available' => __('可用', 'zibll-ad'),
            'pending' => __('待支付', 'zibll-ad'),
            'paid' => __('已支付', 'zibll-ad'),
            'expired' => __('已过期', 'zibll-ad'),

            // 确认对话框
            'confirmDelete' => __('确定要删除吗？此操作不可恢复。', 'zibll-ad'),
            'confirmSave' => __('确定要保存吗？', 'zibll-ad'),

            // 允许第三方扩展
            'custom' => apply_filters('zibll_ad_admin_i18n', array()),
        );
    }

    /**
     * 加载 WordPress 媒体库
     *
     * 深度思考：
     * 1. wp_enqueue_media() 会自动加载所有必需的脚本：
     *    - wp-includes/js/media-models.js
     *    - wp-includes/js/media-views.js
     *    - wp-includes/js/media-editor.js
     *    - wp-includes/js/media-audiovideo.js
     *    以及相关的 Backbone、Underscore 依赖
     *
     * 2. 同时加载必需的样式：
     *    - wp-includes/css/media-views.css
     *    - wp-admin/css/media.css
     *
     * 3. 注入必要的 PHP 数据到 JavaScript：
     *    - _wpMediaViewsL10n（国际化字符串）
     *    - _wpPluploadSettings（上传器配置）
     *    - _wpMediaGridSettings（网格视图配置）
     *
     * 4. 传递 $post 参数的意义：
     *    - 如果传递 post 对象，媒体库会记住当前文章 ID
     *    - 后台管理页面通常传递 null，表示全局上下文
     *    - 这影响"上传到此文章"功能的行为
     *
     * 5. 性能优化考虑：
     *    - 媒体库脚本较大（约 200KB 压缩后）
     *    - 仅在需要的页面加载（已通过 is_plugin_page 控制）
     *    - WordPress 会自动处理脚本依赖和去重
     *
     * 6. 兼容性保障：
     *    - WordPress 3.5+ 支持 wp_enqueue_media()
     *    - 自动适配当前 WordPress 版本的媒体库 UI
     *    - 不需要手动加载 thickbox（旧版方式）
     */
    private function enqueue_wordpress_media() {
        // 加载媒体库核心脚本和样式
        // 参数 null 表示全局上下文，不关联特定文章
        wp_enqueue_media();

        // 记录日志（开发模式）
        if ($this->is_dev_mode) {
            zibll_ad_log('WordPress media library enqueued', array(
                'wp_version' => get_bloginfo('version'),
                'scripts_loaded' => array(
                    'media-models',
                    'media-views',
                    'media-editor',
                    'media-audiovideo',
                ),
            ));
        }

        // 允许第三方在媒体库加载后执行操作
        do_action('zibll_ad_after_enqueue_media');
    }

    /**
     * 移除冲突的脚本/样式
     *
     * 某些插件/主题可能会在后台加载冲突的 Vue 或 Element 版本
     */
    private function dequeue_conflicting_assets() {
        // 允许通过过滤器指定要移除的脚本
        $conflicts = apply_filters('zibll_ad_conflicting_assets', array(
            // 示例：
            // 'old-vue',
            // 'another-element-ui',
        ));

        foreach ($conflicts as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }

    /**
     * 添加头部内联样式
     *
     * 用于加载动画、基础布局等
     */
    public function admin_head_styles() {
        if (!$this->is_plugin_page()) {
            return;
        }

        ?>
        <style>
            /* Vue 应用容器样式 */
            #zibll-ad-app {
                margin-top: 20px;
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
            }

            /* 加载动画 */
            .zibll-ad-loading {
                text-align: center;
                padding: 80px 20px;
                min-height: 400px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }

            .zibll-ad-loading-spinner {
                margin-bottom: 20px;
            }

            .zibll-ad-loading-text {
                color: #666;
                font-size: 14px;
            }

            /* Vue 应用挂载后隐藏加载动画 */
            #zibll-ad-app[data-app-mounted="true"] .zibll-ad-loading {
                display: none;
            }

            /* 页面脚注 */
            .zibll-ad-footer {
                margin-top: 30px;
                padding: 20px 0;
                border-top: 1px solid #ddd;
                text-align: center;
            }

            .zibll-ad-footer p {
                color: #666;
                font-size: 13px;
                margin: 0;
            }

            .zibll-ad-footer a {
                text-decoration: none;
            }

            /* 错误提示 */
            .zibll-ad-error {
                padding: 20px;
                background: #fff;
                border-left: 4px solid #dc3232;
                margin: 20px 0;
            }

            .zibll-ad-error h3 {
                margin-top: 0;
                color: #dc3232;
            }

            /* 响应式 */
            @media screen and (max-width: 782px) {
                #zibll-ad-app {
                    margin-left: -10px;
                    margin-right: -10px;
                }
            }

            /* Admin image preview: force fill to avoid cropping (8:1 box) */
            /* Applies to: 新建/编辑广告位 默认图片、广告内容图片预览 */
            #zibll-ad-app .slot-form .preview-image .el-image__inner,
            #zibll-ad-app .full-width-form-item .preview-image .el-image__inner {
                width: 100% !important;
                height: 100% !important;
                object-fit: fill !important;
            }

            /* ------------------------------------------------------------------
             * Safety overrides: fix 3rd-party/global CSS collisions
             * Some environments/plugins define very generic class names like
             * .site-name, .site-url, .detail-value and set them to display:none
             * or invisible. Our admin uses these class names inside the app,
             * so we harden them with a scoped, high-specificity override to
             * ensure content is visible.
             * ------------------------------------------------------------------ */
            #zibll-ad-app .ad-content .site-name,
            #zibll-ad-app .ad-content .site-url,
            #zibll-ad-app .ad-content .site-url *,
            #zibll-ad-app .ad-detail .detail-label,
            #zibll-ad-app .ad-detail .detail-value {
                display: inline !important;
                visibility: visible !important;
                opacity: 1 !important;
                color: var(--el-text-color-primary, #303133) !important;
                text-indent: 0 !important;
                filter: none !important;
            }

            /* Ensure drawer detail rows always lay out correctly */
            #zibll-ad-app .ad-detail .detail-row {
                display: flex !important;
                align-items: center !important;
            }

            /* ------------------------------------------------------------------
             * Fix: 恢复 Element Plus 控件默认外观，避免被自定义样式/WordPress 样式覆盖
             * 同时去除浏览器/WordPress 自带输入框边框，防止“框中套框”
             * ------------------------------------------------------------------ */
            #zibll-ad-app .el-input__wrapper,
            #zibll-ad-app .el-select__wrapper {
                background-color: var(--el-fill-color-blank) !important;
                box-shadow: 0 0 0 1px var(--el-input-border-color, var(--el-border-color)) inset !important;
                border-radius: var(--el-input-border-radius, var(--el-border-radius-base)) !important;
            }

            /* 避免“框中框”：内部选择区不需要边框和阴影 */
            #zibll-ad-app .el-select__selection {
                background-color: transparent !important;
                box-shadow: none !important;
                border: none !important;
            }

            #zibll-ad-app .el-input__wrapper:hover,
            #zibll-ad-app .el-select__wrapper:hover {
                box-shadow: 0 0 0 1px var(--el-input-hover-border-color, var(--el-border-color-hover)) inset !important;
            }

            #zibll-ad-app .el-input__wrapper.is-focus,
            #zibll-ad-app .el-select__wrapper.is-focused {
                box-shadow: 0 0 0 1px var(--el-color-primary) inset !important;
            }

            /* 输入框内部的原生输入去边框，避免出现“框中框” */
            #zibll-ad-app .el-input__wrapper input,
            #zibll-ad-app .el-range-editor .el-range-input,
            #zibll-ad-app .el-select__wrapper input {
                border: none !important;
                box-shadow: none !important;
                outline: none !important;
                background-color: transparent !important;
            }

            /* 针对筛选区（.filters）先前的覆盖进行还原，保持与 EP 默认一致 */
            #zibll-ad-app .filters .el-input__wrapper,
            #zibll-ad-app .filters .el-select__wrapper,
            #zibll-ad-app .filters .el-select__selection {
                background-color: var(--el-fill-color-blank) !important;
                box-shadow: 0 0 0 1px var(--el-input-border-color, var(--el-border-color)) inset !important;
            }

            /* 提升优先级，覆盖带有 [data-v-xxxx] 的作用域选择器 */
            #zibll-ad-app #zibll-ad-app .filters .el-input__wrapper,
            #zibll-ad-app #zibll-ad-app .filters .el-select__wrapper,
            #zibll-ad-app #zibll-ad-app .filters .el-select__selection {
                background-color: var(--el-fill-color-blank) !important;
                box-shadow: 0 0 0 1px var(--el-input-border-color, var(--el-border-color)) inset !important;
                border-radius: var(--el-input-border-radius, var(--el-border-radius-base)) !important;
            }

            #zibll-ad-app #zibll-ad-app .filters .el-input__wrapper:hover,
            #zibll-ad-app #zibll-ad-app .filters .el-select__wrapper:hover,
            #zibll-ad-app #zibll-ad-app .filters .el-select__selection:hover {
                box-shadow: 0 0 0 1px var(--el-input-hover-border-color, var(--el-border-color-hover)) inset !important;
            }

            /* 允许通过过滤器添加自定义样式 */
            <?php echo apply_filters('zibll_ad_admin_inline_styles', ''); ?>
        </style>
        <?php
    }

    /**
     * 添加尾部内联脚本
     *
     * 用于错误处理、降级方案等
     */
    public function admin_footer_scripts() {
        if (!$this->is_plugin_page()) {
            return;
        }

        ?>
        <script>
        (function() {
            'use strict';

            // 错误处理：Vue 应用 5 秒后仍未挂载，显示错误提示
            setTimeout(function() {
                var app = document.getElementById('zibll-ad-app');
                if (!app || app.getAttribute('data-app-mounted') !== 'true') {
                    console.error('[Zibll Ad] Vue app failed to mount');

                    // 显示错误提示
                    var errorHtml = '<div class="zibll-ad-error">' +
                        '<h3><?php esc_html_e('加载失败', 'zibll-ad'); ?></h3>' +
                        '<p><?php esc_html_e('管理界面加载失败，请检查：', 'zibll-ad'); ?></p>' +
                        '<ul>' +
                        '<li><?php esc_html_e('浏览器控制台是否有 JavaScript 错误', 'zibll-ad'); ?></li>' +
                        '<li><?php esc_html_e('网络连接是否正常', 'zibll-ad'); ?></li>' +
                        '<li><?php esc_html_e('是否与其他插件冲突', 'zibll-ad'); ?></li>' +
                        '</ul>' +
                        '<p><a href="#" onclick="location.reload(); return false;" class="button button-primary">' +
                        '<?php esc_html_e('重新加载', 'zibll-ad'); ?>' +
                        '</a></p>' +
                        '</div>';

                    if (app) {
                        app.innerHTML = errorHtml;
                    }
                }
            }, 5000);

            // 全局错误捕获
            window.addEventListener('error', function(event) {
                try {
                    var err = event && (event.error || event.message || event);
                    if (typeof err === 'undefined' || err === null) return;
                    // 部分浏览器跨域脚本报错 error 为空，仅打印 message 文本
                    console.error('[Zibll Ad] Global error:', err);
                } catch (e) {}
            });

            // Promise 错误捕获
            window.addEventListener('unhandledrejection', function(event) {
                console.error('[Zibll Ad] Unhandled promise rejection:', event.reason);
            });

            // 开发模式：输出配置信息
            <?php if ($this->is_dev_mode): ?>
            console.log('[Zibll Ad] Config:', window.zibllAdConfig);
            <?php endif; ?>

            // 允许通过过滤器添加自定义脚本
            <?php echo apply_filters('zibll_ad_admin_inline_scripts', ''); ?>

            // --------------------------------------------------------------
            // Diagnostic helper (only when ?zad_debug=1 or dev mode on)
            // Prints computed styles for key cells to the console and outlines
            // them to help locate CSS-collision problems in different envs.
            // --------------------------------------------------------------
            try {
                var params = new URLSearchParams(location.search);
                var enableDiag = params.has('zad_debug') || (window.zibllAdConfig && window.zibllAdConfig.isDevMode);
                if (enableDiag) {
                    var pick = ['display','visibility','opacity','color','font-size','line-height','text-indent','height','max-height','overflow','clip-path','clip','position','z-index','transform','-webkit-text-fill-color'];
                    var dump = function(selector){
                        var el = document.querySelector(selector);
                        if(!el){ console.warn('[Zibll Ad][diag] not found:', selector); return; }
                        var cs = getComputedStyle(el);
                        var out = { selector: selector };
                        pick.forEach(function(p){ try{ out[p] = cs.getPropertyValue(p) || cs[p]; }catch(e){} });
                        console.group('[Zibll Ad][diag] computed styles');
                        console.log(el);
                        console.table(out);
                        console.groupEnd();
                        // make it visibly highlighted
                        el.style.outline = '2px dashed #e91e63';
                        el.style.background = 'rgba(255,255,0,.15)';
                    };
                    var runDiag = function(){
                        dump('#zibll-ad-app .ad-content .site-name');
                        dump('#zibll-ad-app .ad-content .site-url .el-link__inner');
                        dump('#zibll-ad-app .ad-detail .detail-value');
                    };
                    setTimeout(runDiag, 1200);
                }
            } catch(e) {}
        })();
        </script>
        <?php
    }

    /**
     * 检查主题资源文件是否存在
     *
     * @return bool
     */
    private function check_theme_assets_exist() {
        $theme_dir = get_template_directory();

        $required_files = array(
            '/zibpay/assets/js/vue.global.min.js',
            '/zibpay/assets/js/element-plus.min.js',
            '/zibpay/assets/css/element-plus.min.css',
        );

        foreach ($required_files as $file) {
            if (!file_exists($theme_dir . $file)) {
                zibll_ad_log('Theme asset not found: ' . $file);
                return false;
            }
        }

        return true;
    }

    /**
     * 加载 CDN 备用资源
     *
     * 当主题资源不可用时的降级方案
     */
    private function enqueue_cdn_fallback() {
        zibll_ad_log('Loading CDN fallback assets');

        // Vue 3 CDN
        wp_enqueue_script(
            'vue',
            'https://cdn.jsdelivr.net/npm/vue@3.3.4/dist/vue.global.prod.js',
            array(),
            '3.3.4',
            true
        );

        // Element Plus CDN
        wp_enqueue_script(
            'element-plus',
            'https://cdn.jsdelivr.net/npm/element-plus@2.3.9/dist/index.full.min.js',
            array('vue'),
            '2.3.9',
            true
        );

        // Element Plus 中文语言包
        wp_enqueue_script(
            'element-plus-locale-zh-cn',
            'https://cdn.jsdelivr.net/npm/element-plus@2.3.9/dist/locale/zh-cn.min.js',
            array('element-plus'),
            '2.3.9',
            true
        );

        // Vue Router CDN
        wp_enqueue_script(
            'vue-router',
            'https://cdn.jsdelivr.net/npm/vue-router@4.2.4/dist/vue-router.global.prod.js',
            array('vue'),
            '4.2.4',
            true
        );

        // Element Plus CSS
        wp_enqueue_style(
            'element-plus',
            'https://cdn.jsdelivr.net/npm/element-plus@2.3.9/dist/index.min.css',
            array(),
            '2.3.9'
        );
    }

    /**
     * 判断当前是否在插件管理页面
     *
     * @param string $hook 当前页面钩子（可选）
     * @return bool
     */
    private function is_plugin_page($hook = null) {
        if (null === $hook) {
            $screen = get_current_screen();
            if (!$screen) {
                return false;
            }
            $hook = $screen->id;
        }

        // 检查是否是插件页面
        return strpos($hook, 'zibll-ad') !== false;
    }
}
