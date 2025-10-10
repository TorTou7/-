<?php
/**
 * 自助广告位 Widget 类 - 服务器端渲染架构（严格遵循开发方案）
 *
 * 架构设计思想：
 * ================
 * 1. 服务器端渲染（SSR）
 *    - Widget 直接输出完整 HTML 结构（<a><img> 或文字链接）
 *    - SEO 友好，搜索引擎可爬取广告内容
 *    - 首屏渲染快速，无需等待 JS 加载
 *
 * 2. 主题集成
 *    - 复用 Zib_CFSwidget 封装（标题、显示规则）
 *    - 使用主题样式类 .theme-box、.zib-widget
 *    - 兼容主题响应式布局系统
 *
 * 3. 布局系统
 *    - CSS Grid 布局（--per-row 自定义属性）
 *    - 空位添加 .is-empty 类和 data-* 属性
 *    - 响应式断点支持（移动端/PC端）
 *
 * 4. 性能优化策略
 *    - 缓存渲染后的完整 HTML（transient）
 *    - 订单变更时精准清除对应缓存
 *    - 支持多实例页面优化
 *
 * 5. 渐进增强
 *    - 前端 JS 仅负责购买交互（可选）
 *    - 禁用 JS 时广告位仍可正常展示
 *    - 空位点击事件由 jQuery 处理
 *
 * @package Zibll_Ad
 * @version 1.0.0
 * @see 开发方案.txt 步骤 3.1
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 自助广告位 Widget 类
 *
 * 遵循开发方案：
 * - 继承 WP_Widget（可选集成 Zib_CFSwidget）
 * - 服务器端直接渲染 HTML
 * - 支持图片型和文字型广告位
 */
class Zibll_Ad_Widget extends WP_Widget {

    /**
     * 构造函数
     */
    public function __construct() {
        parent::__construct(
            'zibll_ad_widget',
            __('子比自助广告位', 'zibll-ad'),
            array(
                'description' => __('展示自助广告位内容（Vue + Element Plus）', 'zibll-ad'),
                'classname' => 'zibll-ad-widget',
                'customize_selective_refresh' => true,
            )
        );
    }

    /**
     * 前端输出 - 服务器端渲染完整 HTML
     *
     * 开发方案要求（步骤 3.1）：
     * - 从 $instance['slot_id'] 获取 slot 配置
     * - 检查缓存：get_transient("zibll_ad_slot_render_{$slot_id}_{$hash}")
     * - 无缓存则调用 Slot_Model::get_units_for_render($slot_id)
     * - 根据 slot_type 渲染：
     *   - 图片型：循环输出 <a href="..."><img src="..." /></a>
     *   - 文字型：输出 <a href="..." style="color:...">文字</a>
     * - 空位：添加 class="is-empty", data-slot, data-unit 属性，绑定购买按钮
     * - 应用布局样式：style="--per-row:{$per_row}"，使用 CSS Grid
     * - 缓存渲染结果（1小时）
     *
     * @param array $args     Widget 参数
     * @param array $instance Widget 实例配置
     */
    public function widget($args, $instance) {
        $slot_id = isset($instance['slot_id']) ? intval($instance['slot_id']) : 0;

        if (!$slot_id) {
            return;
        }

        // 检查 Slot Model 是否存在
        if (!class_exists('Zibll_Ad_Slot_Model')) {
            return;
        }

        $slot = Zibll_Ad_Slot_Model::get($slot_id);

        if (!$slot) {
            // 仅管理员可见错误提示
            if (current_user_can('manage_zibll_ads')) {
                echo '<div class="zibll-ad-error" style="padding:20px;background:#fff3cd;border-left:4px solid #ffc107;color:#856404;">';
                echo esc_html(sprintf(__('广告位 #%d 不存在', 'zibll-ad'), $slot_id));
                echo '</div>';
            }
            return;
        }

        // 检查是否启用
        if (isset($slot['enabled']) && !$slot['enabled']) {
            return;
        }

        // 检查设备显示设置
        $device_display = isset($slot['device_display']) ? $slot['device_display'] : 'all';
        $is_mobile = wp_is_mobile();
        
        // 根据设备显示设置决定是否渲染
        if ($device_display === 'pc' && $is_mobile) {
            // 仅PC端显示，但当前是移动设备
            return;
        } elseif ($device_display === 'mobile' && !$is_mobile) {
            // 仅移动端显示，但当前是PC设备
            return;
        }
        // device_display === 'all' 时，不做任何限制

        // ========================================
        // 集成 Zib_CFSwidget（主题样式封装）
        // ========================================
        $use_zib_widget = class_exists('Zib_CFSwidget');

        // 构建 Zib_CFSwidget 兼容的 $instance 参数
        $widget_instance = array_merge($instance, array(
            'show_type' => isset($instance['show_type']) ? $instance['show_type'] : 'all',
            'title' => isset($instance['title']) ? $instance['title'] : '',
        ));

        // 触发前置钩子
        do_action('zibll_ad_before_widget', $slot_id, $slot, $args, $instance);

        // 使用主题样式包裹
        if ($use_zib_widget) {
            // 使用 Zib_CFSwidget 标准输出
            Zib_CFSwidget::echo_before($widget_instance, 'theme-box zib-widget mb20', $args);
        } else {
            // 降级方案：使用原生 Widget 容器
            echo $args['before_widget'];

            if (!empty($instance['title'])) {
                echo $args['before_title'];
                echo esc_html($instance['title']);
                echo $args['after_title'];
            }
        }

        // ========================================
        // 渲染广告位内容（核心逻辑）
        // ========================================
        $this->render_ad_slot_content($slot_id, $slot);

        // 使用主题样式包裹结束
        if ($use_zib_widget) {
            Zib_CFSwidget::echo_after($widget_instance, $args);
        } else {
            echo $args['after_widget'];
        }

        // 触发后置钩子
        do_action('zibll_ad_after_widget', $slot_id, $slot, $args, $instance);
    }

