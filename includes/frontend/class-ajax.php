<?php
/**
 * 前端 AJAX 处理类
 *
 * 功能职责：
 * ========
 * 1. 处理前端Vue组件发起的购买请求
 * 2. 数据校验（URL、联系方式、必填字段等）
 * 3. 单元锁定（设置为pending状态，防止重复购买）
 * 4. 价格计算（防止前端篡改）
 * 5. 与ZibPay支付系统对接
 *
 * 安全措施：
 * ========
 * 1. Nonce验证
 * 2. 数据过滤和校验
 * 3. SQL预处理
 * 4. 输出转义
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Frontend_AJAX {

    /**
     * 构造函数 - 注册AJAX钩子
     */
    public function __construct() {
        // 获取广告位信息（登录用户）
        add_action('wp_ajax_zibll_ad_get_slot_info', array($this, 'get_slot_info'));

        // 获取广告位信息（未登录用户）
        add_action('wp_ajax_nopriv_zibll_ad_get_slot_info', array($this, 'get_slot_info'));

        // 获取支付方式表单（登录用户）
        add_action('wp_ajax_zibll_ad_get_payment_form', array($this, 'get_payment_form'));

        // 获取支付方式表单（未登录用户）
        add_action('wp_ajax_nopriv_zibll_ad_get_payment_form', array($this, 'get_payment_form'));

        // 注册登录用户的AJAX处理
        add_action('wp_ajax_zibll_ad_prepare_order', array($this, 'prepare_order'));

        // 注册未登录用户的AJAX处理
        add_action('wp_ajax_nopriv_zibll_ad_prepare_order', array($this, 'prepare_order'));

        // 图片上传（登录/未登录均可用于下单前上传素材）
        add_action('wp_ajax_zibll_ad_upload_image', array($this, 'upload_image'));
        add_action('wp_ajax_nopriv_zibll_ad_upload_image', array($this, 'upload_image'));
    }

    /**
     * 获取广告位信息
     *
     * 用于购买模态框加载数据
     * 返回广告位配置、定价信息、可用单元列表
     *
     * @return void (输出JSON)
     */
    public function get_slot_info() {
        try {
            // ========================================
            // 第一步：安全验证
            // ========================================
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
                throw new Exception('安全验证失败');
            }

            // ========================================
            // 第二步：获取参数
            // ========================================
            $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;

            if (!$slot_id) {
                throw new Exception('广告位ID无效');
            }

            // ========================================
            // 第三步：获取广告位配置
            // ========================================
            $slot = Zibll_Ad_Slot_Model::get($slot_id);

            if (!$slot) {
                throw new Exception('广告位不存在');
            }

            // ========================================
            // 第四步：获取所有单元信息
            // ========================================
            $units = Zibll_Ad_Slot_Model::get_units_for_render($slot_id);

            // ========================================
            // 第五步：检查全局暂停投放状态
            // ========================================
            $settings = class_exists('Zibll_Ad_Settings') ? Zibll_Ad_Settings::get_all() : array();
            $global_pause_purchase = isset($settings['global_pause_purchase']) ? (bool) $settings['global_pause_purchase'] : false;

            // ========================================
            // 第六步：构建响应数据
            // ========================================
            // 购买须知：优先使用广告位配置，未设置则回退到插件默认设置
            $default_notice = function_exists('zibll_ad_get_option') ? zibll_ad_get_option('default_purchase_notice', '') : '';

            $response_data = array(
                'slot_id' => $slot_id,
                'title' => isset($slot['title']) ? $slot['title'] : '',
                'slot_type' => isset($slot['slot_type']) ? $slot['slot_type'] : 'image',
                'image_display_mode' => isset($slot['image_display_mode']) ? $slot['image_display_mode'] : 'grid',
                'enabled' => isset($slot['enabled']) ? $slot['enabled'] : true,
                'units' => $units,
                'global_pause_purchase' => $global_pause_purchase,

                // 定价信息
                'pricing' => array(
                    'packages' => isset($slot['pricing_packages']) ? $slot['pricing_packages'] : array(),
                    'single_month' => isset($slot['pricing_single_month']) ? floatval($slot['pricing_single_month']) : 0,
                ),

                // 幻灯片价格差异配置
                'carousel_price_diff_enabled' => isset($slot['carousel_price_diff_enabled']) ? (bool) $slot['carousel_price_diff_enabled'] : false,
                'carousel_price_diff_type' => isset($slot['carousel_price_diff_type']) ? $slot['carousel_price_diff_type'] : 'decrement',
                'carousel_price_diff_amount' => isset($slot['carousel_price_diff_amount']) ? floatval($slot['carousel_price_diff_amount']) : 0,

                // 颜色选项（仅文字型广告）
                'color_options' => isset($slot['text_color_options']) ? $slot['text_color_options'] : array(),

                // 文字广告字数限制（前端实时校验使用）
                'text_length_range' => isset($slot['text_length_range']) && is_array($slot['text_length_range'])
                    ? array(
                        'min' => isset($slot['text_length_range']['min']) ? intval($slot['text_length_range']['min']) : 2,
                        'max' => isset($slot['text_length_range']['max']) ? intval($slot['text_length_range']['max']) : 50,
                    )
                    : array('min' => 2, 'max' => 50),

                // 图片长宽比（前端图片预览使用）
                'image_aspect_ratio' => isset($slot['image_aspect_ratio']) && is_array($slot['image_aspect_ratio'])
                    ? array(
                        'width' => isset($slot['image_aspect_ratio']['width']) ? intval($slot['image_aspect_ratio']['width']) : 8,
                        'height' => isset($slot['image_aspect_ratio']['height']) ? intval($slot['image_aspect_ratio']['height']) : 1,
                    )
                    : array('width' => 8, 'height' => 1),

                // 购买须知
                'purchase_notice' => !empty($slot['purchase_notice']) ? $slot['purchase_notice'] : $default_notice,
            );

            // ========================================
            // 第七步：返回成功响应
            // ========================================
            wp_send_json_success($response_data);

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * 上传广告图片到媒体库
     *
     * 安全性：
     * - 校验 nonce（沿用前端配置的 wp_rest）
     * - 校验文件类型与大小
     *
     * 响应：
     * - { success: true, data: { image_id, image_url } }
     */
    public function upload_image() {
        // 设置错误处理器捕获 Fatal Error
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
                if (function_exists('zibll_ad_log')) {
                    zibll_ad_log('Fatal error in upload_image', $error);
                }
                // 清除已有输出
                if (ob_get_length()) {
                    ob_clean();
                }
                wp_send_json_error(array(
                    'message' => '图片处理时发生致命错误：' . $error['message'],
                ));
            }
        });
        
        // 立即记录请求，便于调试
        if (function_exists('zibll_ad_log')) {
            zibll_ad_log('upload_image called', array(
                'has_file' => isset($_FILES['image']),
                'file_name' => isset($_FILES['image']['name']) ? $_FILES['image']['name'] : '',
                'file_type' => isset($_FILES['image']['type']) ? $_FILES['image']['type'] : '',
                'file_size' => isset($_FILES['image']['size']) ? $_FILES['image']['size'] : 0,
            ));
        }
        
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
                throw new Exception('安全验证失败');
            }

            // 业务规则：是否允许游客上传图片
            $allow_guest_upload = true;
            if (function_exists('zibll_ad_get_option')) {
                $allow_guest_upload = (bool) zibll_ad_get_option('allow_guest_image_upload', true);
            }
            if (!$allow_guest_upload && !is_user_logged_in()) {
                throw new Exception(__('已禁止游客上传图片，请登录后再上传', 'zibll-ad'));
            }

            if (!isset($_FILES['image'])) {
                throw new Exception('未接收到图片文件');
            }

            $file = $_FILES['image'];

            if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
                throw new Exception('无效的文件上传');
            }

            // 基础大小限制（尊重站点与插件设置）
            $max_upload_wp = wp_max_upload_size();
            $max_kb = function_exists('zibll_ad_get_option') ? intval(zibll_ad_get_option('image_max_size', 10240)) : 10240; // KB (10MB)
            $max_upload_plugin = $max_kb * 1024; // 字节
            $limit = min($max_upload_wp, $max_upload_plugin);

            if (!empty($file['size']) && $file['size'] > $limit) {
                throw new Exception(__('文件过大，超过允许的最大上传大小', 'zibll-ad'));
            }

            if (function_exists('zibll_ad_log')) {
                zibll_ad_log('Loading WordPress core files for image upload');
            }
            
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            
            if (function_exists('zibll_ad_log')) {
                zibll_ad_log('WordPress core files loaded successfully');
            }

            // 受插件设置控制的允许图片类型
            $allowed_types = function_exists('zibll_ad_get_option') ? zibll_ad_get_option('image_allowed_types', array('jpg','jpeg','png','gif','webp')) : array('jpg','jpeg','png','gif','webp');
            $allowed_types = is_array($allowed_types) ? array_map('strtolower', $allowed_types) : array();
            $mime_map = array(
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
            );
            
            // 提前验证文件类型，提供友好的错误消息
            $file_type = '';
            if (!empty($file['type'])) {
                $file_type = $file['type'];
            } elseif (!empty($file['tmp_name']) && function_exists('mime_content_type')) {
                $file_type = mime_content_type($file['tmp_name']);
            }
            
            $allowed_mimes = array();
            foreach ($allowed_types as $ext) {
                if (isset($mime_map[$ext])) {
                    $allowed_mimes[] = $mime_map[$ext];
                }
            }
            $allowed_mimes = array_unique($allowed_mimes);
            
            if ($file_type && !in_array($file_type, $allowed_mimes, true)) {
                $allowed_labels = array_map('strtoupper', $allowed_types);
                $allowed_text = implode('/', $allowed_labels);
                throw new Exception(sprintf('不支持的图片格式，仅允许上传 %s 格式的图片', $allowed_text));
            }
            
            $mimes = array();
            foreach ($allowed_types as $ext) {
                if (isset($mime_map[$ext])) {
                    if ($ext === 'jpg' || $ext === 'jpeg') {
                        $mimes['jpg|jpeg'] = 'image/jpeg';
                    } else {
                        $mimes[$ext] = $mime_map[$ext];
                    }
                }
            }
            if (empty($mimes)) {
                $mimes = array('jpg|jpeg' => 'image/jpeg', 'png' => 'image/png');
            }

            $overrides = array(
                'test_form' => false,
                'mimes' => $mimes,
            );

            // 处理上传到 uploads 目录
            if (function_exists('zibll_ad_log')) {
                zibll_ad_log('Calling wp_handle_upload', array(
                    'file_name' => $file['name'],
                    'file_type' => $file['type'],
                    'file_size' => $file['size'],
                ));
            }
            
            $movefile = wp_handle_upload($file, $overrides);
            
            if (function_exists('zibll_ad_log')) {
                zibll_ad_log('wp_handle_upload completed', array(
                    'success' => isset($movefile['file']),
                    'has_error' => isset($movefile['error']),
                    'error' => isset($movefile['error']) ? $movefile['error'] : null,
                ));
            }

            if (!isset($movefile['file']) || isset($movefile['error'])) {
                $err = isset($movefile['error']) ? $movefile['error'] : '上传失败';
                throw new Exception($err);
            }

            $file_path = $movefile['file'];
            $url = $movefile['url'];
            $type = $movefile['type'];

            // 在创建附件之前，按广告位设置或传入参数处理为指定宽高比（默认 8:1）
            // - 优先使用前端 POST 的 ratio_w/ratio_h（由当前广告位长宽比传入）
            // - 其次根据 slot_id 读取广告位的 image_aspect_ratio
            // - 最后回退为 8:1
            // WebP 特殊处理：服务器环境可能对 WebP 支持不完整，需要提前检测
            $skip_aspect_ratio = false;
            $is_webp = (stripos($type, 'webp') !== false);
            
            // 解析目标比例
            $ratio_w = isset($_POST['ratio_w']) ? intval($_POST['ratio_w']) : 0;
            $ratio_h = isset($_POST['ratio_h']) ? intval($_POST['ratio_h']) : 0;

            if ($ratio_w <= 0 || $ratio_h <= 0) {
                $slot_id_from_post = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
                if ($slot_id_from_post > 0 && class_exists('Zibll_Ad_Slot_Model')) {
                    $slot_for_ratio = Zibll_Ad_Slot_Model::get($slot_id_from_post);
                    if (is_array($slot_for_ratio) && isset($slot_for_ratio['image_aspect_ratio']) && is_array($slot_for_ratio['image_aspect_ratio'])) {
                        $rw = isset($slot_for_ratio['image_aspect_ratio']['width']) ? intval($slot_for_ratio['image_aspect_ratio']['width']) : 0;
                        $rh = isset($slot_for_ratio['image_aspect_ratio']['height']) ? intval($slot_for_ratio['image_aspect_ratio']['height']) : 0;
                        if ($rw > 0 && $rh > 0) {
                            $ratio_w = $rw;
                            $ratio_h = $rh;
                        }
                    }
                }
            }

            if ($ratio_w <= 0 || $ratio_h <= 0) {
                $ratio_w = 8;
                $ratio_h = 1;
            }

            // 全局开关：是否启用服务端比例强制处理（默认关闭，浏览器负责填充显示）
            $enable_server_aspect_enforce = function_exists('zibll_ad_get_option') ? (bool) zibll_ad_get_option('enable_server_aspect_enforce', false) : false;
            if (!$enable_server_aspect_enforce) {
                $skip_aspect_ratio = true;
            }

            if ($is_webp) {
                // 检测 GD 库 WebP 实际支持情况
                $gd_info = function_exists('gd_info') ? gd_info() : array();
                $has_webp_support = !empty($gd_info['WebP Support']);
                $has_create_func = function_exists('imagecreatefromwebp');
                $has_save_func = function_exists('imagewebp');
                
                if (!$has_webp_support || !$has_create_func || !$has_save_func) {
                    $skip_aspect_ratio = true;
                    if (function_exists('zibll_ad_log')) {
                        zibll_ad_log('Skipping aspect ratio processing for WebP - incomplete GD support', array(
                            'file' => basename($file_path),
                            'gd_webp_support' => $has_webp_support,
                            'has_imagecreatefromwebp' => $has_create_func,
                            'has_imagewebp' => $has_save_func,
                        ));
                    }
                } else {
                    // GD 库支持 WebP，但需要检测文件大小和可用内存
                    // imagecreatefromwebp 需要的内存约为 width * height * 4 字节
                    // 对于大文件，可能导致 "cannot allocate temporary buffer" 错误
                    $file_size = file_exists($file_path) ? filesize($file_path) : 0;
                    $memory_limit = ini_get('memory_limit');
                    $memory_limit_bytes = $this->parse_memory_limit($memory_limit);
                    $memory_used = memory_get_usage(true);
                    $memory_available = $memory_limit_bytes - $memory_used;
                    
                    // 预估 WebP 解压后需要的内存（保守估计：文件大小 * 10）
                    $estimated_memory = $file_size * 10;
                    
                    // 如果文件超过 500KB 或可用内存不足，跳过比例处理
                    if ($file_size > 512000 || $estimated_memory > $memory_available) {
                        $skip_aspect_ratio = true;
                        if (function_exists('zibll_ad_log')) {
                            zibll_ad_log('Skipping aspect ratio processing for WebP - file too large or insufficient memory', array(
                                'file' => basename($file_path),
                                'file_size' => $file_size,
                                'file_size_kb' => round($file_size / 1024, 2),
                                'memory_limit' => $memory_limit,
                                'memory_used_mb' => round($memory_used / 1024 / 1024, 2),
                                'memory_available_mb' => round($memory_available / 1024 / 1024, 2),
                                'estimated_needed_mb' => round($estimated_memory / 1024 / 1024, 2),
                            ));
                        }
                    }
                }
            }
            
            if (!$skip_aspect_ratio) {
                if (function_exists('zibll_ad_log')) {
                    zibll_ad_log('Starting aspect ratio processing', array(
                        'file_path' => basename($file_path),
                        'type' => $type,
                    ));
                }
                
                try {
                    $processed = $this->enforce_aspect_ratio_stretch($file_path, $type, $ratio_w, $ratio_h);
                    if ($processed && isset($processed['file']) && is_file($processed['file'])) {
                        // 如果处理成功并生成了新文件，则用新文件覆盖上传路径与 URL
                        $old_path = $file_path;
                        $file_path = $processed['file'];
                        $url = $processed['url'];
                        $type = $processed['type'];
                        // 清理原始上传文件，避免占用空间
                        if ($old_path !== $file_path && is_file($old_path)) {
                            @unlink($old_path);
                        }
                    }
                } catch (Exception $e) {
                    // 处理失败不影响上传流程，仅记录日志
                    if (function_exists('zibll_ad_log')) {
                        zibll_ad_log('enforce_aspect_ratio_stretch failed', array(
                            'error' => $e->getMessage(),
                            'file'  => basename($file_path),
                            'type'  => $type,
                        ));
                    }
                } catch (Error $e) {
                    // 捕获 PHP Fatal Error（如 GD 库函数调用失败）
                    if (function_exists('zibll_ad_log')) {
                        zibll_ad_log('enforce_aspect_ratio_stretch fatal error', array(
                            'error' => $e->getMessage(),
                            'file'  => basename($file_path),
                            'type'  => $type,
                        ));
                    }
                }
            }

            // 创建附件
            $attachment = array(
                'guid'           => $url,
                'post_mime_type' => $type,
                'post_title'     => sanitize_file_name(basename($file_path)),
                'post_content'   => '',
                'post_status'    => 'inherit',
            );

            $attach_id = wp_insert_attachment($attachment, $file_path);
            if (is_wp_error($attach_id) || !$attach_id) {
                throw new Exception('创建媒体附件失败');
            }

            // 生成并更新附件元数据（缩略图等）
            // WebP 特殊处理：如果 GD 库 WebP 支持不完整，跳过缩略图生成以避免 Fatal Error
            $skip_thumbnails = false;
            if (stripos($type, 'webp') !== false) {
                $gd_info = function_exists('gd_info') ? gd_info() : array();
                $webp_support = !empty($gd_info['WebP Support']);
                if (!$webp_support || !function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
                    $skip_thumbnails = true;
                    if (function_exists('zibll_ad_log')) {
                        zibll_ad_log('Skipping thumbnail generation for WebP - GD library WebP support incomplete', array(
                            'file' => basename($file_path),
                            'gd_webp_support' => $webp_support,
                            'has_imagecreatefromwebp' => function_exists('imagecreatefromwebp'),
                            'has_imagewebp' => function_exists('imagewebp'),
                        ));
                    }
                }
            }
            
            if (!$skip_thumbnails) {
                $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
                if ($attach_data) {
                    wp_update_attachment_metadata($attach_id, $attach_data);
                }
            } else {
                // 手动设置基本元数据（不生成缩略图）
                $image_meta = array(
                    'width'  => 0,
                    'height' => 0,
                    'file'   => _wp_relative_upload_path($file_path),
                );
                $size = @getimagesize($file_path);
                if ($size && isset($size[0], $size[1])) {
                    $image_meta['width'] = $size[0];
                    $image_meta['height'] = $size[1];
                }
                wp_update_attachment_metadata($attach_id, $image_meta);
            }

            $attachment_url = wp_get_attachment_url($attach_id);

            wp_send_json_success(array(
                'image_id'  => intval($attach_id),
                'image_url' => esc_url_raw($attachment_url ?: $url),
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * 将上传图片强制处理为指定宽高比（默认 8:1），不裁剪，仅缩放并补边（信箱/信封式）
     *
     * - 优先不放大：仅在原图尺寸大于画布时缩小以适配
     * - 画布宽度上限：1600px（可通过过滤器调整）
     * - PNG/WEBP 尽量保留透明背景；JPEG 使用白色背景
     * - GIF（可能为动图）为避免丢帧，默认跳过处理
     *
     * @param string $file_path 已上传到 uploads 目录的文件完整路径
     * @param string $mime      文件 MIME 类型
     * @param int    $ratio_w   目标比例宽（默认 8）
     * @param int    $ratio_h   目标比例高（默认 1）
     * @return array|false      成功返回 [file, url, type]；失败返回 false
     */
    private function enforce_aspect_ratio_stretch($file_path, $mime, $ratio_w = 8, $ratio_h = 1) {
        // 跳过 GIF，避免破坏动图
        if (stripos($mime, 'gif') !== false) {
            return false;
        }
        
        // 检查 GD 库是否可用
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        // 读取原图尺寸
        $size = @getimagesize($file_path);
        if (!$size || !isset($size[0], $size[1])) {
            return false;
        }
        $orig_w = (int) $size[0];
        $orig_h = (int) $size[1];

        if ($orig_w <= 0 || $orig_h <= 0) {
            return false;
        }

        // 计算画布尺寸（尽量不超过原图宽度，设置上限）
        $max_canvas_w = apply_filters('zibll_ad_image_canvas_max_width', 1600);
        $canvas_w = min($orig_w, (int) $max_canvas_w);
        $canvas_w = max(8, (int) $canvas_w);
        $canvas_h = max(1, (int) round($canvas_w * $ratio_h / $ratio_w));

        // 直接拉伸到目标比例尺寸（允许变形），不裁剪
        $new_w = $canvas_w;
        $new_h = $canvas_h;
        $dst_x = 0;
        $dst_y = 0;

        // 构建画布
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $is_png  = (stripos($mime, 'png')  !== false) || ($ext === 'png');
        $is_webp = (stripos($mime, 'webp') !== false) || ($ext === 'webp');
        $is_jpg  = (stripos($mime, 'jpeg') !== false) || ($ext === 'jpg') || ($ext === 'jpeg');
        
        // WebP 额外检查：确保 GD 库真正支持 WebP
        if ($is_webp) {
            $gd_info = function_exists('gd_info') ? gd_info() : array();
            $webp_support = !empty($gd_info['WebP Support']) || (function_exists('imagecreatefromwebp') && function_exists('imagewebp'));
            if (!$webp_support) {
                // GD 库不支持 WebP，跳过处理（直接使用原图）
                if (function_exists('zibll_ad_log')) {
                    zibll_ad_log('WebP processing skipped - GD library does not support WebP', array(
                        'gd_info' => $gd_info,
                        'file' => basename($file_path),
                    ));
                }
                return false;
            }
        }

        $dst = imagecreatetruecolor($canvas_w, $canvas_h);
        if (!$dst) {
            return false;
        }

        if ($is_png || $is_webp) {
            // 透明背景
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefill($dst, 0, 0, $transparent);
        } else {
            // JPEG 等使用白色背景
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);
        }

        // 读取源图
        $src = false;
        if ($is_jpg && function_exists('imagecreatefromjpeg')) {
            $src = @imagecreatefromjpeg($file_path);
        } elseif ($is_png && function_exists('imagecreatefrompng')) {
            $src = @imagecreatefrompng($file_path);
        } elseif ($is_webp && function_exists('imagecreatefromwebp')) {
            // WebP 读取可能失败，使用错误抑制并记录日志
            // 在某些服务器环境中，即使函数存在也可能因为 libwebp 版本问题导致崩溃
            set_error_handler(function($errno, $errstr, $errfile, $errline) {
                // 捕获所有错误，防止 Fatal Error
                if (function_exists('zibll_ad_log')) {
                    zibll_ad_log('imagecreatefromwebp error caught', array(
                        'errno' => $errno,
                        'errstr' => $errstr,
                        'errfile' => basename($errfile),
                        'errline' => $errline,
                    ));
                }
                return true; // 阻止默认错误处理
            });
            
            $src = @imagecreatefromwebp($file_path);
            
            restore_error_handler();
            
            if (!$src) {
                if (function_exists('zibll_ad_log')) {
                    zibll_ad_log('imagecreatefromwebp() failed to load WebP file', array(
                        'file' => basename($file_path),
                        'file_exists' => file_exists($file_path),
                        'file_size' => file_exists($file_path) ? filesize($file_path) : 0,
                        'last_error' => error_get_last(),
                    ));
                }
                imagedestroy($dst);
                return false;
            }
        } else {
            // 其他类型暂不处理
            imagedestroy($dst);
            return false;
        }
        if (!$src) {
            imagedestroy($dst);
            return false;
        }

        // 双三次（GD 内部优化），将源图拉伸至目标尺寸
        imagecopyresampled($dst, $src, $dst_x, $dst_y, 0, 0, $new_w, $new_h, $orig_w, $orig_h);

        // 输出到新文件（避免覆盖源，便于回退）
        $upload_dir = wp_get_upload_dir();
        $suffix = '-zibllad-8x1';
        $new_basename = preg_replace('/(\.[^.]+)$/', $suffix . '$1', basename($file_path));
        if ($new_basename === basename($file_path)) {
            $new_basename = basename($file_path, '.' . $ext) . $suffix . '.' . $ext;
        }
        $new_path = trailingslashit($upload_dir['path']) . $new_basename;

        $saved = false;
        $new_mime = $mime;
        if ($is_jpg && function_exists('imagejpeg')) {
            $saved = @imagejpeg($dst, $new_path, 85);
            $new_mime = 'image/jpeg';
        } elseif ($is_png && function_exists('imagepng')) {
            $saved = @imagepng($dst, $new_path, 6);
            $new_mime = 'image/png';
        } elseif ($is_webp && function_exists('imagewebp')) {
            // 使用错误抑制符防止 GD 库内部错误导致 Fatal Error
            // 在某些服务器环境中，保存 WebP 可能因为内存或 libwebp 版本问题失败
            set_error_handler(function($errno, $errstr, $errfile, $errline) {
                if (function_exists('zibll_ad_log')) {
                    zibll_ad_log('imagewebp error caught', array(
                        'errno' => $errno,
                        'errstr' => $errstr,
                        'errfile' => basename($errfile),
                        'errline' => $errline,
                    ));
                }
                return true;
            });
            
            $saved = @imagewebp($dst, $new_path, 80);
            $new_mime = 'image/webp';
            
            restore_error_handler();
            
            // 如果保存失败，记录详细错误信息
            if (!$saved && function_exists('zibll_ad_log')) {
                zibll_ad_log('imagewebp() failed to save WebP file', array(
                    'file' => basename($new_path),
                    'width' => $canvas_w,
                    'height' => $canvas_h,
                    'last_error' => error_get_last(),
                ));
            }
        }

        imagedestroy($src);
        imagedestroy($dst);

        if (!$saved || !is_file($new_path)) {
            return false;
        }

        // 构建新 URL（使用同一 uploads 子目录）
        $relative_dir = ltrim(str_replace($upload_dir['basedir'], '', dirname($new_path)), '/\\');
        $new_url = trailingslashit($upload_dir['baseurl']) . ($relative_dir ? trailingslashit($relative_dir) : '') . basename($new_path);

        return array(
            'file' => $new_path,
            'url'  => $new_url,
            'type' => $new_mime,
        );
    }

    /**
     * ============================================================================
     * 获取支付方式表单 - 生产级实现（参照开发方案.txt 步骤 7.2）
     * ============================================================================
     *
     * 【核心职责】
     * 1. 根据广告位配置和购买参数计算价格
     * 2. 调用主题 zibpay_get_initiate_pay_input() 生成支付方式选择HTML
     * 3. 支持支付方式过滤（如未登录用户禁用余额支付）
     * 4. 返回完整的支付UI HTML和价格信息
     *
     * 【与主题集成】
     * - 复用主题函数：zibpay_get_payment_methods(31)
     * - 复用主题函数：zibpay_get_initiate_pay_input(31, $price, 0, false)
     * - 生成的HTML包含：
     *   . 支付方式单选按钮（微信/支付宝/PayPal/余额/卡密）
     *   . 余额显示框（data-controller 条件显示）
     *   . 卡密输入框（data-controller 条件显示）
     *   . 隐藏字段 payment_method
     *   . "立即支付"按钮（.initiate-pay）
     *
     * 【前端集成】
     * - 前端在价格变化时调用此接口
     * - 前端将返回的HTML插入到模态框中
     * - 主题的 dependency.js 会自动处理 data-controller 逻辑
     * - 主题的 pay.js 会处理 .initiate-pay 点击事件
     *
     * @return void (输出JSON)
     */
    public function get_payment_form() {
        try {
            // ============================================================================
            // 第一步：安全验证
            // ============================================================================
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
                throw new Exception('安全验证失败');
            }

            // ============================================================================
            // 第二步：接收参数
            // ============================================================================
            $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
            $unit_key = isset($_POST['unit_key']) ? intval($_POST['unit_key']) : 0;
            $plan_type = isset($_POST['plan_type']) ? sanitize_text_field($_POST['plan_type']) : 'custom';
            $duration_months = isset($_POST['duration_months']) ? intval($_POST['duration_months']) : 1;
            $color_key = isset($_POST['color_key']) ? sanitize_text_field($_POST['color_key']) : '';

            zibll_ad_log('get_payment_form: 收到请求', array(
                'slot_id' => $slot_id,
                'unit_key' => $unit_key,
                'plan_type' => $plan_type,
                'duration_months' => $duration_months,
                'color_key' => $color_key,
                'is_logged_in' => is_user_logged_in(),
            ));

            // 业务规则：如不允许游客购买，则未登录用户禁止继续
            $allow_guest = function_exists('zibll_ad_get_option') ? (bool) zibll_ad_get_option('allow_guest_purchase', true) : true;
            if (!$allow_guest && !is_user_logged_in()) {
                throw new Exception(__('请先登录后再购买广告', 'zibll-ad'));
            }

            // 业务规则：检查全局暂停投放
            $settings = class_exists('Zibll_Ad_Settings') ? Zibll_Ad_Settings::get_all() : array();
            $global_pause_purchase = isset($settings['global_pause_purchase']) ? (bool) $settings['global_pause_purchase'] : false;
            if ($global_pause_purchase) {
                throw new Exception(__('暂时停止广告位购买，请稍后再试', 'zibll-ad'));
            }

            // ============================================================================
            // 第三步：参数校验
            // ============================================================================
            if (!$slot_id || $slot_id <= 0) {
                throw new Exception('广告位ID无效');
            }

            if ($duration_months < 1 || $duration_months > 120) {
                throw new Exception('购买时长必须在1-120个月之间');
            }

            // ============================================================================
            // 第四步：获取广告位配置
            // ============================================================================
            $slot = Zibll_Ad_Slot_Model::get($slot_id);

            if (!$slot) {
                throw new Exception('广告位不存在');
            }

            // 检查是否启用
            if (isset($slot['enabled']) && !$slot['enabled']) {
                throw new Exception('此广告位已禁用');
            }

            // ============================================================================
            // 第五步：计算价格
            // ============================================================================
            // 使用与 prepare_order 相同的价格计算逻辑，确保一致性
            // 传入 unit_key 以支持幻灯片价格差异
            $price_data = $this->calculate_price($slot, $plan_type, $duration_months, $color_key, $unit_key);

            $total_price = $price_data['total_price'];

            zibll_ad_log('get_payment_form: 价格计算完成', array(
                'base_price' => $price_data['base_price'],
                'color_price' => $price_data['color_price'],
                'total_price' => $total_price,
            ));

            // ============================================================================
            // 第六步：支付方式过滤（可选）
            // ============================================================================
            // 挂载临时过滤器，针对广告订单（order_type=31）进行支付方式限制
            add_filter('zibpay_payment_methods', array($this, 'filter_payment_methods_for_ad'), 10, 2);

            // ============================================================================
            // 第七步：生成支付方式HTML
            // ============================================================================
            // 检查主题函数是否存在
            if (!function_exists('zibpay_get_initiate_pay_input')) {
                zibll_ad_log('get_payment_form: 主题函数不存在', array(
                    'function' => 'zibpay_get_initiate_pay_input',
                ));
                throw new Exception('主题支付函数未加载，请确保已激活子比主题');
            }

            // 调用主题函数生成支付UI
            // 参数说明：
            // - $pay_type = 31: 广告订单类型
            // - $pay_price = $total_price: 订单总价
            // - $post_id = 0: 不关联特定文章
            // - $is_initiate_pay = false: 这是下单阶段（非支付阶段）
            // - $text = '立即支付': 按钮文本
            $payment_html = zibpay_get_initiate_pay_input(
                31,                 // order_type: 广告订单
                $total_price,       // 订单价格
                0,                  // post_id（不关联文章）
                false,              // is_initiate_pay（下单阶段）
                '立即支付'          // 按钮文本
            );

            // 移除临时过滤器
            remove_filter('zibpay_payment_methods', array($this, 'filter_payment_methods_for_ad'), 10);

            // ============================================================================
            // 第八步：HTML后处理（可选）
            // ============================================================================
            // 如果需要对主题生成的HTML进行调整，可以在这里处理
            // 例如：移除积分抵扣、优惠码等不适用于广告购买的功能

            // 移除优惠码输入框（广告购买通常不支持优惠码）
            $payment_html = preg_replace('/<div class="mb10 coupon-input-box".*?<\/div>\s*<\/div>/s', '', $payment_html);

            // 移除积分抵扣（如果主题启用了积分系统）
            $payment_html = preg_replace('/<label class="flex jsb ac mb10 muted-box padding-h10">.*?积分抵扣.*?<\/label>/s', '', $payment_html);

            zibll_ad_log('get_payment_form: 支付HTML生成成功', array(
                'html_length' => strlen($payment_html),
            ));

            // ============================================================================
            // 第九步：返回响应
            // ============================================================================
            wp_send_json_success(array(
                'html' => $payment_html,
                'total_price' => $total_price,
                'base_price' => $price_data['base_price'],
                'color_price' => $price_data['color_price'],
                'formatted_price' => zibll_ad_format_price($total_price),
                'duration_months' => $duration_months,
            ));

        } catch (Exception $e) {
            zibll_ad_log('get_payment_form: 请求失败', array(
                'error' => $e->getMessage(),
            ));

            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * 过滤支付方式（用于广告订单）
     *
     * 【业务规则】
     * 1. 未登录用户禁用余额支付（因为没有余额账户）
     * 2. 可根据 slot 配置的 payment_methods_override 限制支付方式
     *
     * @param array $methods   可用支付方式数组
     * @param int   $pay_type  订单类型
     * @return array 过滤后的支付方式
     */
    public function filter_payment_methods_for_ad($methods, $pay_type) {
        // 只处理广告订单（order_type=31）
        if (intval($pay_type) !== 31) {
            return $methods;
        }

        zibll_ad_log('filter_payment_methods_for_ad: 开始过滤', array(
            'original_methods' => array_keys($methods),
            'is_logged_in' => is_user_logged_in(),
        ));

        // 规则1：未登录用户禁用余额支付
        if (!is_user_logged_in() && isset($methods['balance'])) {
            unset($methods['balance']);
            zibll_ad_log('filter_payment_methods_for_ad: 移除余额支付（用户未登录）');
        }

        // 规则1.1：全局设置禁用余额支付
        if (function_exists('zibll_ad_get_option')) {
            $allow_balance = (bool) zibll_ad_get_option('allow_balance_payment', true);
            if (!$allow_balance && isset($methods['balance'])) {
                unset($methods['balance']);
                zibll_ad_log('filter_payment_methods_for_ad: 移除余额支付（设置禁用）');
            }
        }

        // 规则2：根据 slot 配置限制支付方式（可选）
        // 如果在 $_POST 中传递了 slot_id，可以读取配置
        if (!empty($_POST['slot_id'])) {
            $slot_id = intval($_POST['slot_id']);
            $slot = Zibll_Ad_Slot_Model::get($slot_id);

            if ($slot && isset($slot['payment_methods_override']) && is_array($slot['payment_methods_override'])) {
                $allowed_methods = $slot['payment_methods_override'];

                // 如果配置了白名单，只保留白名单中的方式
                if (!empty($allowed_methods)) {
                    $methods = array_intersect_key($methods, array_flip($allowed_methods));

                    zibll_ad_log('filter_payment_methods_for_ad: 应用白名单过滤', array(
                        'allowed_methods' => $allowed_methods,
                        'filtered_methods' => array_keys($methods),
                    ));
                }
            }
        }

        zibll_ad_log('filter_payment_methods_for_ad: 过滤完成', array(
            'filtered_methods' => array_keys($methods),
        ));

        return $methods;
    }

    /**
     * ============================================================================
     * 准备订单 - 生产级实现
     * ============================================================================
     *
     * 【核心职责】
     * 1. 接收前端购买请求，校验所有输入数据
     * 2. 锁定广告单元，防止并发购买冲突
     * 3. 计算实际价格，防止前端篡改
     * 4. 生成安全的订单凭证，对接ZibPay支付系统
     *
     * 【关键安全措施】
     * 1. Nonce验证 - 防止CSRF攻击
     * 2. 数据过滤 - 防止XSS/SQL注入
     * 3. 并发控制 - 使用数据库事务和行锁
     * 4. 数据签名 - transient数据防篡改
     * 5. 完整日志 - 便于审计和调试
     *
     * 【并发场景处理】
     * 场景：多个用户同时购买同一个广告位
     * 解决：数据库行级锁 + 状态机检查 + 原子性更新
     *
     * 【错误恢复机制】
     * 1. 任何步骤失败都会抛出异常
     * 2. 异常会被统一捕获并返回友好错误信息
     * 3. 关键操作失败会记录详细日志
     * 4. pending状态有超时自动释放机制
     *
     * @return void (输出JSON响应)
     * @throws Exception 各类验证失败或业务逻辑错误
     */
    public function prepare_order() {
        global $wpdb;

        // 初始化响应变量（用于日志记录）
        $request_id = uniqid('req_', true); // 生成唯一请求ID，用于追踪整个流程
        $start_time = microtime(true);

        try {
            // ============================================================================
            // 第一步：安全验证 (Security Check)
            // ============================================================================
            // 【深度思考】为什么要先验证nonce？
            // 1. nonce是WordPress防CSRF的核心机制
            // 2. 如果nonce无效，说明请求来源可疑，立即拒绝，避免浪费资源
            // 3. nonce验证失败不需要记录详细日志，避免日志膨胀

            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
                zibll_ad_log('prepare_order: nonce验证失败', array(
                    'request_id' => $request_id,
                    'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown',
                    'user_id' => get_current_user_id(),
                ));
                throw new Exception('安全验证失败，请刷新页面后重试');
            }

            // ============================================================================
            // 第二步：接收和过滤数据 (Input Sanitization)
            // ============================================================================
            // 【深度思考】数据过滤的层次
            // 1. intval/sanitize_text_field - 类型转换和基础过滤
            // 2. 后续会进行业务逻辑校验（范围、格式、存在性等）
            // 3. 数据库写入时使用prepared statement
            // 4. 输出时使用esc_*函数

            // 业务规则：如不允许游客购买，则未登录用户禁止继续
            $allow_guest = function_exists('zibll_ad_get_option') ? (bool) zibll_ad_get_option('allow_guest_purchase', true) : true;
            if (!$allow_guest && !is_user_logged_in()) {
                throw new Exception(__('请先登录后再购买广告', 'zibll-ad'));
            }

            // 订单基础信息
            $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
            $unit_key = isset($_POST['unit_key']) ? intval($_POST['unit_key']) : 0;
            $plan_type = isset($_POST['plan_type']) ? sanitize_text_field($_POST['plan_type']) : '';
            $duration_months = isset($_POST['duration_months']) ? intval($_POST['duration_months']) : 0;

            // 用户联系信息
            $website_name = isset($_POST['website_name']) ? sanitize_text_field($_POST['website_name']) : '';
            $website_url = isset($_POST['website_url']) ? esc_url_raw($_POST['website_url']) : '';
            $contact_type = isset($_POST['contact_type']) ? sanitize_text_field($_POST['contact_type']) : '';
            $contact_value = isset($_POST['contact_value']) ? sanitize_text_field($_POST['contact_value']) : '';
            $target_url = isset($_POST['target_url']) ? esc_url_raw($_POST['target_url']) : '';

            // 广告内容
            $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
            $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
            $text_content = isset($_POST['text_content']) ? sanitize_textarea_field($_POST['text_content']) : '';
            $color_key = isset($_POST['color_key']) ? sanitize_text_field($_POST['color_key']) : '';

            // 记录请求日志（生产环境）
            zibll_ad_log('prepare_order: 收到订单请求', array(
                'request_id' => $request_id,
                'slot_id' => $slot_id,
                'unit_key' => $unit_key,
                'plan_type' => $plan_type,
                'duration_months' => $duration_months,
                'user_id' => get_current_user_id(),
                'is_logged_in' => is_user_logged_in(),
            ));

            // ============================================================================
            // 第三步：基础参数校验 (Basic Validation)
            // ============================================================================
            // 【深度思考】为什么要分层校验？
            // 1. 快速失败原则 - 先校验基础参数，避免不必要的数据库查询
            // 2. 友好错误提示 - 精确定位参数问题
            // 3. 性能优化 - 减少无效请求的资源消耗

            // 业务规则：如不允许游客购买，则未登录用户禁止继续
            $allow_guest = function_exists('zibll_ad_get_option') ? (bool) zibll_ad_get_option('allow_guest_purchase', true) : true;
            if (!$allow_guest && !is_user_logged_in()) {
                throw new Exception(__('请先登录后再购买广告', 'zibll-ad'));
            }

            // 业务规则：检查全局暂停投放
            $settings = class_exists('Zibll_Ad_Settings') ? Zibll_Ad_Settings::get_all() : array();
            $global_pause_purchase = isset($settings['global_pause_purchase']) ? (bool) $settings['global_pause_purchase'] : false;
            if ($global_pause_purchase) {
                throw new Exception(__('暂时停止广告位购买，请稍后再试', 'zibll-ad'));
            }

            $validation_errors = array();

            if (!$slot_id || $slot_id <= 0) {
                $validation_errors[] = '广告位ID无效';
            }

            if ($unit_key < 0) {
                $validation_errors[] = '广告单元位置无效';
            }

            if (!in_array($plan_type, array('package', 'custom'), true)) {
                $validation_errors[] = '购买类型无效（必须是package或custom）';
            }

            if ($duration_months < 1) {
                $validation_errors[] = '购买时长至少为1个月';
            }

            if ($duration_months > 120) {
                $validation_errors[] = '购买时长最多为120个月（10年）';
            }

            if (!empty($validation_errors)) {
                throw new Exception('参数校验失败：' . implode('；', $validation_errors));
            }

            // ============================================================================
            // 第四步：获取广告位配置 (Load Slot Configuration)
            // ============================================================================
            // 【深度思考】为什么要在锁定单元前先读取配置？
            // 1. 如果广告位不存在或已禁用，立即返回错误，避免锁定资源
            // 2. 后续价格计算需要用到配置信息
            // 3. 可以校验颜色选项、定价套餐是否有效

            $slot = Zibll_Ad_Slot_Model::get($slot_id);

            if (!$slot) {
                zibll_ad_log('prepare_order: 广告位不存在', array(
                    'request_id' => $request_id,
                    'slot_id' => $slot_id,
                ));
                throw new Exception('广告位不存在或已被删除');
            }

            // 检查是否启用
            if (isset($slot['enabled']) && !$slot['enabled']) {
                zibll_ad_log('prepare_order: 广告位已禁用', array(
                    'request_id' => $request_id,
                    'slot_id' => $slot_id,
                ));
                throw new Exception('此广告位已禁用，暂不接受购买');
            }

            // 获取广告位类型
            $slot_type = isset($slot['slot_type']) ? $slot['slot_type'] : 'image';

            // ============================================================================
            // 第五步：深度校验用户输入 (Deep Input Validation)
            // ============================================================================
            // 【深度思考】为什么要在获取配置后再校验内容？
            // 1. 可以根据广告位类型（图片/文字）进行针对性校验
            // 2. 可以校验颜色选项是否在配置的范围内
            // 3. 可以根据配置的文字长度限制进行校验

            // 网站名称校验
            $website_name = trim($website_name);
            if (empty($website_name)) {
                $validation_errors[] = '网站名称不能为空';
            } elseif (mb_strlen($website_name) < 2) {
                $validation_errors[] = '网站名称至少2个字符';
            } elseif (mb_strlen($website_name) > 50) {
                $validation_errors[] = '网站名称最多50个字符';
            }

            // 网站URL校验（可选字段，如果填写则必须有效）
            if (!empty($website_url)) {
                if (!$this->validate_url($website_url)) {
                    $validation_errors[] = '网站地址格式不正确（如 http://example.com 或 https://example.com）';
                }
                // 额外验证：域名格式
                $parsed_url = parse_url($website_url);
                if (!isset($parsed_url['host']) || empty($parsed_url['host'])) {
                    $validation_errors[] = '网站地址格式不正确';
                }
            }

            // 联系方式校验
            if (!$this->validate_contact($contact_type, $contact_value)) {
                switch ($contact_type) {
                    case 'qq':
                        $validation_errors[] = 'QQ号格式不正确（必须是5位以上的数字，不能以0开头）';
                        break;
                    case 'wechat':
                        $validation_errors[] = '微信号格式不正确（必须以字母开头，6-20位字母数字下划线）';
                        break;
                    case 'email':
                        $validation_errors[] = '邮箱格式不正确';
                        break;
                    default:
                        $validation_errors[] = '联系方式类型无效（必须是qq/wechat/email）';
                }
            }

            // 目标URL校验
            if (!$this->validate_url($target_url)) {
                $validation_errors[] = '广告链接格式不正确（如 http://example.com/page 或 https://example.com/page）';
            }

            // 广告内容校验（根据类型）
            if ($slot_type === 'image') {
                // 图片型广告
                if (empty($image_url)) {
                    $validation_errors[] = '请上传广告图片';
                } else {
                    // 验证图片URL格式
                    if (!$this->validate_url($image_url)) {
                        $validation_errors[] = '图片地址格式不正确';
                    }

                    // 验证图片ID和URL的一致性（防止篡改）
                    if ($image_id > 0) {
                        $attachment_url = wp_get_attachment_url($image_id);
                        if ($attachment_url && $attachment_url !== $image_url) {
                            zibll_ad_log('prepare_order: 图片ID和URL不匹配', array(
                                'request_id' => $request_id,
                                'image_id' => $image_id,
                                'expected_url' => $attachment_url,
                                'received_url' => $image_url,
                            ));
                            $validation_errors[] = '图片数据不一致，请重新上传';
                        }
                    }
                }
            } else {
                // 文字型广告
                $text_content = trim($text_content);

                if (empty($text_content)) {
                    $validation_errors[] = '广告文字不能为空';
                } else {
                    // 获取文字长度限制配置
                    $text_length_range = isset($slot['text_length_range']) ? $slot['text_length_range'] : array('min' => 2, 'max' => 100);
                    $min_length = isset($text_length_range['min']) ? intval($text_length_range['min']) : 2;
                    $max_length = isset($text_length_range['max']) ? intval($text_length_range['max']) : 100;

                    $text_length = mb_strlen($text_content);

                    if ($text_length < $min_length) {
                        $validation_errors[] = "广告文字至少{$min_length}个字符";
                    } elseif ($text_length > $max_length) {
                        $validation_errors[] = "广告文字最多{$max_length}个字符";
                    }
                }

                // 验证颜色选项（如果指定了颜色）
                // 【深度修复】需要区分"未选择"和"选择了无效值"
                // 约定：空字符串 '' 或 'default' 表示使用默认颜色（不收费）
                // 其他值必须在配置的颜色选项列表中

                // 记录颜色相关数据用于调试
                zibll_ad_log('prepare_order: 颜色选项校验', array(
                    'request_id' => $request_id,
                    'slot_type' => $slot_type,
                    'color_key_raw' => $color_key,
                    'color_key_type' => gettype($color_key),
                    'color_key_empty' => empty($color_key),
                    'color_key_length' => strlen($color_key),
                    'text_color_options' => isset($slot['text_color_options']) ? $slot['text_color_options'] : 'NOT_SET',
                ));

                // 定义默认颜色的识别值
                $is_default_color = ($color_key === '' || $color_key === 'default' || is_null($color_key));

                if (!$is_default_color) {
                    // 用户选择了特定颜色，需要验证是否在允许范围内
                    $color_options = isset($slot['text_color_options']) ? $slot['text_color_options'] : array();

                    // 只有在配置了颜色选项时才验证
                    if (is_array($color_options) && !empty($color_options)) {
                        $valid_color = false;
                        $available_color_keys = array(); // 用于错误提示

                        foreach ($color_options as $option) {
                            if (isset($option['key'])) {
                                $available_color_keys[] = $option['key'];
                                if ($option['key'] === $color_key) {
                                    $valid_color = true;
                                    break;
                                }
                            }
                        }

                        if (!$valid_color) {
                            zibll_ad_log('prepare_order: 颜色选项验证失败', array(
                                'request_id' => $request_id,
                                'received_color_key' => $color_key,
                                'available_color_keys' => $available_color_keys,
                            ));
                            $validation_errors[] = '选择的颜色选项无效（可用选项：' . implode(', ', $available_color_keys) . '）';
                        }
                    } else {
                        // 没有配置颜色选项，但用户传了非默认值，记录警告但允许通过
                        zibll_ad_log('prepare_order: 颜色选项未配置但用户传了值', array(
                            'request_id' => $request_id,
                            'color_key' => $color_key,
                        ));
                    }
                } else {
                    // 使用默认颜色，不验证，不收费
                    zibll_ad_log('prepare_order: 使用默认颜色', array(
                        'request_id' => $request_id,
                        'color_key_raw' => $color_key,
                    ));
                }
            }

            // 如果有校验错误，抛出异常
            if (!empty($validation_errors)) {
                zibll_ad_log('prepare_order: 数据校验失败', array(
                    'request_id' => $request_id,
                    'errors' => $validation_errors,
                ));
                throw new Exception('数据校验失败：' . implode('；', $validation_errors));
            }

            // ============================================================================
            // 第六步：并发控制 - 检查并锁定单元 (Concurrency Control)
            // ============================================================================
            // 【深度思考】如何处理并发购买？
            //
            // 问题场景：
            // - 用户A和用户B同时点击购买同一个广告位的同一个位置
            // - 两个请求几乎同时到达服务器
            // - 如果不加控制，可能导致：
            //   1. 两个人都看到"available"状态
            //   2. 两个人都成功创建pending订单
            //   3. 最后支付的人会覆盖前面的订单，导致纠纷
            //
            // 解决方案：
            // 1. 使用MySQL行级锁（SELECT ... FOR UPDATE）
            // 2. 在事务中完成"读取-检查-更新"的原子操作
            // 3. 第一个请求会锁定该行，第二个请求必须等待
            // 4. 等第一个事务提交后，第二个请求会看到"pending"状态，返回错误
            //
            // 为什么不用Redis/Memcached锁？
            // 1. 增加系统复杂度和依赖
            // 2. MySQL行锁已经足够高效
            // 3. 广告位购买不是高频操作
            // 4. 事务可以保证数据一致性

            // 开启数据库事务
            $wpdb->query('START TRANSACTION');

            try {
                $table_units = $wpdb->prefix . 'zibll_ad_units';

                // 使用 FOR UPDATE 锁定该行，防止并发修改
                // 【关键】这一行会阻塞其他正在尝试锁定同一行的请求
                $unit = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $table_units
                    WHERE slot_id = %d AND unit_key = %d
                    FOR UPDATE",
                    $slot_id,
                    $unit_key
                ), ARRAY_A);

                $current_time = time();

                if ($unit) {
                    // 单元已存在，检查状态
                    zibll_ad_log('prepare_order: 单元已存在，检查状态', array(
                        'request_id' => $request_id,
                        'unit_id' => $unit['id'],
                        'status' => $unit['status'],
                        'pending_expires_at' => isset($unit['pending_expires_at']) ? $unit['pending_expires_at'] : null,
                        'ends_at' => isset($unit['ends_at']) ? $unit['ends_at'] : null,
                    ));

                    // 状态机检查
                    if ($unit['status'] === 'paid') {
                        // 已被购买，检查是否已过期
                        $ends_at = isset($unit['ends_at']) ? intval($unit['ends_at']) : 0;

                        if ($ends_at > $current_time) {
                            // 未过期，不能购买
                            throw new Exception('此位置已被占用，到期时间：' . date('Y-m-d H:i:s', $ends_at));
                        }

                        // 已过期：直接将状态置为 expired，继续购买流程（无需等待 cron）
                        $auto_expire_res = $wpdb->update(
                            $table_units,
                            array(
                                'status' => 'expired',
                                'order_id' => null,
                                'order_num' => null,
                                'pending_expires_at' => null,
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => $unit['id']),
                            null, // 让 WP 自动推断字段类型，正确写入 NULL
                            array('%d')
                        );

                        if ($auto_expire_res === false) {
                            throw new Exception('系统繁忙，请稍后再试');
                        }

                        // 同步内存中的状态，后续逻辑将把它锁为 pending
                        $unit['status'] = 'expired';

                        zibll_ad_log('prepare_order: 自动将过期paid单元置为expired并清理订单字段', array(
                            'request_id' => $request_id,
                            'unit_id' => $unit['id'],
                            'clear_order_fields' => true,
                        ));
                    }

                    if ($unit['status'] === 'pending') {
                        // 正在pending，检查是否超时
                        $pending_expires_at = isset($unit['pending_expires_at']) ? intval($unit['pending_expires_at']) : 0;

                        if ($pending_expires_at > $current_time) {
                            // 未超时，不能购买
                            $wait_seconds = $pending_expires_at - $current_time;
                            $wait_minutes = ceil($wait_seconds / 60);

                            throw new Exception("此位置正在被他人购买，请等待约{$wait_minutes}分钟后再试");
                        }

                        // 已超时，可以重新锁定（继续执行）
                        zibll_ad_log('prepare_order: pending已超时，可以重新锁定', array(
                            'request_id' => $request_id,
                            'unit_id' => $unit['id'],
                        ));
                    }

                    // status === 'available' 或 'expired' 或 超时的 'pending'，可以购买
                } else {
                    // 单元不存在，需要创建
                    // 【注意】这种情况应该很少发生，因为创建slot时会初始化units
                    // 但为了健壮性，这里支持动态创建
                    zibll_ad_log('prepare_order: 单元不存在，将创建', array(
                        'request_id' => $request_id,
                        'slot_id' => $slot_id,
                        'unit_key' => $unit_key,
                    ));
                }

                // ============================================================================
                // 第七步：计算价格 (Price Calculation)
                // ============================================================================
                // 【深度思考】为什么要在后端重新计算价格？
                // 1. 前端价格仅用于展示，不可信任
                // 2. 恶意用户可能篡改请求，传入错误的价格
                // 3. 后端计算可以确保价格与套餐配置一致
                // 4. 价格计算逻辑可能随时调整，后端统一管理

                // 传入 unit_key 以支持幻灯片价格差异
                $price_data = $this->calculate_price($slot, $plan_type, $duration_months, $color_key, $unit_key);

                if (!isset($price_data['total_price']) || $price_data['total_price'] <= 0) {
                    throw new Exception('价格计算错误，请联系管理员');
                }

                zibll_ad_log('prepare_order: 价格计算完成', array(
                    'request_id' => $request_id,
                    'base_price' => $price_data['base_price'],
                    'color_price' => $price_data['color_price'],
                    'position_price_diff' => $price_data['position_price_diff'] ?? 0,
                    'total_price' => $price_data['total_price'],
                    'duration_months' => $duration_months,
                    'unit_key' => $unit_key,
                ));

                // ============================================================================
                // 第八步：更新单元状态为pending (Lock Unit)
                // ============================================================================
                // 【深度思考】为什么要设置pending状态？
                // 1. 防止其他用户同时购买
                // 2. 保存用户填写的数据，支付成功后直接使用
                // 3. 设置超时时间，避免长期占用
                // 4. 如果用户放弃支付，系统会自动释放

                $timeout_minutes = zibll_ad_get_order_timeout();
                $pending_expires_at = $current_time + ($timeout_minutes * 60);

                $unit_data = array(
                    'status' => 'pending',
                    'customer_name' => $website_name,
                    'website_name' => $website_name,
                    'website_url' => $website_url,
                    'contact_type' => $contact_type,
                    'contact_value' => $contact_value,
                    'target_url' => $target_url,
                    'price' => $price_data['total_price'],
                    'duration_months' => $duration_months,
                    'pending_expires_at' => $pending_expires_at,
                    'updated_at' => current_time('mysql'),
                );

                // 广告内容（根据类型）
                if ($slot_type === 'image') {
                    $unit_data['image_id'] = $image_id;
                    $unit_data['image_url'] = $image_url;
                    $unit_data['text_content'] = null; // 清空文字内容
                    $unit_data['color_key'] = null;
                } else {
                    $unit_data['text_content'] = $text_content;
                    $unit_data['color_key'] = $color_key;
                    $unit_data['image_id'] = 0; // 清空图片
                    $unit_data['image_url'] = null;
                }

                // 执行更新或插入
                if ($unit) {
                    // 更新现有单元
                    $update_result = $wpdb->update(
                        $table_units,
                        $unit_data,
                        array('id' => $unit['id']),
                        null, // format自动推断
                        array('%d')
                    );

                    if ($update_result === false) {
                        throw new Exception('锁定广告位失败，数据库错误：' . $wpdb->last_error);
                    }

                    $unit_id = $unit['id'];

                    zibll_ad_log('prepare_order: 更新单元为pending', array(
                        'request_id' => $request_id,
                        'unit_id' => $unit_id,
                        'pending_expires_at' => date('Y-m-d H:i:s', $pending_expires_at),
                    ));
                } else {
                    // 创建新单元
                    $unit_data['slot_id'] = $slot_id;
                    $unit_data['unit_key'] = $unit_key;
                    $unit_data['created_at'] = current_time('mysql');

                    $insert_result = $wpdb->insert($table_units, $unit_data);

                    if ($insert_result === false) {
                        throw new Exception('创建订单失败，数据库错误：' . $wpdb->last_error);
                    }

                    $unit_id = $wpdb->insert_id;

                    if (!$unit_id) {
                        throw new Exception('创建订单失败，无法获取单元ID');
                    }

                    zibll_ad_log('prepare_order: 创建新单元', array(
                        'request_id' => $request_id,
                        'unit_id' => $unit_id,
                        'pending_expires_at' => date('Y-m-d H:i:s', $pending_expires_at),
                    ));
                }

                // 提交事务
                $wpdb->query('COMMIT');

                zibll_ad_log('prepare_order: 事务提交成功', array(
                    'request_id' => $request_id,
                    'unit_id' => $unit_id,
                ));

            } catch (Exception $e) {
                // 回滚事务
                $wpdb->query('ROLLBACK');

                zibll_ad_log('prepare_order: 事务回滚', array(
                    'request_id' => $request_id,
                    'error' => $e->getMessage(),
                ));

                // 重新抛出异常
                throw $e;
            }

            // ============================================================================
            // 第九步：生成安全的订单凭证 (Generate Secure Token)
            // ============================================================================
            // 【深度思考】为什么要使用transient + 签名？
            //
            // transient的作用：
            // 1. 临时存储订单数据，避免在URL中传递敏感信息
            // 2. 自动过期，避免数据堆积
            // 3. 支持对象缓存，性能好
            //
            // 为什么要签名？
            // 1. 防止用户篡改transient数据（例如修改价格）
            // 2. 验证数据完整性
            // 3. 防止伪造订单token
            //
            // 签名算法：
            // HMAC-SHA256(订单数据 + WordPress密钥)
            //
            // 为什么安全？
            // 1. 即使黑客知道transient key，也无法篡改数据
            // 2. 修改任何字段都会导致签名验证失败
            // 3. WordPress AUTH_KEY 是站点唯一的，无法从外部获取

            $order_token = 'zibll_ad_order_' . $unit_id . '_' . time() . '_' . wp_generate_password(8, false);

            $order_data = array(
                'slot_id' => $slot_id,
                'slot_title' => isset($slot['title']) ? $slot['title'] : '',
                'unit_id' => $unit_id,
                'unit_key' => $unit_key,
                'plan_type' => $plan_type,
                'duration_months' => $duration_months,
                'base_price' => $price_data['base_price'],
                'color_price' => $price_data['color_price'],
                'total_price' => $price_data['total_price'],
                'ad_data' => array(
                    'website_name' => $website_name,
                    'website_url' => $website_url,
                    'contact_type' => $contact_type,
                    'contact_value' => $contact_value,
                    'target_url' => $target_url,
                    'image_id' => $image_id,
                    'image_url' => $image_url,
                    'text_content' => $text_content,
                    'color_key' => $color_key,
                ),
                'user_id' => get_current_user_id(), // 记录下单用户（0表示未登录）
                'created_at' => current_time('mysql'),
                'created_timestamp' => $current_time,
                'expires_at' => date('Y-m-d H:i:s', $pending_expires_at),
                'request_id' => $request_id, // 关联请求日志
            );

            // 生成数据签名
            $signature = $this->generate_order_signature($order_data);
            $order_data['signature'] = $signature;

            // 保存到transient（有效期为超时时间+5分钟，给用户留出支付时间）
            $transient_expiry = ($timeout_minutes + 5) * 60;
            set_transient($order_token, $order_data, $transient_expiry);

            zibll_ad_log('prepare_order: 订单凭证已生成', array(
                'request_id' => $request_id,
                'order_token' => $order_token,
                'transient_expiry_seconds' => $transient_expiry,
                'has_signature' => !empty($signature),
            ));

            // 记录一条待支付订单（历史保留，不覆盖）
            try {
                global $wpdb;
                $table_orders = $wpdb->prefix . 'zibll_ad_orders';

                // 在待支付阶段也完整记录联系与站点信息，以便后台详情可见
                $customer_snapshot = array(
                    'customer_name' => $website_name,
                    'contact_type'  => $contact_type,
                    'contact_value' => $contact_value,
                    'website_name'  => $website_name,
                    'website_url'   => $website_url,
                    'ad_data' => $order_data['ad_data'],
                    'price_detail' => array(
                        'base_price' => $price_data['base_price'],
                        'color_price' => $price_data['color_price'],
                        'total_price' => $price_data['total_price'],
                        'duration_months' => $duration_months,
                        'plan_type' => $plan_type,
                    ),
                );

                $insert_data = array(
                    'unit_id'         => $unit_id,
                    'slot_id'         => $slot_id,
                    'user_id'         => get_current_user_id(),
                    'customer_snapshot'=> maybe_serialize($customer_snapshot),
                    'attempt_token'   => $order_token,
                    'plan_type'       => $plan_type,
                    'duration_months' => $duration_months,
                    'base_price'      => $price_data['base_price'],
                    'color_price'     => $price_data['color_price'],
                    'total_price'     => $price_data['total_price'],
                    'pay_status'      => 'pending',
                    'created_at'      => current_time('mysql'),
                );

                $res = $wpdb->insert($table_orders, $insert_data);
                if ($res === false) {
                    zibll_ad_log('prepare_order: 记录待支付订单失败', array(
                        'wpdb_error' => $wpdb->last_error,
                        'insert_data' => $insert_data,
                    ));
                } else {
                    zibll_ad_log('prepare_order: 已记录待支付订单', array(
                        'order_row_id' => $wpdb->insert_id,
                        'attempt_token' => $order_token,
                    ));
                }
            } catch (Exception $ex) {
                zibll_ad_log('prepare_order: 记录待支付订单异常', array(
                    'message' => $ex->getMessage(),
                ));
            }

            // ============================================================================
            // 第十步：构造支付URL (Build Payment URL)
            // ============================================================================
            // 【深度思考】如何对接ZibPay？
            //
            // 根据开发方案.txt，ZibPay支付流程：
            // 1. 前端提交订单到 ZibPay 的 submit_order 接口
            // 2. 传递 order_type=31 标识为广告订单
            // 3. 传递 order_token，用于还原订单数据
            // 4. ZibPay 通过 filter 'initiate_order_data_type_31' 回调我们的插件
            // 5. 我们在回调中读取 transient，填充订单价格和商品信息
            // 6. 支付成功后，ZibPay 触发 'payment_order_success' action
            // 7. 我们在 action 中更新 unit 状态为 paid
            //
            // 这里返回的信息供前端使用，前端会：
            // 1. 显示订单信息（价格、时长）
            // 2. 跳转到支付页面或弹出支付对话框
            // 3. 调用 ZibPay 的支付接口

            // 生成支付页面URL（主题的支付页面路径）
            $pay_url = home_url('/shop/pay'); // ZibPay的支付页面

            // 构造提交给ZibPay的表单数据
            $zibpay_form_data = array(
                'order_type' => '31', // 自定义订单类型（广告订单）
                'order_token' => $order_token,
                'slot_id' => $slot_id,
                'unit_id' => $unit_id,
                'unit_key' => $unit_key,
            );

            // ============================================================================
            // 第十一步：返回成功响应 (Send Response)
            // ============================================================================
            $elapsed_time = round((microtime(true) - $start_time) * 1000, 2); // 毫秒

            zibll_ad_log('prepare_order: 订单创建成功', array(
                'request_id' => $request_id,
                'order_token' => $order_token,
                'unit_id' => $unit_id,
                'total_price' => $price_data['total_price'],
                'elapsed_time_ms' => $elapsed_time,
            ));

            wp_send_json_success(array(
                'message' => '订单创建成功，即将跳转到支付页面',
                'order_token' => $order_token,
                'unit_id' => $unit_id,
                'slot_title' => isset($slot['title']) ? $slot['title'] : '',
                'duration_months' => $duration_months,
                'total_price' => $price_data['total_price'],
                'formatted_price' => zibll_ad_format_price($price_data['total_price']),
                'expires_at' => date('Y-m-d H:i:s', $pending_expires_at),
                'timeout_minutes' => $timeout_minutes,
                'pay_url' => $pay_url,
                'zibpay_form_data' => $zibpay_form_data,
            ));

        } catch (Exception $e) {
            // ============================================================================
            // 统一异常处理 (Exception Handling)
            // ============================================================================
            $elapsed_time = round((microtime(true) - $start_time) * 1000, 2);

            zibll_ad_log('prepare_order: 订单创建失败', array(
                'request_id' => $request_id,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'elapsed_time_ms' => $elapsed_time,
            ));

            // 返回错误响应（用户友好的错误信息）
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'request_id' => $request_id, // 返回请求ID，便于用户反馈问题时提供
            ));
        }
    }

    /**
     * ============================================================================
     * 生成订单数据签名 (Generate Order Signature)
     * ============================================================================
     *
     * 【目的】防止transient数据被篡改
     *
     * 【算法】HMAC-SHA256
     * 1. 将订单数据序列化为字符串
     * 2. 使用WordPress AUTH_KEY作为密钥
     * 3. 计算HMAC-SHA256
     * 4. Base64编码
     *
     * 【验证】在ZibPay回调中，重新计算签名并比对
     *
     * @param array $order_data 订单数据
     * @return string 签名字符串
     */
    private function generate_order_signature($order_data) {
        // 移除旧签名（如果存在）
        $data_to_sign = $order_data;
        unset($data_to_sign['signature']);

        // 序列化数据
        $serialized = maybe_serialize($data_to_sign);

        // 获取WordPress密钥（每个站点唯一）
        if (defined('AUTH_KEY') && AUTH_KEY) {
            $secret_key = AUTH_KEY;
        } else {
            // 降级方案：使用数据库前缀（虽然安全性较低）
            global $wpdb;
            $secret_key = $wpdb->prefix . 'zibll_ad_secret';
        }

        // 计算HMAC
        $signature = hash_hmac('sha256', $serialized, $secret_key);

        return $signature;
    }

    /**
     * 验证订单签名
     *
     * @param array $order_data 订单数据（包含signature字段）
     * @return bool 签名是否有效
     */
    private function verify_order_signature($order_data) {
        if (!isset($order_data['signature'])) {
            return false;
        }

        $received_signature = $order_data['signature'];
        $expected_signature = $this->generate_order_signature($order_data);

        // 使用hash_equals防止时序攻击
        return hash_equals($expected_signature, $received_signature);
    }

    /**
     * 计算价格
     *
     * @param array  $slot           广告位配置
     * @param string $plan_type      套餐类型 package|custom
     * @param int    $duration_months 购买月数
     * @param string $color_key      颜色键（文字广告）
     * @return array 价格数据
     */
    private function calculate_price($slot, $plan_type, $duration_months, $color_key = '', $unit_key = 0) {
        $base_price = 0;
        $color_price = 0;
        $position_price_diff = 0;

        // 计算基础价格
        if ($plan_type === 'package') {
            // 套餐价格
            $packages = isset($slot['pricing_packages']) ? $slot['pricing_packages'] : array();

            foreach ($packages as $package) {
                if (isset($package['months']) && intval($package['months']) === $duration_months) {
                    $base_price = isset($package['price']) ? floatval($package['price']) : 0;
                    break;
                }
            }

            if ($base_price === 0) {
                throw new Exception('未找到匹配的套餐');
            }
        } else {
            // 自定义月数
            $single_month_price = isset($slot['pricing_single_month']) ? floatval($slot['pricing_single_month']) : 0;

            if ($single_month_price <= 0) {
                throw new Exception('单月价格未设置');
            }

            $base_price = $single_month_price * $duration_months;
        }

        // 计算颜色附加价（仅文字广告）
        if (!empty($color_key) && isset($slot['text_color_options'])) {
            $color_options = $slot['text_color_options'];

            if (is_array($color_options)) {
                foreach ($color_options as $option) {
                    if (isset($option['key']) && $option['key'] === $color_key) {
                        $color_price = isset($option['price']) ? floatval($option['price']) : 0;
                        break;
                    }
                }
            }
        }

        // 计算幻灯片位置价格差异（每月差异 × 购买月数）
        $image_display_mode = isset($slot['image_display_mode']) ? $slot['image_display_mode'] : 'grid';
        if ($image_display_mode === 'carousel' && isset($slot['carousel_price_diff_enabled']) && $slot['carousel_price_diff_enabled']) {
            // 获取差异配置
            $diff_type = isset($slot['carousel_price_diff_type']) ? $slot['carousel_price_diff_type'] : 'decrement';
            $diff_amount = isset($slot['carousel_price_diff_amount']) ? floatval($slot['carousel_price_diff_amount']) : 0;
            
            // 第一个位置（unit_key=0）不加差异，从第二个位置开始
            if ($unit_key > 0 && $diff_amount > 0) {
                // 每月差异 × 位置序号 × 购买月数
                $position_price_diff = $diff_amount * $unit_key * $duration_months;
                
                // 递减时为负数
                if ($diff_type === 'decrement') {
                    $position_price_diff = -$position_price_diff;
                }
            }
        }

        $total_price = $base_price + $color_price + $position_price_diff;
        
        // 确保价格不为负数
        $total_price = max(0, $total_price);

        return array(
            'base_price' => $base_price,
            'color_price' => $color_price,
            'position_price_diff' => $position_price_diff,
            'total_price' => $total_price,
        );
    }

    /**
     * 验证 URL
     *
     * @param string $url URL
     * @return bool 是否有效
     */
    private function validate_url($url) {
        if (empty($url)) {
            return false;
        }

        // 验证URL格式
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // 验证必须使用 HTTP 或 HTTPS
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            return false;
        }

        return true;
    }

    /**
     * 验证联系方式
     *
     * @param string $type  类型 qq|wechat|email
     * @param string $value 值
     * @return bool 是否有效
     */
    private function validate_contact($type, $value) {
        if (empty($value)) {
            return false;
        }

        switch ($type) {
            case 'qq':
                // QQ号：1-9开头的5位以上数字
                return preg_match('/^[1-9][0-9]{4,}$/', $value) === 1;

            case 'wechat':
                // 微信号：字母开头，6-20位字母数字下划线减号
                return preg_match('/^[a-zA-Z][-_a-zA-Z0-9]{5,19}$/', $value) === 1;

            case 'email':
                // 邮箱
                return is_email($value) !== false;

            default:
                return false;
        }
    }

    /**
     * 解析内存限制字符串为字节数
     *
     * @param string $size 内存大小字符串（如 "256M", "1G", "512K"）
     * @return int 字节数
     */
    private function parse_memory_limit($size) {
        if (empty($size) || $size === '-1') {
            return PHP_INT_MAX; // 无限制
        }

        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $value = intval(substr($size, 0, -1));

        switch ($unit) {
            case 'G':
                return $value * 1024 * 1024 * 1024;
            case 'M':
                return $value * 1024 * 1024;
            case 'K':
                return $value * 1024;
            default:
                return intval($size); // 纯数字，默认为字节
        }
    }
}
