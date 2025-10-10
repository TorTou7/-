<?php
/**
 * Widget 自动挂载管理器
 *
 * 负责管理广告位 Widget 的自动挂载、卸载和实例配置
 *
 * 深度设计思考：
 * 1. 单例模式：确保全局只有一个管理器实例，避免状态不一致
 * 2. 事务性操作：支持备份和回滚机制
 * 3. 验证机制：严格验证 sidebar 和 widget 的有效性
 * 4. 日志记录：详细记录所有挂载操作，便于调试
 * 5. 钩子支持：允许第三方扩展挂载逻辑
 *
 * @package Zibll_Ad
 */

// 禁止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Widget 挂载管理器类
 */
class Zibll_Ad_Widget_Manager {

    /**
     * Widget ID 基础名称
     *
     * @var string
     */
    const WIDGET_ID_BASE = 'zibll_ad_widget';

    /**
     * Widget 选项名称
     *
     * @var string
     */
    const WIDGET_OPTION_NAME = 'widget_zibll_ad_widget';

    /**
     * 单例实例
     *
     * @var Zibll_Ad_Widget_Manager
     */
    private static $instance = null;

    /**
     * 操作备份（用于回滚）
     *
     * @var array
     */
    private $backup = array();

    /**
     * 获取单例实例
     *
     * @return Zibll_Ad_Widget_Manager
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数（私有）
     */
    private function __construct() {
        // 单例模式，防止外部实例化
    }

    /**
     * 自动挂载 Widget（根据新旧绑定差异）
     *
     * 这是主要的公共接口，由 REST API 或其他模块调用
     *
     * @param int   $slot_id      广告位 ID
     * @param array $old_bindings 旧的 sidebar 绑定列表
     * @param array $new_bindings 新的 sidebar 绑定列表
     * @return bool|WP_Error 成功返回 true，失败返回 WP_Error
     */
    public function auto_mount($slot_id, $old_bindings, $new_bindings) {
        // 验证 slot_id
        if (!$slot_id || $slot_id <= 0) {
            return new WP_Error(
                'invalid_slot_id',
                __('无效的广告位 ID', 'zibll-ad')
            );
        }

        // 确保参数是数组
        $old_bindings = is_array($old_bindings) ? $old_bindings : array();
        $new_bindings = is_array($new_bindings) ? $new_bindings : array();

        // 移除重复项并重新索引
        $old_bindings = array_values(array_unique($old_bindings));
        $new_bindings = array_values(array_unique($new_bindings));

        // 记录日志
        zibll_ad_log('Auto-mounting widgets for slot ' . $slot_id, array(
            'old_bindings' => $old_bindings,
            'new_bindings' => $new_bindings,
        ));

        // 计算差异
        $to_mount = array_diff($new_bindings, $old_bindings);   // 需要新挂载的
        $to_unmount = array_diff($old_bindings, $new_bindings); // 需要卸载的

        // 如果没有变化，直接返回
        if (empty($to_mount) && empty($to_unmount)) {
            zibll_ad_log('No widget mounting changes needed for slot ' . $slot_id);
            return true;
        }

        // 执行前的钩子
        do_action('zibll_ad_before_auto_mount', $slot_id, $old_bindings, $new_bindings);

        // 创建备份点
        $this->create_backup();

        // 执行挂载和卸载
        try {
            // 先处理卸载
            foreach ($to_unmount as $sidebar_id) {
                $result = $this->unmount_widget($slot_id, $sidebar_id);
                if (is_wp_error($result)) {
                    // 如果卸载失败，记录警告但继续（因为可能已经不存在）
                    zibll_ad_log('Warning: Failed to unmount widget', array(
                        'slot_id' => $slot_id,
                        'sidebar_id' => $sidebar_id,
                        'error' => $result->get_error_message(),
                    ));
                }
            }

            // 再处理挂载
            foreach ($to_mount as $sidebar_id) {
                $result = $this->mount_widget($slot_id, $sidebar_id);
                if (is_wp_error($result)) {
                    // 挂载失败，回滚并返回错误
                    $this->rollback();
                    return $result;
                }
            }

            // 清理不再使用的 widget 实例
            $this->cleanup_orphaned_instances($slot_id);

            // 执行后的钩子
            do_action('zibll_ad_after_auto_mount', $slot_id, $old_bindings, $new_bindings);

            zibll_ad_log('Successfully auto-mounted widgets for slot ' . $slot_id);

            return true;

        } catch (Exception $e) {
            // 捕获异常，回滚
            $this->rollback();
            return new WP_Error(
                'mount_exception',
                __('Widget 挂载过程发生异常：', 'zibll-ad') . $e->getMessage()
            );
        }
    }

