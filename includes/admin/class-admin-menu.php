<?php
/**
 * 后台菜单管理类
 *
 * 负责注册后台管理菜单、渲染 Vue 应用容器、提供管理界面入口
 *
 * 深度设计思考：
 * 1. 菜单层级：顶级菜单 + 可扩展的子菜单
 * 2. 权限控制：严格的 capability 检查
 * 3. 用户体验：加载动画、错误提示、帮助文档
 * 4. 性能优化：仅在必要时加载资源
 * 5. 可访问性：ARIA 标签、键盘导航支持
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 后台菜单类
 */
class Zibll_Ad_Admin_Menu {

    /**
     * 菜单页面 slug
     *
     * @var string
     */
    const MENU_SLUG = 'zibll-ad';

    /**
     * 菜单钩子后缀（用于资源加载）
     *
     * @var string
     */
    private $page_hook;

    /**
     * 构造函数
     */
    public function __construct() {
        // 注册后台菜单
        add_action('admin_menu', array($this, 'register_menu'));

        // 添加页面帮助文档
        add_action('load-' . $this->page_hook, array($this, 'add_help_tabs'), 20);

        // 添加管理员通知（迁移为 Element 提示组件展示）
        add_action('admin_notices', array($this, 'admin_notices'));

        // 将提示注入到内联脚本，由 Element 提示组件展示
        add_filter('zibll_ad_admin_inline_scripts', array($this, 'inject_element_notices_script'));

        // AJAX：持久化关闭欢迎提示（仅管理员）
        add_action('wp_ajax_zibll_ad_dismiss_welcome', array($this, 'ajax_dismiss_welcome'));
        // AJAX：持久化关闭环境警告（仅管理员）
        add_action('wp_ajax_zibll_ad_dismiss_env_notice', array($this, 'ajax_dismiss_env_notice'));
    }

    /**
     * 注册后台菜单
     */
    public function register_menu() {
        // 添加顶级菜单
        $this->page_hook = add_menu_page(
            __('自助广告位', 'zibll-ad'),                    // 页面标题
            __('自助广告位', 'zibll-ad'),                    // 菜单标题
            'manage_zibll_ads',                             // 所需权限
            self::MENU_SLUG,                                // 菜单 slug
            array($this, 'render_page'),                    // 渲染回调
            $this->get_menu_icon(),                         // 菜单图标
            30                                              // 菜单位置（30 在"评论"之后）
        );

        // 添加子菜单（重复第一个作为"概览"）
        add_submenu_page(
            self::MENU_SLUG,
            __('概览', 'zibll-ad'),
            __('概览', 'zibll-ad'),
            'manage_zibll_ads',
            self::MENU_SLUG,
            array($this, 'render_page')
        );

        // 添加"广告管理"子菜单
        add_submenu_page(
            self::MENU_SLUG,
            __('广告管理', 'zibll-ad'),
            __('广告管理', 'zibll-ad'),
            'manage_zibll_ads',
            self::MENU_SLUG . '#/ads',
            '__return_null'
        );

        // 添加"广告位管理"子菜单（指向同一页面，Vue 路由处理）
        add_submenu_page(
            self::MENU_SLUG,
            __('广告位管理', 'zibll-ad'),
            __('广告位管理', 'zibll-ad'),
            'manage_zibll_ads',
            self::MENU_SLUG . '#/slots',
            '__return_null' // 不需要回调，由 Vue Router 处理
        );

        // 添加"订单管理"子菜单（指向 Vue 路由）
        add_submenu_page(
            self::MENU_SLUG,
            __('订单管理', 'zibll-ad'),
            __('订单管理', 'zibll-ad'),
            'manage_zibll_ads',
            self::MENU_SLUG . '#/orders',
            '__return_null'
        );

        // 添加"插件设置"子菜单
        add_submenu_page(
            self::MENU_SLUG,
            __('插件设置', 'zibll-ad'),
            __('插件设置', 'zibll-ad'),
            'manage_zibll_ads',
            self::MENU_SLUG . '#/settings',
            '__return_null'
        );

        // 允许第三方添加子菜单
        do_action('zibll_ad_admin_menu_registered', self::MENU_SLUG);
    }

