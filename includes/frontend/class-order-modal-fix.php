<?php
/**
 * 订单详情模态框缩略图修复（仅此区域，最小范围）
 *
 * 由于主题该处无后端钩子可替换缩略图，当广告订单无封面时会出现 <img src="">。
 * 这里针对模态框内容做一次最小范围兜底，将空 src 的缩略图替换为插件内置 SVG。
 */

if (!defined('ABSPATH')) {
    exit;
}

class Zibll_Ad_Order_Modal_Fix {
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_inline_script'));
    }

    public function enqueue_inline_script() {
        $handle = 'zibll-ad-order-modal-fix';
        if (!wp_script_is($handle, 'registered')) {
            wp_register_script($handle, '', array(), ZIBLL_AD_VERSION, true);
        }
        wp_enqueue_script($handle);

        $svg = ZIBLL_AD_URL . 'includes/frontend/assets/img/ad-order.svg';
        $inline = <<<'JS'
(function(){
  function patchIn(node){
    var root = (node && typeof node.querySelectorAll === 'function') ? node : document;
    var sels = ['.refresh-modal .order-thumb img', '.modal .order-thumb img'];
    for(var s=0; s<sels.length; s++){
      var list = root.querySelectorAll(sels[s]);
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
  }
  function patchLater(){ for(var i=0;i<6;i++){ setTimeout(patchIn, 150*i); } }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', function(){ patchIn(); }); } else { patchIn(); }
  document.addEventListener('click', function(e){
    var t=e.target;
    if(!t) return;
    if((t.closest && (t.closest('[data-toggle="RefreshModal"]')|| t.closest('.show-order-modal'))) || (t.matches && (t.matches('[data-toggle="RefreshModal"]')|| t.matches('.show-order-modal')))) {
      patchLater();
    }
  }, true);
  if(window.MutationObserver){
    var mo=new MutationObserver(function(ms){ ms.forEach(function(m){ if(m.addedNodes){ m.addedNodes.forEach(function(n){ try{ if(n.querySelector && ((n.matches && (n.matches('.refresh-modal')|| n.matches('.modal'))) || n.querySelector('.order-thumb'))){ patchIn(n); } }catch(e){} }); } }); });
    mo.observe(document.documentElement, {childList:true, subtree:true});
  }
})();
JS;
        $inline = str_replace('__AD_SVG__', esc_js($svg), $inline);

        wp_add_inline_script($handle, $inline);
    }
}