    /**
     * 挂载单个 Widget 到指定 sidebar
     *
     * @param int    $slot_id    广告位 ID
     * @param string $sidebar_id Sidebar ID
     * @return bool|WP_Error 成功返回 true，失败返回 WP_Error
     */
    public function mount_widget($slot_id, $sidebar_id) {
        // 验证 sidebar 是否存在
        if (!$this->is_sidebar_valid($sidebar_id)) {
            return new WP_Error(
                'invalid_sidebar',
                sprintf(__('Sidebar "%s" 不存在或无效', 'zibll-ad'), $sidebar_id)
            );
        }

        // 获取当前的 sidebars_widgets
        $sidebars_widgets = $this->get_sidebars_widgets();

        // 初始化 sidebar 数组（如果不存在）
        if (!isset($sidebars_widgets[$sidebar_id])) {
            $sidebars_widgets[$sidebar_id] = array();
        }

        // 生成 widget 实例 ID
        $widget_id = $this->get_widget_id($slot_id);

        // 检查是否已经存在
        if (in_array($widget_id, $sidebars_widgets[$sidebar_id])) {
            zibll_ad_log('Widget already mounted in sidebar', array(
                'slot_id' => $slot_id,
                'sidebar_id' => $sidebar_id,
            ));
            return true; // 已存在，视为成功
        }

        // 添加到 sidebar
        $sidebars_widgets[$sidebar_id][] = $widget_id;

        // 保存 sidebars_widgets
        $this->set_sidebars_widgets($sidebars_widgets);

        // 验证保存结果 - 重新读取并检查
        $sidebars_widgets_after = $this->get_sidebars_widgets();
        if (!isset($sidebars_widgets_after[$sidebar_id]) || !in_array($widget_id, $sidebars_widgets_after[$sidebar_id])) {
            return new WP_Error(
                'save_sidebars_failed',
                __('保存 sidebars_widgets 失败', 'zibll-ad')
            );
        }

        // 创建或更新 widget 实例配置
        $instance_result = $this->update_widget_instance($slot_id, array(
            'slot_id' => $slot_id,
        ));

        if (is_wp_error($instance_result)) {
            // 实例创建失败，回滚 sidebar 更改
            $this->rollback();
            return $instance_result;
        }

        // 验证挂载结果
        $mounted_successfully = isset($sidebars_widgets_after[$sidebar_id]) && in_array($widget_id, $sidebars_widgets_after[$sidebar_id]);

        zibll_ad_log('Widget mount result', array(
            'slot_id' => $slot_id,
            'sidebar_id' => $sidebar_id,
            'widget_id' => $widget_id,
            'mounted_successfully' => $mounted_successfully,
            'widgets_in_sidebar' => isset($sidebars_widgets_after[$sidebar_id]) ? $sidebars_widgets_after[$sidebar_id] : array(),
        ));

        return true;
    }

    /**
     * 从指定 sidebar 卸载 Widget
     *
     * @param int    $slot_id    广告位 ID
     * @param string $sidebar_id Sidebar ID
     * @return bool|WP_Error 成功返回 true，失败返回 WP_Error
     */
    public function unmount_widget($slot_id, $sidebar_id) {
        // 获取当前的 sidebars_widgets
        $sidebars_widgets = $this->get_sidebars_widgets();

        // 检查 sidebar 是否存在
        if (!isset($sidebars_widgets[$sidebar_id])) {
            // Sidebar 不存在，视为已卸载
            return true;
        }

        // 生成 widget 实例 ID
        $widget_id = $this->get_widget_id($slot_id);

        // 查找并移除
        $key = array_search($widget_id, $sidebars_widgets[$sidebar_id]);
        if ($key !== false) {
            unset($sidebars_widgets[$sidebar_id][$key]);
            // 重新索引数组（重要！保持数组索引连续）
            $sidebars_widgets[$sidebar_id] = array_values($sidebars_widgets[$sidebar_id]);

            // 保存
            $this->set_sidebars_widgets($sidebars_widgets);

            // 验证卸载结果
            $sidebars_widgets_after = $this->get_sidebars_widgets();
            if (isset($sidebars_widgets_after[$sidebar_id]) && in_array($widget_id, $sidebars_widgets_after[$sidebar_id])) {
                zibll_ad_log('Failed to unmount widget', array(
                    'slot_id' => $slot_id,
                    'sidebar_id' => $sidebar_id,
                    'widget_id' => $widget_id,
                ));
                return new WP_Error(
                    'unmount_failed',
                    __('卸载 Widget 失败', 'zibll-ad')
                );
            }

            zibll_ad_log('Widget unmounted successfully', array(
                'slot_id' => $slot_id,
                'sidebar_id' => $sidebar_id,
                'widget_id' => $widget_id,
            ));
        }

        return true;
    }

