<?php
/**
 * 悬浮“未支付订单”缩略图修复（仅此区域，最小范围）
 *
 * 主题在浮窗里直接拼接了 <img src="">（当 post 无封面时），
 * 没有提供后端过滤钩子。为避免裂图，这里仅对浮窗区域兜底：
 * - 选中 .float-right-wait-pay 内的 .order-thumb img[src=""]
 * - 替换为插件内置 SVG（与订单历史页保持一致）
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Order_Float_Fix {
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_inline_script'));
    }

    public function enqueue_inline_script() {
        $handle = 'zibll-ad-order-float-fix';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', array(), ZIBLL_AD_VERSION, true);
        }
        wp_enqueue_script($handle);

        $svg = ZIBLL_AD_URL . 'includes/frontend/assets/img/ad-order.svg';
        $inline = <<<'JS'
(function(){
  function patch(){
    var scope = document.querySelector('.float-right-wait-pay');
    if(!scope) return;
    var list = scope.querySelectorAll('.order-thumb img');
    for(var i=0;i<list.length;i++){
      var img=list[i];
      var src=(img.getAttribute('src')||'').trim();
      var item = img.closest('.order-item');
      var isAd = item && item.classList && item.classList.contains('order-type-31');
      if(isAd || !src){
        img.setAttribute('src', '__AD_SVG__');
        if(!img.classList.contains('fit-cover')) img.classList.add('fit-cover');
        if(!img.classList.contains('radius8')) img.classList.add('radius8');
        img.classList.remove('vip-card');
        if(img.hasAttribute('data-src')) img.removeAttribute('data-src');
        if(img.classList.contains('lazyload')) img.classList.remove('lazyload');
        if(img.classList.contains('lazyloadafter')) img.classList.remove('lazyloadafter');
      }
    }
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded', patch);}else{patch();}
  document.addEventListener('mouseover', function(e){ var t=e.target; if(t && t.closest && t.closest('.float-right-wait-pay')){ setTimeout(patch, 0); } });
})();
JS;
        $inline = str_replace('__AD_SVG__', esc_js($svg), $inline);

        wp_add_inline_script($handle, $inline);
    }
}