    /**
     * 渲染管理页面
     */
    public function render_page() {
        // 权限检查（双重保险）
        if (!current_user_can('manage_zibll_ads')) {
            wp_die(
                __('您没有权限访问此页面。', 'zibll-ad'),
                __('权限不足', 'zibll-ad'),
                array('response' => 403)
            );
        }

        // 输出页面容器
        ?>
        <div class="wrap" id="zibll-ad-wrap">
            <!-- Vue 应用挂载点 -->
            <div id="zibll-ad-app" class="zibll-ad-app-container" style="height: 100%; width: 100%;">
                <!-- 管理员通知区域 -->
                <div id="zibll-ad-notices"></div>

                <!-- 加载动画（Vue 挂载前显示） -->
                <div class="zibll-ad-loading">
                    <div class="zibll-ad-loading-spinner">
                        <span class="spinner is-active"></span>
                    </div>
                    <p class="zibll-ad-loading-text">
                        <?php esc_html_e('正在加载管理界面...', 'zibll-ad'); ?>
                    </p>
                </div>
            </div>

            <!-- 页面脚注（版本信息、帮助链接） -->
            <div class="zibll-ad-footer">
                <p class="zibll-ad-version">
                    <?php
                    printf(
                        /* translators: %s: plugin version */
                        esc_html__('子比自助广告位 v%s', 'zibll-ad'),
                        esc_html(ZIBLL_AD_VERSION)
                    );
                    ?>
                    &nbsp;|&nbsp;
                    <a href="<?php echo esc_url($this->get_support_url()); ?>" target="_blank">
                        <?php esc_html_e('技术支持', 'zibll-ad'); ?>
                    </a>
                    &nbsp;|&nbsp;
                    <?php esc_html_e('禁止倒卖', 'zibll-ad'); ?>
                </p>
            </div>
        </div>

        <?php
        // 输出后钩子（允许第三方添加内容）
        do_action('zibll_ad_after_admin_page');
    }

    /**
     * 获取菜单图标
     *
     * 优先级：
     * 1. 自定义 SVG 图标（最佳）
     * 2. Dashicons
     * 3. 图片 URL
     *
     * @return string 图标 URL 或 Dashicon 类名
     */
    private function get_menu_icon() {
        // 允许通过过滤器自定义图标
        $custom_icon = apply_filters('zibll_ad_menu_icon', '');

        if (!empty($custom_icon)) {
            return $custom_icon;
        }

        // 使用内联 SVG（推荐方式）
        // 广告牌图标（自定义设计）
        $svg = '<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
            <path fill="black" d="M18 3H2c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 12H2V5h16v10zM4 7h5v2H4zm0 3h5v2H4zm6-3h6v5h-6z"/>
        </svg>';

        // Base64 编码 SVG
        return 'data:image/svg+xml;base64,' . base64_encode($svg);

        // 备选：使用 Dashicons
        // return 'dashicons-megaphone';
    }

    /**
     * 添加帮助文档标签
     *
     * WordPress 后台右上角的"帮助"下拉菜单
     */
    public function add_help_tabs() {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== $this->page_hook) {
            return;
        }

        // 概览标签
        $screen->add_help_tab(array(
            'id' => 'zibll-ad-overview',
            'title' => __('概览', 'zibll-ad'),
            'content' => $this->get_help_tab_overview(),
        ));

        // 快速入门标签
        $screen->add_help_tab(array(
            'id' => 'zibll-ad-quickstart',
            'title' => __('快速入门', 'zibll-ad'),
            'content' => $this->get_help_tab_quickstart(),
        ));

        // 常见问题标签
        $screen->add_help_tab(array(
            'id' => 'zibll-ad-faq',
            'title' => __('常见问题', 'zibll-ad'),
            'content' => $this->get_help_tab_faq(),
        ));