    /**
     * 卸载 Widget 的所有实例（从所有 sidebar）
     *
     * @param int $slot_id 广告位 ID
     * @return bool|WP_Error
     */
    public function unmount_all($slot_id) {
        $sidebars_widgets = $this->get_sidebars_widgets();
        $widget_id = $this->get_widget_id($slot_id);

        $unmounted_count = 0;

        foreach ($sidebars_widgets as $sidebar_id => $widgets) {
            if ($sidebar_id === 'wp_inactive_widgets') {
                continue; // 跳过非活动 widgets
            }

            if (is_array($widgets) && in_array($widget_id, $widgets)) {
                $result = $this->unmount_widget($slot_id, $sidebar_id);
                if (!is_wp_error($result)) {
                    $unmounted_count++;
                }
            }
        }

        // 删除 widget 实例配置
        $this->delete_widget_instance($slot_id);

        zibll_ad_log('Unmounted all widget instances for slot ' . $slot_id, array(
            'count' => $unmounted_count,
        ));

        return true;
    }

    /**
     * 获取 Widget 当前挂载的所有 sidebar
     *
     * @param int $slot_id 广告位 ID
     * @return array Sidebar ID 列表
     */
    public function get_mounted_sidebars($slot_id) {
        $sidebars_widgets = $this->get_sidebars_widgets();
        $widget_id = $this->get_widget_id($slot_id);

        $mounted = array();

        foreach ($sidebars_widgets as $sidebar_id => $widgets) {
            if ($sidebar_id === 'wp_inactive_widgets') {
                continue;
            }

            if (is_array($widgets) && in_array($widget_id, $widgets)) {
                $mounted[] = $sidebar_id;
            }
        }

        return $mounted;
    }

    /**
     * 检查 Widget 是否已挂载到指定 sidebar
     *
     * @param int    $slot_id    广告位 ID
     * @param string $sidebar_id Sidebar ID
     * @return bool
     */
    public function is_mounted($slot_id, $sidebar_id) {
        $mounted_sidebars = $this->get_mounted_sidebars($slot_id);
        return in_array($sidebar_id, $mounted_sidebars);
    }

    /**
     * 更新 Widget 实例配置
     *
     * @param int   $slot_id  广告位 ID（用作实例 key）
     * @param array $instance 实例配置数据
     * @return bool|WP_Error
     */
    private function update_widget_instance($slot_id, $instance) {
        $widget_instances = get_option(self::WIDGET_OPTION_NAME, array());

        if (!is_array($widget_instances)) {
            $widget_instances = array();
        }

        // 使用 slot_id 作为实例 key
        $widget_instances[$slot_id] = $instance;

        $update_result = update_option(self::WIDGET_OPTION_NAME, $widget_instances);

        // update_option 在值未改变时返回 false,所以需要验证实际值
        $saved_instances = get_option(self::WIDGET_OPTION_NAME, array());
        if (!isset($saved_instances[$slot_id]) || $saved_instances[$slot_id] !== $instance) {
            zibll_ad_log('Failed to save widget instance', array(
                'slot_id' => $slot_id,
                'instance' => $instance,
                'saved_instances' => $saved_instances,
            ));
            return new WP_Error(
                'update_instance_failed',
                __('更新 Widget 实例配置失败', 'zibll-ad')
            );
        }

        zibll_ad_log('Widget instance saved successfully', array(
            'slot_id' => $slot_id,
            'instance' => $instance,
        ));

        return true;
    }

    /**
     * 删除 Widget 实例配置
     *
     * @param int $slot_id 广告位 ID
     * @return bool
     */
    private function delete_widget_instance($slot_id) {
        $widget_instances = get_option(self::WIDGET_OPTION_NAME, array());

        if (!is_array($widget_instances)) {
            return true;
        }

        if (isset($widget_instances[$slot_id])) {
            unset($widget_instances[$slot_id]);
            update_option(self::WIDGET_OPTION_NAME, $widget_instances);
        }

        return true;
    }