    /**
     * 渲染广告位内容（核心渲染逻辑）
     *
     * 用户需求更新：
     * - 文字型广告位使用全新的 card 样式模板
     * - 顶部添加 card-head（图标+标题+"立即入驻"按钮）
     * - 使用 posts-row 网格布局
     * - 每个广告单元严格按照模板结构输出
     *
     * @param int   $slot_id 广告位 ID
     * @param array $slot    广告位配置数组
     */
    private function render_ad_slot_content($slot_id, $slot) {
        // ========================================
        // 步骤 1：检查缓存（改为版本化 key，避免 Redis 下清理不即时）
        // ========================================
        // 使用数据库中 units 的最近更新时间生成版本号(hash)，
        // 每次投放变化都会产生新 key，从而立即命中“空缓存”强制重渲染。
        if (!function_exists('zibll_ad_generate_cache_hash')) {
            require_once ZIBLL_AD_PATH . 'includes/helpers.php';
        }
        $cache_hash = zibll_ad_generate_cache_hash($slot_id, $slot);
        $cache_key  = 'zibll_ad_slot_render_' . $slot_id . '_' . $cache_hash;

        // 开启 nonce 校验且用户已登录时跳过共享缓存，避免复用他人生成的 nonce
        $nonce_per_user = (function_exists('_pz') && _pz('go_link_nonce_s') && function_exists('is_user_logged_in') && is_user_logged_in());
        $cached_html = (!$nonce_per_user && !(defined('ZIBLL_AD_DISABLE_CACHE') && ZIBLL_AD_DISABLE_CACHE)) ? get_transient($cache_key) : false;

        if (false !== $cached_html && (!defined('WP_DEBUG') || !WP_DEBUG)) {
            // 直接输出缓存的 HTML
            echo $cached_html;
            return;
        }

        // ========================================
        // 步骤 1.5：获取全局设置（检查是否暂停投放）
        // ========================================
        $settings = class_exists('Zibll_Ad_Settings') ? Zibll_Ad_Settings::get_all() : array();
        $global_pause_purchase = isset($settings['global_pause_purchase']) ? (bool) $settings['global_pause_purchase'] : false;

        // ========================================
        // 步骤 2：获取 units 数据
        // ========================================
        $units = Zibll_Ad_Slot_Model::get_units_for_render($slot_id);

        // 提取配置
        $slot_type = isset($slot['slot_type']) ? $slot['slot_type'] : 'image';
        $image_display_mode = isset($slot['image_display_mode']) ? $slot['image_display_mode'] : 'grid';
        $display_layout = isset($slot['display_layout']) ? $slot['display_layout'] : array();
        $default_media = isset($slot['default_media']) ? $slot['default_media'] : array();
        $text_color_options = isset($slot['text_color_options']) ? $slot['text_color_options'] : array();

        // 布局配置 - 根据显示模式计算最大显示数量
        if ($slot_type === 'image' && $image_display_mode === 'carousel') {
            // 幻灯片模式：使用 carousel_count
            $max_display = isset($display_layout['carousel_count']) ? intval($display_layout['carousel_count']) : 3;
            $max_display = max(1, $max_display);
            $per_row = 1; // 幻灯片不需要per_row
            $rows = $max_display;
        } else {
            // 普通模式：使用 rows * per_row
            $per_row = isset($display_layout['per_row']) ? intval($display_layout['per_row']) : 4;
            $per_row = max(1, $per_row); // 至少1列

            $rows = isset($display_layout['rows']) ? intval($display_layout['rows']) : 2;
            $rows = max(1, $rows); // 至少1行

            $max_display = $rows * $per_row;
        }

        // 移动端布局配置（根据广告类型设置默认值）
        $default_mobile_per_row = ($slot_type === 'text') ? 2 : 1;
        $mobile_per_row = isset($display_layout['mobile_per_row']) ? intval($display_layout['mobile_per_row']) : $default_mobile_per_row;
        $mobile_per_row = max(1, $mobile_per_row); // 至少1列
        $mobile_rows = ceil($max_display / $mobile_per_row);

        // 图片长宽比配置
        $image_aspect_ratio = isset($slot['image_aspect_ratio']) ? $slot['image_aspect_ratio'] : array('width' => 8, 'height' => 1);

        // ========================================
        // 步骤 3：开始渲染 HTML（捕获到缓冲区）
        // ========================================
        ob_start();

        // 检查图片广告的显示模式
        $image_display_mode = isset($slot['image_display_mode']) ? $slot['image_display_mode'] : 'grid';

        // 文字型使用新模板，图片型根据display_mode选择渲染方式
        if ($slot_type === 'text') {
            $this->render_text_slot_with_card($slot_id, $slot, $units, $per_row, $max_display, $default_media, $text_color_options, $global_pause_purchase, $mobile_per_row);
        } elseif ($slot_type === 'image' && $image_display_mode === 'carousel') {
            // 幻灯片模式
            $this->render_image_carousel($slot_id, $slot, $units, $max_display, $default_media, $global_pause_purchase, $image_aspect_ratio);
        } else {
            // 普通网格模式
            $this->render_image_slot_traditional($slot_id, $slot, $units, $per_row, $max_display, $default_media, $global_pause_purchase, $image_aspect_ratio, $mobile_per_row);
        }

        // ========================================
        // 步骤 4：缓存渲染结果
        // ========================================
        $html_output = ob_get_clean();

        // 缓存（默认 1 小时），可通过过滤器调整
        if (!(defined('ZIBLL_AD_DISABLE_CACHE') && ZIBLL_AD_DISABLE_CACHE) && !$nonce_per_user) {
            $ttl = apply_filters('zibll_ad_render_cache_ttl', 3600, $slot_id, $slot);
            set_transient($cache_key, $html_output, intval($ttl));
        }

        // 输出 HTML
        echo $html_output;

        // 调试信息（仅管理员可见）
        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_zibll_ads')) {
            echo "\n<!-- Zibll Ad Debug:\n";
            echo "Slot ID: {$slot_id}\n";
            echo "Type: {$slot_type}\n";
            echo "Layout: {$per_row} per row, {$rows} rows\n";
            echo "Units: {$max_display} total\n";
            echo "Cached: " . ($cached_html ? 'Yes' : 'No (Fresh Render)') . "\n";
            echo "Global Pause: " . ($global_pause_purchase ? 'Yes' : 'No') . "\n";
            echo "-->\n";
        }
    }

    /**
     * 渲染图片型广告位（幻灯片模式 - 使用主题Swiper）
     *
     * @param int   $slot_id               广告位 ID
     * @param array $slot                  广告位配置
     * @param array $units                 广告单元数据
     * @param int   $max_display           最大显示数量
     * @param array $default_media         默认展示内容
     * @param bool  $global_pause_purchase 全局暂停购买
     * @param array $image_aspect_ratio    图片长宽比
     */
    private function render_image_carousel($slot_id, $slot, $units, $max_display, $default_media, $global_pause_purchase = false, $image_aspect_ratio = array('width' => 8, 'height' => 1)) {
        // 获取默认图片
        $default_image = isset($default_media['image_url']) && !empty($default_media['image_url'])
            ? $default_media['image_url']
            : '';

        if (empty($default_image)) {
            $default_image = 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="100" viewBox="0 0 800 100">
                    <rect fill="#f5f5f5" width="800" height="100"/>
                    <text x="50%" y="50%" fill="#909399" font-size="16" text-anchor="middle" dy=".3em">广告位招租</text>
                </svg>'
            );
        }

        $default_alt = isset($default_media['alt']) && !empty($default_media['alt'])
            ? $default_media['alt']
            : __('广告位招租', 'zibll-ad');

        // 获取广告位标题
        $widget_title = isset($slot['widget_title']) && !empty($slot['widget_title'])
            ? $slot['widget_title']
            : '恰饭广告 感谢支持';

        // 计算长宽比对应的高度
        $aspect_ratio_width = isset($image_aspect_ratio['width']) ? intval($image_aspect_ratio['width']) : 8;
        $aspect_ratio_height = isset($image_aspect_ratio['height']) ? intval($image_aspect_ratio['height']) : 1;
        
        // 根据长宽比计算高度（假设宽度为100%）
        // 例如：8:1 比例，高度约为 12.5% 的宽度
        $aspect_ratio_percent = ($aspect_ratio_height / $aspect_ratio_width) * 100;
        
        // 为幻灯片设置合适的高度（PC和移动端）
        // 假设容器宽度：PC端约1200px，移动端约375px
        $pc_height = wp_is_mobile() ? 0 : round(1200 * $aspect_ratio_height / $aspect_ratio_width);
        $m_height = wp_is_mobile() ? round(375 * $aspect_ratio_height / $aspect_ratio_width) : 0;

        // 准备幻灯片数据
        $slides = array();
        
        for ($i = 0; $i < $max_display; $i++) {
            $unit = isset($units[$i]) ? $units[$i] : null;
            $is_empty = !$unit || (isset($unit['is_empty']) && $unit['is_empty']);
            $unit_key = $unit ? (isset($unit['unit_key']) ? $unit['unit_key'] : $i) : $i;
            $status = $unit ? (isset($unit['status']) ? $unit['status'] : 'available') : 'available';

            $slide_data = array();

            if ($is_empty || $status === 'available') {
                // 空位：设置背景图
                $slide_data['background'] = $default_image;
                
                // 给slide添加特殊class，用于JS识别空位
                $slide_data['class'] = 'zibll-ad-carousel-empty zibll-ad-carousel-slot-' . $slot_id . '-unit-' . $unit_key;
                
                if (!$global_pause_purchase) {
                    // 设置为伪链接，方便点击
                    $slide_data['link'] = array(
                        'url' => '#ad-slot-' . $slot_id . '-unit-' . $unit_key,
                    );
                }
            } else {
                // 已售广告：设置背景图和外链
                $ad_image = isset($unit['image_url']) && !empty($unit['image_url'])
                    ? $unit['image_url']
                    : $default_image;
                $ad_link = isset($unit['website_url']) && !empty($unit['website_url'])
                    ? $unit['website_url']
                    : '';
                
                $slide_data['background'] = $ad_image;
                
                // 只有有效链接才设置，并应用重定向规则
                if ($ad_link && $ad_link !== '#') {
                    // 根据设置，决定是否走 go.php 重定向
                    if (!function_exists('zibll_ad_maybe_redirect_url')) {
                        require_once ZIBLL_AD_PATH . 'includes/helpers.php';
                    }
                    $final_url = zibll_ad_maybe_redirect_url($ad_link);
                    
                    $slide_data['link'] = array(
                        'url' => $final_url,
                        'target' => true, // 新窗口打开
                    );
                }
            }

            $slides[] = $slide_data;
        }
        
        // 调试日志
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Zibll Ad Carousel - Slot ID: ' . $slot_id . ', Slides: ' . count($slides) . ', Max: ' . $max_display . ', carousel_count: ' . ($display_layout['carousel_count'] ?? 'not set'));
        }

        // 输出卡片头部（在幻灯片外层）
        ?>
        <!-- 卡片头部 -->
        <div class="card-head">
            <div class="text-sm">
                <span class="icon-hot">
                    <svg t="1759425764668" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="2908" width="200" height="200"><path d="M484.4 171.4a518.98 518.98 0 0 0 116.5 205.5l5.6 5.6 63.8 77.8 34.2-91.3c5.6-19.6 13.4-39.2 19-61.6 101.2 97.5 141.9 242 106.4 378-12.7 51.3-42 97-83.4 129.9-63.9 53.4-145.3 81.1-228.5 77.8-99.5 4.4-195.6-36.9-261-112-9.6-9.2-17.7-19.8-24.1-31.4-55-81.5-63.3-185.8-21.8-275 12.5 13.1 25.8 25.4 39.8 37 7.8 8.4 16.8 14 21.8 19.6l119.8 122.1-23-168c-20.1-117.5 23.6-237.1 114.9-314z m44.8-107.5c-136.1 91.3-247.5 241.9-224 427.8-29.1-28.6-85.1-64.4-101.4-119.8C103.3 486.3 92.2 654.1 177 780.7c9.7 15.7 20.7 30.5 33 44.2 76.4 89.7 189.7 139.4 307.4 135 97 1.9 191.6-30.4 267.1-91.3 51.1-41.6 88-97.9 105.8-161.3 47.3-190.9-33.4-390.5-199.9-495-3.8 49.7-14.9 98.6-33 145C576.7 281 530.4 175 529.2 63.9z" fill="" p-id="2909"></path></svg>
                </span><?php echo esc_html($widget_title); ?>
            </div>
            <?php if (!$global_pause_purchase) : ?>
            <a href="javascript:void(0);"
               class="btn vc-yellow btn-outline ml-auto zibll-ad-purchase-trigger"
               data-slot="<?php echo esc_attr($slot_id); ?>">
                <span class="icon-ad-copy">
                    <svg t="1759425804622" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="4823" width="200" height="200"><path d="M315.04 544h73.92L352 437.56 315.04 544zM704 512c-26.46 0-48 21.54-48 48s21.54 48 48 48 48-21.54 48-48-21.54-48-48-48zM928 128H96C43 128 0 171 0 224v576c0 53 43 96 96 96h832c53 0 96-43 96-96V224c0-53-43-96-96-96zM501.16 704h-33.88c-13.62 0-25.76-8.64-30.24-21.5L422.3 640h-140.58l-14.76 42.5A32 32 0 0 1 236.72 704h-33.88c-22.02 0-37.46-21.7-30.24-42.5L280 352.24A47.99 47.99 0 0 1 325.34 320h53.32A47.98 47.98 0 0 1 424 352.26l107.38 309.24c7.22 20.8-8.22 42.5-30.22 42.5zM848 672c0 17.68-14.32 32-32 32h-32c-9.7 0-18.08-4.54-23.96-11.36-17.24 7.32-36.18 11.36-56.04 11.36-79.4 0-144-64.6-144-144s64.6-144 144-144c16.92 0 32.92 3.46 48 8.84V352c0-17.68 14.32-32 32-32h32c17.68 0 32 14.32 32 32v320z" p-id="4824"></path></svg>
                </span>立即入驻
            </a>
            <?php endif; ?>
        </div>

        <!-- 使用主题的Swiper轮播函数 -->
        <?php
        // 调试：输出准备的数据
        if (current_user_can('manage_zibll_ads')) {
            echo "\n<!-- Zibll Ad Carousel Debug:\n";
            echo "Slot ID: {$slot_id}\n";
            echo "Max display: {$max_display}\n";
            echo "Slides count: " . count($slides) . "\n";
            echo "carousel_count from layout: " . ($display_layout['carousel_count'] ?? 'not set') . "\n";
            echo "\nSlides data:\n";
            foreach ($slides as $idx => $slide) {
                echo "Slide {$idx}: background=" . (isset($slide['background']) ? 'set' : 'not set');
                echo ", link=" . (isset($slide['link']['url']) ? $slide['link']['url'] : 'no link');
                echo ", has_html=" . (isset($slide['html']) && !empty($slide['html']) ? 'yes' : 'no') . "\n";
            }
            echo "-->\n";
        }
        
        if (function_exists('zib_new_slider') && !empty($slides)) {
            $slider_args = array(
                'class'        => 'zibll-ad-carousel zibll-ad-carousel-' . $slot_id . ' mb10',
                'slides'       => $slides,
                'loop'         => true,
                'autoplay'     => true,
                'interval'     => 4000,
                'pagination'   => true,
                'button'       => true,
                'scale_height' => true,
                'scale'        => $aspect_ratio_percent,
                'effect'       => 'slide',
            );
            
            zib_new_slider($slider_args, true);
            
            // 添加JS初始化幻灯片购买事件
            ?>
            <script>
            jQuery(document).ready(function($) {
                // 等待Swiper初始化完成后再绑定事件
                setTimeout(function() {
                    // 使用事件委托，监听所有空位点击
                    $(document).on('click', '.zibll-ad-carousel-<?php echo esc_js($slot_id); ?> .zibll-ad-carousel-empty', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // 从class中提取slot_id和unit_key
                        var classes = $(this).attr('class') || '';
                        var match = classes.match(/zibll-ad-carousel-slot-(\d+)-unit-(\d+)/);
                        
                        if (match) {
                            var slotId = parseInt(match[1], 10);
                            var unitKey = parseInt(match[2], 10);
                            
                            console.log('[Zibll Ad] 幻灯片空位点击:', { slotId, unitKey, classes: classes });
                            
                            // 触发购买事件
                            var event = new CustomEvent('zibll-ad-open-purchase', {
                                detail: {
                                    slotId: slotId,
                                    unitKey: unitKey
                                }
                            });
                            document.dispatchEvent(event);
                        }
                        
                        return false; // 阻止默认跳转
                    });
                    
                    // 添加样式和悬停效果
                    $('.zibll-ad-carousel-<?php echo esc_js($slot_id); ?> .swiper-slide.zibll-ad-carousel-empty').css({
                        'cursor': 'pointer'
                    }).attr('title', '点击购买此广告位');
                    
                    console.log('[Zibll Ad] 幻灯片事件监听已注册 - Slot <?php echo esc_js($slot_id); ?>');
                }, 500); // 延迟500ms，确保Swiper已初始化
            });
            </script>
            <?php
        } else {
            // 降级方案
            echo '<div class="zibll-ad-error" style="padding:20px;background:#fff3cd;border-left:4px solid #ffc107;color:#856404;">';
            if (empty($slides)) {
                echo '幻灯片数据为空 - Slides: ' . count($slides);
            } else {
                echo esc_html__('幻灯片功能需要子比主题支持', 'zibll-ad');
            }
            echo '</div>';
        }
        ?>
        <?php
    }

    /**
     * 渲染文字型广告位（新模板 - Card 样式）
     *
     * 直接输出到 theme-box 容器内：
     * - card-head 包含标题和"立即入驻"按钮
     * - card-body 使用 posts-row 网格布局
     * - 响应式：移动端2列，PC端根据配置
     * - 不使用额外的 .auto-ad-url 和 .card 包装层
     *
     * @param int   $slot_id               广告位 ID
     * @param array $slot                  广告位配置
     * @param array $units                 广告单元数据
     * @param int   $per_row               每行数量
     * @param int   $max_display           最大显示数量
     * @param array $default_media         默认媒体配置
     * @param array $text_color_options    颜色选项
     * @param bool  $global_pause_purchase 全局暂停购买
     */
    private function render_text_slot_with_card($slot_id, $slot, $units, $per_row, $max_display, $default_media, $text_color_options, $global_pause_purchase = false, $mobile_per_row = 1) {
        // 获取广告位标题，如果为空则使用默认标题
        $widget_title = isset($slot['widget_title']) && !empty($slot['widget_title'])
            ? $slot['widget_title']
            : '恰饭广告 感谢支持';
        ?>
        <!-- 卡片头部：标题 + 立即入驻按钮 -->
        <div class="card-head">
            <div class="text-sm">
                <span class="icon-hot">
                    <svg t="1759425764668" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="2908" width="200" height="200"><path d="M484.4 171.4a518.98 518.98 0 0 0 116.5 205.5l5.6 5.6 63.8 77.8 34.2-91.3c5.6-19.6 13.4-39.2 19-61.6 101.2 97.5 141.9 242 106.4 378-12.7 51.3-42 97-83.4 129.9-63.9 53.4-145.3 81.1-228.5 77.8-99.5 4.4-195.6-36.9-261-112-9.6-9.2-17.7-19.8-24.1-31.4-55-81.5-63.3-185.8-21.8-275 12.5 13.1 25.8 25.4 39.8 37 7.8 8.4 16.8 14 21.8 19.6l119.8 122.1-23-168c-20.1-117.5 23.6-237.1 114.9-314z m44.8-107.5c-136.1 91.3-247.5 241.9-224 427.8-29.1-28.6-85.1-64.4-101.4-119.8C103.3 486.3 92.2 654.1 177 780.7c9.7 15.7 20.7 30.5 33 44.2 76.4 89.7 189.7 139.4 307.4 135 97 1.9 191.6-30.4 267.1-91.3 51.1-41.6 88-97.9 105.8-161.3 47.3-190.9-33.4-390.5-199.9-495-3.8 49.7-14.9 98.6-33 145C576.7 281 530.4 175 529.2 63.9z" fill="" p-id="2909"></path></svg>
                </span><?php echo esc_html($widget_title); ?>
            </div>
            <?php if (!$global_pause_purchase) : ?>
            <a href="javascript:void(0);"
               class="btn vc-yellow btn-outline ml-auto zibll-ad-purchase-trigger"
               data-slot="<?php echo esc_attr($slot_id); ?>">
                <span class="icon-ad-copy">
                    <svg t="1759425804622" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="4823" width="200" height="200"><path d="M315.04 544h73.92L352 437.56 315.04 544zM704 512c-26.46 0-48 21.54-48 48s21.54 48 48 48 48-21.54 48-48-21.54-48-48-48zM928 128H96C43 128 0 171 0 224v576c0 53 43 96 96 96h832c53 0 96-43 96-96V224c0-53-43-96-96-96zM501.16 704h-33.88c-13.62 0-25.76-8.64-30.24-21.5L422.3 640h-140.58l-14.76 42.5A32 32 0 0 1 236.72 704h-33.88c-22.02 0-37.46-21.7-30.24-42.5L280 352.24A47.99 47.99 0 0 1 325.34 320h53.32A47.98 47.98 0 0 1 424 352.26l107.38 309.24c7.22 20.8-8.22 42.5-30.22 42.5zM848 672c0 17.68-14.32 32-32 32h-32c-9.7 0-18.08-4.54-23.96-11.36-17.24 7.32-36.18 11.36-56.04 11.36-79.4 0-144-64.6-144-144s64.6-144 144-144c16.92 0 32.92 3.46 48 8.84V352c0-17.68 14.32-32 32-32h32c17.68 0 32 14.32 32 32v320z" p-id="4824"></path></svg>
                </span>立即入驻
            </a>
            <?php endif; ?>
        </div>

        <!-- 卡片主体：网格布局 -->
        <div class="card-body posts-row" data-per-row="<?php echo esc_attr($per_row); ?>" data-mobile-per-row="<?php echo esc_attr($mobile_per_row); ?>" style="--per-row: <?php echo esc_attr($per_row); ?>; --mobile-per-row: <?php echo esc_attr($mobile_per_row); ?>;">
            <?php
            // 渲染每个广告单元
            for ($i = 0; $i < $max_display; $i++) {
                $unit = isset($units[$i]) ? $units[$i] : null;
                $is_empty = !$unit || (isset($unit['is_empty']) && $unit['is_empty']);
                $unit_key = $unit ? (isset($unit['unit_key']) ? $unit['unit_key'] : $i) : $i;
                $status = $unit ? (isset($unit['status']) ? $unit['status'] : 'available') : 'available';

                if ($is_empty) {
                    // 空位样式（完全复刻模板）
                    $empty_class = $global_pause_purchase ? 'auto-list-null' : 'auto-list-null zibll-ad-buy-trigger';
                    $empty_style = $global_pause_purchase ? 'cursor:default;opacity:0.6;' : 'cursor:pointer;';
                    ?>
                    <div class="<?php echo esc_attr($empty_class); ?>">
                        <div data-slot="<?php echo esc_attr($slot_id); ?>"
                             data-unit="<?php echo esc_attr($unit_key); ?>"
                             <?php if (!$global_pause_purchase) : ?>
                             class="zibll-ad-buy-trigger"
                             <?php endif; ?>
                             style="<?php echo esc_attr($empty_style); ?>">
                            <div class="auto-ad-img">
                                <svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M315.04 544h73.92L352 437.56 315.04 544zM704 512c-26.46 0-48 21.54-48 48s21.54 48 48 48 48-21.54 48-48-21.54-48-48-48zM928 128H96C43 128 0 171 0 224v576c0 53 43 96 96 96h832c53 0 96-43 96-96V224c0-53-43-96-96-96zM501.16 704h-33.88c-13.62 0-25.76-8.64-30.24-21.5L422.3 640h-140.58l-14.76 42.5A32 32 0 0 1 236.72 704h-33.88c-22.02 0-37.46-21.7-30.24-42.5L280 352.24A47.99 47.99 0 0 1 325.34 320h53.32A47.98 47.98 0 0 1 424 352.26l107.38 309.24c7.22 20.8-8.22 42.5-30.22 42.5zM848 672c0 17.68-14.32 32-32 32h-32c-9.7 0-18.08-4.54-23.96-11.36-17.24 7.32-36.18 11.36-56.04 11.36-79.4 0-144-64.6-144-144s64.6-144 144-144c16.92 0 32.92 3.46 48 8.84V352c0-17.68 14.32-32 32-32h32c17.68 0 32 14.32 32 32v320z"></path>
                                </svg>
                            </div>
                            <div class="auto-ad-name"></div>
                        </div>
                    </div>
                    <?php
                } else {
                    // 已售广告样式（按需：图标始终为首字母/首字符圆形头像，白色文字、随机背景色）
                    $website_name = isset($unit['website_name']) ? $unit['website_name'] : '';
                    $target_url = isset($unit['website_url']) ? $unit['website_url'] : '';
                    // 根据设置，决定是否走 go.php 重定向
                    if (!function_exists('zibll_ad_maybe_redirect_url')) {
                        require_once ZIBLL_AD_PATH . 'includes/helpers.php';
                    }
                    $final_url = zibll_ad_maybe_redirect_url($target_url);

                    // 获取用户选择的文字颜色
                    $color_key = isset($unit['color_key']) ? $unit['color_key'] : '';
                    $text_color = $this->get_color_from_key($text_color_options, $color_key);

                    // 如果没有选择颜色或颜色不存在，使用默认颜色
                    $text_style = '';
                    if (!empty($text_color)) {
                        $text_style = ' style="color: ' . esc_attr($text_color) . '"';
                    }

                    // 生成图标字母与颜色（颜色基于种子生成，保证同一广告稳定一致）
                    $icon_letter = $this->get_icon_letter($website_name, '');
                    $avatar_bg   = $this->get_avatar_bg_color($slot_id . '|' . $unit_key . '|' . $website_name);
                    ?>
                    <div class="auto-list">
                        <a href="<?php echo esc_url($final_url); ?>"
                           target="_blank"
                           rel="nofollow noopener">
                            <div class="auto-ad-img" style="background-color: <?php echo esc_attr($avatar_bg); ?>; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; line-height:1; font-size:12px;">
                                <?php echo esc_html($icon_letter); ?>
                            </div>
                            <div class="auto-ad-name"<?php echo $text_style; ?>><?php echo esc_html($website_name); ?></div>
                        </a>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染图片型广告位（新模板 - Card 布局）
     *
     * 用户需求更新：
     * - 使用简洁的 card + ad-container + ad-item 结构
     * - 每个广告使用自定义的 aspect-ratio 保持比例
     * - 每行数量和展示行数按照后台设置
     * - 默认显示后台设置的默认图片
     *
     * @param int   $slot_id               广告位 ID
     * @param array $slot                  广告位配置
     * @param array $units                 广告单元数据
     * @param int   $per_row               每行数量
     * @param int   $max_display           最大显示数量
     * @param array $default_media         默认媒体配置
     * @param bool  $global_pause_purchase 全局暂停购买
     * @param array $image_aspect_ratio    图片长宽比 ['width' => 8, 'height' => 1]
     */
    private function render_image_slot_traditional($slot_id, $slot, $units, $per_row, $max_display, $default_media, $global_pause_purchase = false, $image_aspect_ratio = array('width' => 8, 'height' => 1), $mobile_per_row = 1) {
        // 获取默认图片（字段名：image_url）
        $default_image = isset($default_media['image_url']) && !empty($default_media['image_url'])
            ? $default_media['image_url']
            : '';

        // 如果没有设置默认图片，使用占位SVG
        if (empty($default_image)) {
            $default_image = 'data:image/svg+xml;base64,' . base64_encode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="100" viewBox="0 0 800 100">
                    <rect fill="#f5f5f5" width="800" height="100"/>
                    <text x="50%" y="50%" fill="#909399" font-size="16" text-anchor="middle" dy=".3em">广告位招租</text>
                </svg>'
            );
        }

        $default_alt = isset($default_media['alt']) && !empty($default_media['alt'])
            ? $default_media['alt']
            : __('广告位招租', 'zibll-ad');

        // 获取广告位标题，如果为空则使用默认标题
        $widget_title = isset($slot['widget_title']) && !empty($slot['widget_title'])
            ? $slot['widget_title']
            : '恰饭广告 感谢支持';

        // 计算长宽比样式
        $aspect_ratio_width = isset($image_aspect_ratio['width']) ? intval($image_aspect_ratio['width']) : 8;
        $aspect_ratio_height = isset($image_aspect_ratio['height']) ? intval($image_aspect_ratio['height']) : 1;
        $aspect_ratio_css = $aspect_ratio_width . ' / ' . $aspect_ratio_height;

        ?>
        <!-- 卡片头部：标题 + 立即入驻按钮 -->
        <div class="card-head">
            <div class="text-sm">
                <span class="icon-hot">
                    <svg t="1759425764668" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="2908" width="200" height="200"><path d="M484.4 171.4a518.98 518.98 0 0 0 116.5 205.5l5.6 5.6 63.8 77.8 34.2-91.3c5.6-19.6 13.4-39.2 19-61.6 101.2 97.5 141.9 242 106.4 378-12.7 51.3-42 97-83.4 129.9-63.9 53.4-145.3 81.1-228.5 77.8-99.5 4.4-195.6-36.9-261-112-9.6-9.2-17.7-19.8-24.1-31.4-55-81.5-63.3-185.8-21.8-275 12.5 13.1 25.8 25.4 39.8 37 7.8 8.4 16.8 14 21.8 19.6l119.8 122.1-23-168c-20.1-117.5 23.6-237.1 114.9-314z m44.8-107.5c-136.1 91.3-247.5 241.9-224 427.8-29.1-28.6-85.1-64.4-101.4-119.8C103.3 486.3 92.2 654.1 177 780.7c9.7 15.7 20.7 30.5 33 44.2 76.4 89.7 189.7 139.4 307.4 135 97 1.9 191.6-30.4 267.1-91.3 51.1-41.6 88-97.9 105.8-161.3 47.3-190.9-33.4-390.5-199.9-495-3.8 49.7-14.9 98.6-33 145C576.7 281 530.4 175 529.2 63.9z" fill="" p-id="2909"></path></svg>
                </span><?php echo esc_html($widget_title); ?>
            </div>
            <?php if (!$global_pause_purchase) : ?>
            <a href="javascript:void(0);"
               class="btn vc-yellow btn-outline ml-auto zibll-ad-purchase-trigger"
               data-slot="<?php echo esc_attr($slot_id); ?>">
                <span class="icon-ad-copy">
                    <svg t="1759425804622" class="icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="4823" width="200" height="200"><path d="M315.04 544h73.92L352 437.56 315.04 544zM704 512c-26.46 0-48 21.54-48 48s21.54 48 48 48 48-21.54 48-48-21.54-48-48-48zM928 128H96C43 128 0 171 0 224v576c0 53 43 96 96 96h832c53 0 96-43 96-96V224c0-53-43-96-96-96zM501.16 704h-33.88c-13.62 0-25.76-8.64-30.24-21.5L422.3 640h-140.58l-14.76 42.5A32 32 0 0 1 236.72 704h-33.88c-22.02 0-37.46-21.7-30.24-42.5L280 352.24A47.99 47.99 0 0 1 325.34 320h53.32A47.98 47.98 0 0 1 424 352.26l107.38 309.24c7.22 20.8-8.22 42.5-30.22 42.5zM848 672c0 17.68-14.32 32-32 32h-32c-9.7 0-18.08-4.54-23.96-11.36-17.24 7.32-36.18 11.36-56.04 11.36-79.4 0-144-64.6-144-144s64.6-144 144-144c16.92 0 32.92 3.46 48 8.84V352c0-17.68 14.32-32 32-32h32c17.68 0 32 14.32 32 32v320z" p-id="4824"></path></svg>
                </span>立即入驻
            </a>
            <?php endif; ?>
        </div>

        <!-- 图片型广告位容器 -->
        <div class="ad-container" data-per-row="<?php echo esc_attr($per_row); ?>" data-mobile-per-row="<?php echo esc_attr($mobile_per_row); ?>" style="--ad-aspect-ratio: <?php echo esc_attr($aspect_ratio_css); ?>; --per-row: <?php echo esc_attr($per_row); ?>; --mobile-per-row: <?php echo esc_attr($mobile_per_row); ?>;">
            <?php
            for ($i = 0; $i < $max_display; $i++) {
                $unit = isset($units[$i]) ? $units[$i] : null;

                // 判断是否为空位
                $is_empty = !$unit || (isset($unit['is_empty']) && $unit['is_empty']);
                $unit_key = $unit ? (isset($unit['unit_key']) ? $unit['unit_key'] : $i) : $i;
                $status = $unit ? (isset($unit['status']) ? $unit['status'] : 'available') : 'available';

                if ($is_empty || $status === 'available') {
                    // 空位：显示默认图片，点击可购买（除非全局暂停）
                    $empty_class = $global_pause_purchase
                        ? 'ad-item ad-item-empty'
                        : 'ad-item ad-item-empty zibll-ad-buy-trigger';
                    $empty_style = $global_pause_purchase ? 'cursor:default;opacity:0.6;' : '';
                    $empty_tag = $global_pause_purchase ? 'div' : 'a';
                    $empty_attrs = $global_pause_purchase ? '' : 'href="javascript:void(0);"';
                    ?>
                    <?php if ($global_pause_purchase) : ?>
                    <div class="<?php echo esc_attr($empty_class); ?>"
                         data-slot="<?php echo esc_attr($slot_id); ?>"
                         data-unit="<?php echo esc_attr($unit_key); ?>"
                         title="<?php echo esc_attr($default_alt); ?>"
                         style="<?php echo esc_attr($empty_style); ?>">
                        <img src="<?php echo esc_url($default_image); ?>"
                             alt="<?php echo esc_attr($default_alt); ?>">
                    </div>
                    <?php else : ?>
                    <a href="javascript:void(0);"
                       class="<?php echo esc_attr($empty_class); ?>"
                       data-slot="<?php echo esc_attr($slot_id); ?>"
                       data-unit="<?php echo esc_attr($unit_key); ?>"
                       title="<?php echo esc_attr($default_alt); ?>">
                        <img src="<?php echo esc_url($default_image); ?>"
                             alt="<?php echo esc_attr($default_alt); ?>">
                    </a>
                    <?php endif; ?>
                    <?php
                } else {
                    // 已售广告：显示用户上传的图片
                    $image_url = isset($unit['image_url']) && !empty($unit['image_url'])
                        ? $unit['image_url']
                        : $default_image;
                    $image_alt = isset($unit['website_name']) && !empty($unit['website_name'])
                        ? $unit['website_name']
                        : $default_alt;
                    $link_url = isset($unit['website_url']) && !empty($unit['website_url'])
                        ? $unit['website_url']
                        : '#';
                    // 根据设置，决定是否走 go.php 重定向
                    if (!function_exists('zibll_ad_maybe_redirect_url')) {
                        require_once ZIBLL_AD_PATH . 'includes/helpers.php';
                    }
                    $final_url = ($link_url && $link_url !== '#') ? zibll_ad_maybe_redirect_url($link_url) : $link_url;
                    ?>
                    <a href="<?php echo esc_url($final_url); ?>"
                       class="ad-item"
                       target="_blank"
                       rel="noopener noreferrer"
                       title="<?php echo esc_attr($image_alt); ?>">
                        <img src="<?php echo esc_url($image_url); ?>"
                             alt="<?php echo esc_attr($image_alt); ?>">
                    </a>
                    <?php
                }
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染图片型广告单元
     *
     * 开发方案要求：
     * - 输出 <a> 包裹 <img>，提供 data-unit/data-slot 属性
     * - 空位使用默认招租图
     *
     * @param array|null $unit          单元数据
     * @param bool       $is_empty      是否为空位
     * @param array      $default_media 默认媒体配置
     */
    private function render_image_unit($unit, $is_empty, $default_media) {
        if ($is_empty) {
            // 空位：显示默认招租图
            $image_url = isset($default_media['image_url']) ? $default_media['image_url'] : '';
            $image_alt = isset($default_media['alt']) ? $default_media['alt'] : __('广告位招租', 'zibll-ad');
            $link_url = isset($default_media['url']) ? $default_media['url'] : 'javascript:void(0)';
            $link_target = isset($default_media['url']) && $default_media['url'] ? '_blank' : '_self';

            // 如果没有默认图，使用占位符
            if (empty($image_url)) {
                $image_url = 'data:image/svg+xml;base64,' . base64_encode('
                    <svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">
                        <rect fill="#f0f2f5" width="400" height="300"/>
                        <text x="50%" y="50%" fill="#909399" font-size="18" text-anchor="middle" dy=".3em">广告位招租</text>
                    </svg>
                ');
            }
        } else {
            // 已售广告
            $image_url = isset($unit['image_url']) ? $unit['image_url'] : '';
            $image_alt = isset($unit['website_name']) ? $unit['website_name'] : '';
            $link_url = isset($unit['website_url']) ? $unit['website_url'] : '';
            // 根据设置，决定是否走 go.php 重定向
            if (!function_exists('zibll_ad_maybe_redirect_url')) {
                require_once ZIBLL_AD_PATH . 'includes/helpers.php';
            }
            $link_url = zibll_ad_maybe_redirect_url($link_url);
            $link_target = '_blank';
        }

        // 渲染 HTML
        if ($link_url && $link_url !== 'javascript:void(0)') {
            ?>
            <a href="<?php echo esc_url($link_url); ?>"
               target="<?php echo esc_attr($link_target); ?>"
               rel="nofollow noopener"
               class="ad-link">
                <img src="<?php echo esc_url($image_url); ?>"
                     alt="<?php echo esc_attr($image_alt); ?>"
                     class="ad-image"
                     loading="lazy">
            </a>
            <?php
        } else {
            ?>
            <div class="ad-image-wrapper">
                <img src="<?php echo esc_url($image_url); ?>"
                     alt="<?php echo esc_attr($image_alt); ?>"
                     class="ad-image"
                     loading="lazy">
            </div>
            <?php
        }
    }


    /**
     * 获取完整颜色选项（含背景色）
     *
     * @param array  $color_options 颜色选项数组
     * @param string $color_key     颜色键
     * @return array|null 颜色选项数组或 null
     */
    private function get_color_option_from_key($color_options, $color_key) {
        if (empty($color_key) || !is_array($color_options)) {
            return null;
        }

        foreach ($color_options as $option) {
            if (isset($option['key']) && $option['key'] === $color_key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * 颜色转浅色背景（自动计算）
     *
     * 将深色文字颜色转换为对应的浅色背景
     * 例如：#ff6b6b → rgba(255, 107, 107, 0.1)
     *
     * @param string $color 文字颜色（Hex 格式）
     * @return string 背景色（rgba 格式）
     */
    private function color_to_bg($color) {
        // 移除 # 号
        $hex = ltrim($color, '#');

        // 转换为 RGB
        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }

        // 返回 10% 透明度的背景色
        return sprintf('rgba(%d, %d, %d, 0.1)', $r, $g, $b);
    }

    /**
     * 生成图标字母
     *
     * 优先级：网站名首字 → 文字内容首字 → A
     *
     * @param string $website_name 网站名称
     * @param string $text_content 文字内容
     * @return string 单个字符
     */
    private function get_icon_letter($website_name, $text_content) {
        // 优先使用网站名
        if (!empty($website_name)) {
            $first_char = mb_substr($website_name, 0, 1, 'UTF-8');
            if (!empty($first_char)) {
                return $first_char;
            }
        }

        // 降级使用文字内容
        if (!empty($text_content)) {
            $first_char = mb_substr($text_content, 0, 1, 'UTF-8');
            if (!empty($first_char)) {
                return $first_char;
            }
        }

        // 默认字母
        return 'A';
    }

    /**
     * 从颜色选项中获取颜色代码
     *
     * @param array  $color_options 颜色选项数组
     * @param string $color_key     颜色键
     * @return string 颜色代码（Hex 格式）
     */
    private function get_color_from_key($color_options, $color_key) {
        if (empty($color_key) || !is_array($color_options)) {
            return '';
        }

        foreach ($color_options as $option) {
            if (isset($option['key']) && $option['key'] === $color_key) {
                return isset($option['color']) ? $option['color'] : '';
            }
        }

        return '';
    }

    /**
     * 后台表单（Widget 设置界面）
     *
     * 开发方案要求：
     * - 后台 widget 配置界面（简单显示 slot_id，或提供下拉选择）
     *
     * @param array $instance 当前实例配置
     */
    public function form($instance) {
        $slot_id = isset($instance['slot_id']) ? intval($instance['slot_id']) : 0;
        $title = isset($instance['title']) ? $instance['title'] : '';

        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('标题（可选）：', 'zibll-ad'); ?>
            </label>
            <input
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                type="text"
                value="<?php echo esc_attr($title); ?>"
                placeholder="<?php esc_attr_e('留空则显示默认标题', 'zibll-ad'); ?>"
            >
        </p>

        <p>
            <label for="<?php echo esc_attr($this->get_field_id('slot_id')); ?>">
                <?php esc_html_e('广告位 ID：', 'zibll-ad'); ?>
            </label>
            <input
                class="widefat"
                id="<?php echo esc_attr($this->get_field_id('slot_id')); ?>"
                name="<?php echo esc_attr($this->get_field_name('slot_id')); ?>"
                type="number"
                value="<?php echo esc_attr($slot_id); ?>"
                readonly
                style="background-color: #f0f0f0; cursor: not-allowed;"
            >
            <small style="color:#666; display:block; margin-top:5px;">
                <?php esc_html_e('此 ID 由系统自动管理，请勿手动修改', 'zibll-ad'); ?>
            </small>
        </p>

        <?php if ($slot_id && class_exists('Zibll_Ad_Slot_Model')): ?>
            <?php $slot = Zibll_Ad_Slot_Model::get($slot_id); ?>
            <?php if ($slot): ?>
                <div style="padding:12px; background:#f0f0f1; border-left:4px solid #2271b1; margin:10px 0; border-radius:4px;">
                    <strong style="display:block; margin-bottom:8px; color:#2271b1;">
                        📌 <?php echo esc_html($slot['title']); ?>
                    </strong>
                    <small style="color:#666; line-height:1.6;">
                        <strong><?php esc_html_e('类型：', 'zibll-ad'); ?></strong>
                        <?php echo esc_html($slot['slot_type'] === 'image' ? __('图片', 'zibll-ad') : __('文字', 'zibll-ad')); ?>
                        <br>
                        <strong><?php esc_html_e('单元数：', 'zibll-ad'); ?></strong>
                        <?php echo esc_html(isset($slot['display_layout']['max_items']) ? $slot['display_layout']['max_items'] : 0); ?>
                        <br>
                        <strong><?php esc_html_e('布局：', 'zibll-ad'); ?></strong>
                        <?php
                        if (isset($slot['display_layout']['per_row'])) {
                            echo esc_html(sprintf(
                                __('每行 %d 个', 'zibll-ad'),
                                $slot['display_layout']['per_row']
                            ));
                        }
                        ?>
                    </small>
                </div>
            <?php else: ?>
                <div style="padding:12px; background:#fff3cd; border-left:4px solid #ffc107; color:#856404; border-radius:4px;">
                    ⚠️ <?php esc_html_e('警告：关联的广告位不存在', 'zibll-ad'); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p style="margin-top:15px; padding-top:15px; border-top:1px solid #ddd;">
            <small style="color:#666; line-height:1.6;">
                💡 <?php esc_html_e('提示：通过后台"自助广告位"菜单可以管理广告位的挂载位置和配置', 'zibll-ad'); ?>
            </small>
        </p>
        <?php
    }

    /**
     * 基于种子生成稳定的 HSL 颜色（随机但可复现）
     *
     * @param string $seed
     * @return string e.g. hsl(210, 72%, 62%)
     */
    private function get_avatar_bg_color($seed) {
        $seed = (string) $seed;
        // 使用 crc32 作为轻量 hash
        $hash = sprintf('%u', crc32($seed));
        $hue = intval($hash) % 360;     // 0-359
        $sat = 72;                      // 亮丽一些
        $light = 62;                    // 偏亮，保证白字可读
        return 'hsl(' . $hue . ', ' . $sat . '%, ' . $light . '%)';
    }

    /**
     * 更新 Widget 实例
     *
     * 开发方案要求：
     * - 支持标题修改
     * - slot_id 由系统管理
     * - 更新时清除缓存
     *
     * @param array $new_instance 新的实例配置
     * @param array $old_instance 旧的实例配置
     * @return array 更新后的实例配置
     */
    public function update($new_instance, $old_instance) {
        $instance = array();

        // 标题可以由用户修改
        $instance['title'] = !empty($new_instance['title'])
            ? sanitize_text_field($new_instance['title'])
            : '';

        // slot_id 由系统管理，保持不变
        $instance['slot_id'] = isset($old_instance['slot_id'])
            ? intval($old_instance['slot_id'])
            : 0;

        // 如果是新实例且提供了 slot_id，使用新的
        if (!$instance['slot_id'] && !empty($new_instance['slot_id'])) {
            $instance['slot_id'] = intval($new_instance['slot_id']);
        }

        // ========================================
        // 清除缓存（重要！）
        // ========================================
        if ($instance['slot_id']) {
            zibll_ad_clear_slot_cache($instance['slot_id']);
        }

        return $instance;
    }
}