        // 设置侧边栏（联系方式）
        $screen->set_help_sidebar($this->get_help_sidebar());
    }

    /**
     * 获取"概览"帮助内容
     *
     * @return string HTML 内容
     */
    private function get_help_tab_overview() {
        ob_start();
        ?>
        <h3><?php esc_html_e('关于自助广告位插件', 'zibll-ad'); ?></h3>
        <p>
            <?php esc_html_e('自助广告位插件允许用户在前台自助购买和管理广告位，支持图片和文字两种广告类型，全程自动化管理，无需人工干预。', 'zibll-ad'); ?>
        </p>
        <h4><?php esc_html_e('主要功能', 'zibll-ad'); ?></h4>
        <ul>
            <li><?php esc_html_e('创建和管理广告位（图片/文字）', 'zibll-ad'); ?></li>
            <li><?php esc_html_e('自动挂载到指定侧边栏', 'zibll-ad'); ?></li>
            <li><?php esc_html_e('灵活的定价策略（套餐/自定义月数）', 'zibll-ad'); ?></li>
            <li><?php esc_html_e('集成子比主题支付系统', 'zibll-ad'); ?></li>
            <li><?php esc_html_e('自动上下架管理', 'zibll-ad'); ?></li>
            <li><?php esc_html_e('订单和收入统计', 'zibll-ad'); ?></li>
        </ul>
        <?php
        return ob_get_clean();
    }

    /**
     * 获取"快速入门"帮助内容
     *
     * @return string HTML 内容
     */
    private function get_help_tab_quickstart() {
        ob_start();
        ?>
        <h3><?php esc_html_e('快速入门指南', 'zibll-ad'); ?></h3>
        <ol>
            <li>
                <strong><?php esc_html_e('创建广告位', 'zibll-ad'); ?></strong><br>
                <?php esc_html_e('点击"新建广告位"按钮，填写广告位信息（名称、类型、布局等）。', 'zibll-ad'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('配置定价', 'zibll-ad'); ?></strong><br>
                <?php esc_html_e('设置预设套餐或单月价格，文字广告可配置颜色附加价。', 'zibll-ad'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('选择挂载位置', 'zibll-ad'); ?></strong><br>
                <?php esc_html_e('选择要展示的侧边栏位置，系统会自动挂载 Widget。', 'zibll-ad'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('前台展示', 'zibll-ad'); ?></strong><br>
                <?php esc_html_e('访问前台查看广告位，用户可以点击空位购买广告。', 'zibll-ad'); ?>
            </li>
            <li>
                <strong><?php esc_html_e('查看订单', 'zibll-ad'); ?></strong><br>
                <?php esc_html_e('在"订单管理"中查看所有广告订单，支持筛选和导出。', 'zibll-ad'); ?>
            </li>
        </ol>
        <?php
        return ob_get_clean();
    }

    /**
     * 获取"常见问题"帮助内容
     *
     * @return string HTML 内容
     */
    private function get_help_tab_faq() {
        ob_start();
        ?>
        <h3><?php esc_html_e('常见问题', 'zibll-ad'); ?></h3>

        <h4><?php esc_html_e('Q: 如何修改广告位的挂载位置？', 'zibll-ad'); ?></h4>
        <p>
            <?php esc_html_e('A: 在广告位编辑页面，重新选择"挂载位置"即可，系统会自动更新 Widget。', 'zibll-ad'); ?>
        </p>

        <h4><?php esc_html_e('Q: 广告到期后会自动下架吗？', 'zibll-ad'); ?></h4>
        <p>
            <?php esc_html_e('A: 是的，系统每小时会自动检查到期广告并下架，恢复为默认展示内容。', 'zibll-ad'); ?>
        </p>

        <h4><?php esc_html_e('Q: 用户购买后多久广告上线？', 'zibll-ad'); ?></h4>
        <p>
            <?php esc_html_e('A: 支付成功后立即上线，无需人工审核（可通过钩子添加审核流程）。', 'zibll-ad'); ?>
        </p>

        <h4><?php esc_html_e('Q: 支持哪些支付方式？', 'zibll-ad'); ?></h4>
        <p>
            <?php esc_html_e('A: 支持子比主题集成的所有支付方式（微信、支付宝、余额等）。', 'zibll-ad'); ?>
        </p>

        <h4><?php esc_html_e('Q: 如何自定义广告位样式？', 'zibll-ad'); ?></h4>
        <p>
            <?php esc_html_e('A: 可以通过 CSS 覆盖 .zibll-ad-widget 相关类名，或使用提供的钩子。', 'zibll-ad'); ?>
        </p>
        <?php
        return ob_get_clean();
    }

    /**
     * 获取帮助侧边栏
     *
     * @return string HTML 内容
     */
    private function get_help_sidebar() {
        ob_start();
        ?>
        <p><strong><?php esc_html_e('获取帮助', 'zibll-ad'); ?></strong></p>
        <p>
            <a href="<?php echo esc_url($this->get_help_url()); ?>" target="_blank">
                <?php esc_html_e('查看完整文档', 'zibll-ad'); ?>
            </a>
        </p>
        <p>
            <a href="<?php echo esc_url($this->get_support_url()); ?>" target="_blank">
                <?php esc_html_e('联系技术支持', 'zibll-ad'); ?>
            </a>
        </p>
        <p>
            <a href="<?php echo esc_url($this->get_github_url()); ?>" target="_blank">
                <?php esc_html_e('GitHub 仓库', 'zibll-ad'); ?>
            </a>
        </p>
        <?php
        return ob_get_clean();
    }

    /**
     * 管理员通知
     *
     * 显示重要的系统消息（欢迎消息、警告等）
     */
    public function admin_notices() {
        // 仅在插件管理页面显示
        if (!$this->is_plugin_page()) {
            return;
        }
        // 已迁移到 Element 提示组件，在前端展示
        return;
    }

    /**
     * 渲染欢迎消息
     */
    private function render_welcome_notice() {
        // 已迁移到 Element 提示组件展示（见 inject_element_notices_script）
        return;
    }

    /**
     * 渲染环境警告
     */
    private function render_environment_warning() {
        // 已迁移到 Element 提示组件展示（见 inject_element_notices_script）
        return;
    }

    /**
     * 判断是否应该显示欢迎消息
     *
     * @return bool
     */
    private function should_show_welcome_notice() {
        // 检查是否已显示过
        $dismissed = get_user_meta(get_current_user_id(), 'zibll_ad_welcome_dismissed', true);

        if ($dismissed) {
            return false;
        }

        // 检查是否是首次访问（没有任何广告位）
        $slots = Zibll_Ad_Slot_Model::get_all(array('posts_per_page' => 1));

        return empty($slots);
    }

    /**
     * 判断是否应该显示环境警告
     *
     * @return bool
     */
    private function should_show_environment_warning() {
        // 检查子比主题
        if (!function_exists('_pz')) {
            return true;
        }

        // 检查 PHP 版本
        if (version_compare(PHP_VERSION, '7.2', '<')) {
            return true;
        }

        return false;
    }

    /**
     * 判断当前是否在插件管理页面
     *
     * @return bool
     */
    private function is_plugin_page() {
        $screen = get_current_screen();

        return $screen && $screen->id === $this->page_hook;
    }

    /**
     * AJAX：持久化关闭欢迎提示（每用户一次）
     */
    public function ajax_dismiss_welcome() {
        // 权限与来源校验（仅管理员）
        if (!current_user_can('manage_zibll_ads')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        // Nonce 校验（可选）
        if (isset($_POST['nonce'])) {
            check_ajax_referer('zibll_ad_admin', 'nonce');
        }

        update_user_meta(get_current_user_id(), 'zibll_ad_welcome_dismissed', 1);
        wp_send_json_success(array('dismissed' => true));
    }

    /**
     * AJAX：持久化关闭环境警告（逐条 key 按用户记录）
     */
    public function ajax_dismiss_env_notice() {
        if (!current_user_can('manage_zibll_ads')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        if (isset($_POST['nonce'])) {
            check_ajax_referer('zibll_ad_admin', 'nonce');
        }

        $key = isset($_POST['key']) ? sanitize_key(wp_unslash($_POST['key'])) : '';
        if (empty($key)) {
            wp_send_json_error(array('message' => 'missing key'), 400);
        }

        $user_id = get_current_user_id();
        $list = get_user_meta($user_id, 'zibll_ad_env_dismissed', true);
        $list = is_array($list) ? $list : array();
        if (!in_array($key, $list, true)) {
            $list[] = $key;
            update_user_meta($user_id, 'zibll_ad_env_dismissed', $list);
        }

        wp_send_json_success(array('dismissed' => $key));
    }

    /**
     * 注入基于 Element 的提示脚本（替代 WP 原生 notice）
     *
     * - 仅在本插件“概览”页面（#/ 或空 hash）展示
     * - 欢迎信息：success 类型，自动消失
     * - 环境警告：warning 类型，需手动关闭（duration: 0）
     *
     * @param string $existing 现有内联脚本
     * @return string 附加后的脚本
     */
    public function inject_element_notices_script($existing) {
        if (!$this->is_plugin_page()) {
            return $existing;
        }

        $notices = array();

        // 欢迎信息（首次、无广告位）
        if ($this->should_show_welcome_notice()) {
            $notices[] = array(
                'type'     => 'success',
                'message'  => __('欢迎使用子比自助广告位插件！', 'zibll-ad'),
                'duration' => 5000,
                'key'      => 'welcome',
            );
        }

        // 环境检查警告
        if ($this->should_show_environment_warning()) {
            $issues = array();
            if (!function_exists('_pz')) {
                $issues[] = array(
                    'key' => 'env-no-theme',
                    'message' => __('未检测到子比主题，部分功能可能无法使用。', 'zibll-ad'),
                );
            }
            if (version_compare(PHP_VERSION, '7.2', '<')) {
                $issues[] = array(
                    'key' => 'env-php-too-low',
                    'message' => sprintf(
                        /* translators: %s: current PHP version */
                        __('需要 PHP 7.2 或更高版本（当前：%s）。', 'zibll-ad'),
                        PHP_VERSION
                    ),
                );
            }

            // 读取已关闭的环境提示（用户级）
            $dismissed = get_user_meta(get_current_user_id(), 'zibll_ad_env_dismissed', true);
            $dismissed = is_array($dismissed) ? $dismissed : array();

            if (!empty($issues)) {
                foreach ($issues as $it) {
                    if (!in_array($it['key'], $dismissed, true)) {
                        $notices[] = array(
                            'type'     => 'warning',
                            'message'  => $it['message'],
                            'duration' => 0, // 需要手动关闭
                            'key'      => $it['key'],
                        );
                    }
                }
            }
        }

        if (empty($notices)) {
            return $existing;
        }

        $json = wp_json_encode($notices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $create_url = esc_url_raw(admin_url('admin.php?page=' . self::MENU_SLUG)) . '#/slots/create';

        $js = <<<JS
(function(){
  try {
    var notices = $json || [];
    if (!Array.isArray(notices) || !notices.length) return;
    function isOverview(){
      var h = (window.location.hash||'').replace(/^#/, '');
      return h === '' || h === '/';
    }
    function ensureHost(){
      var app = document.getElementById('zibll-ad-app');
      if (!app) return null;
      var host = document.getElementById('zibll-ad-notices');
      if (!host) {
        host = document.createElement('div');
        host.id = 'zibll-ad-notices';
        host.style.margin = '16px';
        app.insertBefore(host, app.firstChild);
      }
      return host;
    }
    function mountAlerts(){
      if (!isOverview()) { unmountAlerts(); return; }
      if (!window.Vue || !window.ElementPlus) return;
      var host = ensureHost();
      if (!host) return;
      // Avoid double mount
      if (window.__zibllAdNoticesApp) return;
      var Vue = window.Vue;
      var Alerts = {
        data: function(){
          return { items: notices.map(function(n, i){ n._id = i; return n; }) };
        },
        render: function(){
          var h = Vue.h;
          var self = this;
          return h('div', { class: 'zibll-ad-el-notices' }, this.items.map(function(item){
            var props = {
              title: item.message,
              type: item.type || 'info',
              closable: true,
              showIcon: true,
              onClose: function(){
                self.items = self.items.filter(function(x){ return x._id !== item._id; });
                try {
                  var ajaxUrl = (window.zibllAdConfig && window.zibllAdConfig.ajaxUrl) || (window.ajaxurl || '');
                  var nonce = (window.zibllAdConfig && window.zibllAdConfig.ajaxNonce) || '';
                  if (!ajaxUrl) return;
                  var data = new URLSearchParams();
                  if (item.key === 'welcome') {
                    data.append('action','zibll_ad_dismiss_welcome');
                  } else if (item.key && item.key.indexOf('env-') === 0) {
                    data.append('action','zibll_ad_dismiss_env_notice');
                    data.append('key', item.key);
                  } else {
                    return;
                  }
                  if (nonce) data.append('nonce', nonce);
                  fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type':'application/x-www-form-urlencoded' }, body: data.toString() });
                } catch (e) { console.error('[Zibll Ad] dismiss persist error', e); }
              }
            };
            var content = [];
            if (item.key === 'welcome') {
              var text1 = '感谢您的选择。';
              var linkCreate = '{$create_url}';
              var qqUrl = 'https://wpa.qq.com/msgrd?v=3&uin=3092548013&site=qq&menu=yes';
              content = [
                h('span', { class: 'zibll-ad-welcome-text' }, text1 + ' '),
                h('a', { href: linkCreate, class: 'zibll-ad-link-create' }, '立即创建第一个广告位'),
                h('span', null, ' 或 '),
                h('a', { href: qqUrl, target: '_blank', rel: 'noopener noreferrer', class: 'zibll-ad-link-support' }, '联系开发者')
              ];
            }
            return h(
              window.ElementPlus.ElAlert,
              props,
              content.length ? { default: function(){ return content; } } : undefined
            );
          }));
        }
      };
      try {
        var app = Vue.createApp(Alerts);
        app.use(window.ElementPlus);
        app.mount(host);
        window.__zibllAdNoticesApp = app;
      } catch (e) { console.error('[Zibll Ad] ElAlert mount error', e); }
    }
    function unmountAlerts(){
      try {
        if (window.__zibllAdNoticesApp) {
          window.__zibllAdNoticesApp.unmount();
          window.__zibllAdNoticesApp = null;
        }
        var host = document.getElementById('zibll-ad-notices');
        if (host) host.innerHTML = '';
      } catch (e) { console.error('[Zibll Ad] ElAlert unmount error', e); }
    }
    function init(){ mountAlerts(); }
    if (document.readyState !== 'loading') {
      setTimeout(init, 0);
    } else {
      document.addEventListener('DOMContentLoaded', function(){ setTimeout(init, 0); });
    }
    window.addEventListener('hashchange', function(){ setTimeout(mountAlerts, 0); });
  } catch (e) { console.error('[Zibll Ad] element notices init error', e); }
})();
JS;

        return $existing . "\n" . $js;
    }

    /**
     * 注入“链接重定向（go.php）”设置小挂件
     *
     * - 仅在插件 Settings 路由（#/settings）展示
     * - 读取/保存通过现有 REST 接口 /settings
     * - 作为临时 UI，避免必须重构/重打包管理端 Vue 资源
     */
    public function inject_link_redirect_toggle($existing) {
        if (!$this->is_plugin_page()) {
            return $existing;
        }

        // 预先生成 REST URL 与 Nonce（也可复用 window.zibllAdConfig.nonce）
        $rest_url = esc_url_raw(rest_url('zibll-ad/v1/settings'));
        $rest_nonce = wp_create_nonce('wp_rest');

        $js = <<<JS
(function(){
  try{
    function isSettings(){
      var h = (window.location.hash||'').replace(/^#/, '');
      return h === '/settings' || h.indexOf('/settings') === 0;
    }
    function ensureHost(){
      var app = document.getElementById('zibll-ad-app');
      if (!app) return null;
      var id = 'zibll-ad-link-redirect';
      var host = document.getElementById(id);
      if (!host) {
        host = document.createElement('div');
        host.id = id;
        host.style.margin = '16px';
        // 插入到应用容器顶部（和 notices 同层）
        app.insertBefore(host, app.firstChild);
      }
      return host;
    }
    function mountToggle(){
      if (!isSettings()) { unmountToggle(); return; }
      if (!window.Vue || !window.ElementPlus) return;
      var host = ensureHost();
      if (!host) return;
      if (window.__zibllAdRedirectToggleApp) return;
      var Vue = window.Vue;
      var ElMessage = window.ElementPlus.ElMessage;
      var restUrl = (window.zibllAdConfig && window.zibllAdConfig.restUrl) ? (window.zibllAdConfig.restUrl + '/settings') : '{$rest_url}';
      var wpNonce = (window.zibllAdConfig && window.zibllAdConfig.nonce) ? window.zibllAdConfig.nonce : '{$rest_nonce}';

      var Toggle = {
        data: function(){
          return {
            loading: false,
            saving: false,
            value: true,
          };
        },
        mounted: function(){ this.load(); },
        methods: {
          load: function(){
            var self = this; self.loading = true;
            fetch(restUrl, { method: 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': wpNonce } })
              .then(function(r){ return r.json(); })
              .then(function(j){ if (j && j.success && j.data) { self.value = !!j.data.link_redirect; } })
              .catch(function(e){ try{ ElMessage.error('加载链接重定向设置失败'); }catch(_){} })
              .finally(function(){ self.loading = false; });
          },
          save: function(){
            var self = this; if (self.saving) return; self.saving = true;
            fetch(restUrl, {
              method: 'PUT',
              credentials: 'same-origin',
              headers: { 'Content-Type':'application/json', 'X-WP-Nonce': wpNonce },
              body: JSON.stringify({ link_redirect: !!self.value })
            })
            .then(function(r){ return r.json(); })
            .then(function(j){ if (j && j.success) { try{ ElMessage.success('已保存链接重定向设置'); }catch(_){} } else { throw new Error(); } })
            .catch(function(){ try{ ElMessage.error('保存失败'); }catch(_){} })
            .finally(function(){ self.saving = false; });
          }
        },
        render: function(){
          var h = Vue.h; var self = this;
          return h('div', { class: 'zibll-ad-inline-card', style: { background:'#fff', border:'1px solid #e5e7eb', padding:'12px', marginBottom:'12px' } }, [
            h('div', { style: { display:'flex', alignItems:'center', gap:'12px' } }, [
              h('strong', null, '链接重定向'),
              h(window.ElementPlus.ElSwitch, {
                modelValue: self.value,
                'onUpdate:modelValue': function(v){ self.value = !!v; self.save(); },
                loading: self.loading || self.saving,
                activeText: '开启(go.php)',
                inactiveText: '关闭(直连)'
              })
            ])
          ]);
        }
      };
      try{
        var app = Vue.createApp(Toggle);
        app.use(window.ElementPlus); app.mount(host);
        window.__zibllAdRedirectToggleApp = app;
      }catch(e){ console.error('[Zibll Ad] link redirect toggle mount error', e); }
    }
    function unmountToggle(){
      try{
        if (window.__zibllAdRedirectToggleApp) {
          window.__zibllAdRedirectToggleApp.unmount();
          window.__zibllAdRedirectToggleApp = null;
        }
        var host = document.getElementById('zibll-ad-link-redirect');
        if (host) host.innerHTML = '';
      }catch(e){ console.error('[Zibll Ad] link redirect toggle unmount error', e); }
    }
    function init(){ mountToggle(); }
    if (document.readyState !== 'loading') { setTimeout(init, 0); } else { document.addEventListener('DOMContentLoaded', function(){ setTimeout(init, 0); }); }
    window.addEventListener('hashchange', function(){ setTimeout(mountToggle, 0); });
  }catch(e){ console.error('[Zibll Ad] inject link redirect toggle error', e); }
})();
JS;

        return $existing . "\n" . $js;
    }

    /**
     * 获取帮助文档 URL
     *
     * @return string
     */
    private function get_help_url() {
        return apply_filters(
            'zibll_ad_help_url',
            'https://example.com/docs/zibll-ad' // TODO: 替换为实际文档地址
        );
    }

    /**
     * 获取技术支持 URL
     *
     * @return string
     */
    private function get_support_url() {
        return apply_filters(
            'zibll_ad_support_url',
            'https://wpa.qq.com/msgrd?v=3&uin=3092548013&site=qq&menu=yes'
        );
    }

    /**
     * 获取 GitHub 仓库 URL
     *
     * @return string
     */
    private function get_github_url() {
        return apply_filters(
            'zibll_ad_github_url',
            'https://github.com/example/zibll-ad' // TODO: 替换为实际仓库地址
        );
    }
}