    /**
     * 清理孤儿 Widget 实例（不在任何 sidebar 中的实例）
     *
     * @param int $slot_id 广告位 ID
     * @return bool
     */
    private function cleanup_orphaned_instances($slot_id) {
        $mounted_sidebars = $this->get_mounted_sidebars($slot_id);

        // 如果不在任何 sidebar 中，删除实例配置
        if (empty($mounted_sidebars)) {
            $this->delete_widget_instance($slot_id);
            zibll_ad_log('Cleaned up orphaned widget instance for slot ' . $slot_id);
        }

        return true;
    }

    /**
     * 验证 sidebar 是否有效
     *
     * @param string $sidebar_id Sidebar ID
     * @return bool
     */
    private function is_sidebar_valid($sidebar_id) {
        global $wp_registered_sidebars;

        return isset($wp_registered_sidebars[$sidebar_id]);
    }

    /**
     * 生成 Widget 实例 ID
     *
     * @param int $slot_id 广告位 ID
     * @return string Widget ID（格式：zibll_ad_widget-{slot_id}）
     */
    private function get_widget_id($slot_id) {
        return self::WIDGET_ID_BASE . '-' . $slot_id;
    }

    /**
     * 获取 sidebars_widgets（带缓存清理）
     *
     * @return array
     */
    private function get_sidebars_widgets() {
        // 先删除缓存,确保获取最新数据
        wp_cache_delete('sidebars_widgets', 'global');

        $sidebars_widgets = wp_get_sidebars_widgets();

        if (!is_array($sidebars_widgets)) {
            $sidebars_widgets = array();
        }

        return $sidebars_widgets;
    }

    /**
     * 保存 sidebars_widgets
     *
     * @param array $sidebars_widgets Sidebars widgets 数组
     * @return bool
     */
    private function set_sidebars_widgets($sidebars_widgets) {
        // 先删除缓存,确保使用新数据
        wp_cache_delete('sidebars_widgets', 'global');

        // 保存到数据库
        $result = wp_set_sidebars_widgets($sidebars_widgets);

        // 记录详细日志
        zibll_ad_log('set_sidebars_widgets called', array(
            'result' => $result,
            'sidebars_widgets_count' => count($sidebars_widgets),
            'first_5_keys' => array_slice(array_keys($sidebars_widgets), 0, 5),
        ));

        // 再次删除缓存,强制下次读取时从数据库加载
        wp_cache_delete('sidebars_widgets', 'global');

        return $result;
    }

    /**
     * 创建备份点（用于回滚）
     */
    private function create_backup() {
        $this->backup = array(
            'sidebars_widgets' => $this->get_sidebars_widgets(),
            'widget_instances' => get_option(self::WIDGET_OPTION_NAME, array()),
        );

        zibll_ad_log('Widget manager backup created');
    }

    /**
     * 回滚到备份点
     */
    private function rollback() {
        if (empty($this->backup)) {
            zibll_ad_log('No backup to rollback');
            return;
        }

        // 恢复 sidebars_widgets
        if (isset($this->backup['sidebars_widgets'])) {
            $this->set_sidebars_widgets($this->backup['sidebars_widgets']);
        }

        // 恢复 widget 实例
        if (isset($this->backup['widget_instances'])) {
            update_option(self::WIDGET_OPTION_NAME, $this->backup['widget_instances']);
        }

        zibll_ad_log('Widget manager rolled back to backup');

        // 清空备份
        $this->backup = array();
    }

    /**
     * 获取所有 Widget 实例的统计信息（调试用）
     *
     * @return array
     */
    public function get_stats() {
        $sidebars_widgets = $this->get_sidebars_widgets();
        $widget_instances = get_option(self::WIDGET_OPTION_NAME, array());

        $stats = array(
            'total_instances' => count($widget_instances),
            'mounted_widgets' => array(),
            'orphaned_instances' => array(),
        );

        // 统计每个实例的挂载情况
        foreach ($widget_instances as $slot_id => $instance) {
            $mounted_sidebars = $this->get_mounted_sidebars($slot_id);

            if (empty($mounted_sidebars)) {
                $stats['orphaned_instances'][] = $slot_id;
            } else {
                $stats['mounted_widgets'][$slot_id] = $mounted_sidebars;
            }
        }

        return $stats;
    }
}
