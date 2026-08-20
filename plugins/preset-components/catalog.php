<?php

return (static function (): array {
    $tagPrefix = function_exists('brand_tag_prefix') ? brand_tag_prefix() : (string)config('brand.tag_prefix', config('site_name', ''));
    $cssPrefix = function_exists('brand_css_prefix') ? brand_css_prefix() : (string)config('brand.css_prefix', config('site_name', ''));
    $make = static function (
        string $slug,
        string $title,
        string $tag,
        string $category,
        array $targets,
        string $summary,
        string $html,
        string $css,
        string $js,
        array $params,
        int $sortOrder
    ) use ($cssPrefix): array {
        return [
            'slug' => $slug,
            'title' => $title,
            'tag' => $tag,
            'category' => $category,
            'targets' => $targets,
            'summary' => $summary,
            'html' => trim(str_replace('{{css_prefix}}', $cssPrefix, $html)),
            'css' => trim(str_replace('{{css_prefix}}', $cssPrefix, $css)),
            'js' => trim(str_replace('{{css_prefix}}', $cssPrefix, $js)),
            'params' => $params,
            'sort_order' => $sortOrder,
        ];
    };

    $textTargets = ['page', 'article', 'product', 'template'];
    $pageTargets = ['page', 'template'];

    return [
        $make(
            'hero-slider',
            '轮播图',
            ($tagPrefix . '-preset-slider'),
            'marketing',
            $textTargets,
            '适合首页首屏、活动页、产品页顶部的轻量轮播组件。',
            <<<'HTML'
<section class="{{css_prefix}}-preset-slider" data-autoplay="{{autoplay}}">
    <div class="{{css_prefix}}-preset-slider__track">
        <article class="{{css_prefix}}-preset-slider__item is-active" style="--image:url('{{image_1}}')">
            <div>
                <span>{{eyebrow}}</span>
                <h2>{{title_1}}</h2>
                <p>{{subtitle_1}}</p>
                <a href="{{button_url}}">{{button_text}}</a>
            </div>
        </article>
        <article class="{{css_prefix}}-preset-slider__item" style="--image:url('{{image_2}}')">
            <div>
                <span>{{eyebrow}}</span>
                <h2>{{title_2}}</h2>
                <p>{{subtitle_2}}</p>
                <a href="{{button_url}}">{{button_text}}</a>
            </div>
        </article>
    </div>
    <button class="{{css_prefix}}-preset-slider__nav {{css_prefix}}-preset-slider__prev" type="button" aria-label="上一张">‹</button>
    <button class="{{css_prefix}}-preset-slider__nav {{css_prefix}}-preset-slider__next" type="button" aria-label="下一张">›</button>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-preset-slider{position:relative;overflow:hidden;border-radius:18px;background:#111827;color:#fff;min-height:360px}
.{{css_prefix}}-preset-slider__track,.{{css_prefix}}-preset-slider__item{min-height:360px}
.{{css_prefix}}-preset-slider__item{display:none;align-items:center;background-image:linear-gradient(90deg,rgba(8,15,31,.82),rgba(8,15,31,.22)),var(--image);background-size:cover;background-position:center;padding:56px}
.{{css_prefix}}-preset-slider__item.is-active{display:flex}
.{{css_prefix}}-preset-slider span{display:inline-flex;margin-bottom:12px;color:#a7f3d0;font-weight:700}
.{{css_prefix}}-preset-slider h2{max-width:720px;margin:0 0 14px;font-size:clamp(32px,5vw,64px);line-height:1.05;letter-spacing:0}
.{{css_prefix}}-preset-slider p{max-width:560px;margin:0 0 24px;color:#dbeafe;font-size:18px;line-height:1.7}
.{{css_prefix}}-preset-slider a{display:inline-flex;align-items:center;min-height:44px;padding:0 22px;border-radius:999px;background:#fff;color:#111827;text-decoration:none;font-weight:700}
.{{css_prefix}}-preset-slider__nav{position:absolute;top:50%;transform:translateY(-50%);width:42px;height:42px;border:0;border-radius:50%;background:rgba(255,255,255,.86);color:#111827;font-size:28px;cursor:pointer}
.{{css_prefix}}-preset-slider__prev{left:18px}.{{css_prefix}}-preset-slider__next{right:18px}
@media (max-width:640px){.{{css_prefix}}-preset-slider,.{{css_prefix}}-preset-slider__track,.{{css_prefix}}-preset-slider__item{min-height:420px}.{{css_prefix}}-preset-slider__item{padding:72px 24px 36px}.{{css_prefix}}-preset-slider__nav{top:auto;bottom:18px;transform:none}}
CSS,
            <<<'JS'
(function(){
    document.querySelectorAll('.{{css_prefix}}-preset-slider').forEach(function(root){
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        var items = Array.prototype.slice.call(root.querySelectorAll('.{{css_prefix}}-preset-slider__item'));
        var index = 0;
        function show(next){ items[index].classList.remove('is-active'); index = (next + items.length) % items.length; items[index].classList.add('is-active'); }
        var prev = root.querySelector('.{{css_prefix}}-preset-slider__prev');
        var next = root.querySelector('.{{css_prefix}}-preset-slider__next');
        if (prev) prev.addEventListener('click', function(){ show(index - 1); });
        if (next) next.addEventListener('click', function(){ show(index + 1); });
        if (root.dataset.autoplay !== '0' && items.length > 1) setInterval(function(){ show(index + 1); }, 5200);
    });
})();
JS,
            [
                ['key' => 'eyebrow', 'label' => '眉标', 'type' => 'text', 'default' => '精选推荐'],
                ['key' => 'title_1', 'label' => '第一张标题', 'type' => 'text', 'default' => '让页面第一眼就抓住重点'],
                ['key' => 'subtitle_1', 'label' => '第一张描述', 'type' => 'text', 'default' => '用清晰的视觉和行动按钮承接活动、产品或品牌入口。'],
                ['key' => 'title_2', 'label' => '第二张标题', 'type' => 'text', 'default' => '灵活展示多组内容'],
                ['key' => 'subtitle_2', 'label' => '第二张描述', 'type' => 'text', 'default' => '支持前台直接通过组件属性替换文案和图片。'],
                ['key' => 'image_1', 'label' => '第一张图片', 'type' => 'url', 'default' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1400&q=80'],
                ['key' => 'image_2', 'label' => '第二张图片', 'type' => 'url', 'default' => 'https://images.unsplash.com/photo-1518005020951-eccb494ad742?auto=format&fit=crop&w=1400&q=80'],
                ['key' => 'button_text', 'label' => '按钮文字', 'type' => 'text', 'default' => '了解更多'],
                ['key' => 'button_url', 'label' => '按钮链接', 'type' => 'url', 'default' => '#'],
                ['key' => 'autoplay', 'label' => '自动播放', 'type' => 'number', 'default' => '1'],
            ],
            10
        ),

        $make(
            'event-calendar',
            '日历',
            ($tagPrefix . '-event-calendar'),
            'content',
            $textTargets,
            '展示本月活动、排期或预约信息的迷你日历。',
            <<<'HTML'
<section class="{{css_prefix}}-event-calendar">
    <header>
        <div>
            <span>{{eyebrow}}</span>
            <h2>{{title}}</h2>
        </div>
        <strong>{{month}}</strong>
    </header>
    <div class="{{css_prefix}}-event-calendar__week"><span>一</span><span>二</span><span>三</span><span>四</span><span>五</span><span>六</span><span>日</span></div>
    <div class="{{css_prefix}}-event-calendar__grid">
        <span class="is-muted">29</span><span class="is-muted">30</span><span>1</span><span>2</span><span class="has-event">3</span><span>4</span><span>5</span>
        <span>6</span><span class="has-event">7</span><span>8</span><span>9</span><span>10</span><span>11</span><span>12</span>
        <span>13</span><span>14</span><span class="is-today">15</span><span>16</span><span>17</span><span class="has-event">18</span><span>19</span>
        <span>20</span><span>21</span><span>22</span><span>23</span><span>24</span><span>25</span><span>26</span>
        <span>27</span><span>28</span><span>29</span><span>30</span><span>31</span><span class="is-muted">1</span><span class="is-muted">2</span>
    </div>
    <p>{{note}}</p>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-event-calendar{border:1px solid #e5e7eb;border-radius:16px;padding:22px;background:#fff;color:#111827;box-shadow:0 16px 42px rgba(15,23,42,.08)}
.{{css_prefix}}-event-calendar header{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:18px}
.{{css_prefix}}-event-calendar span:first-child{color:#2563eb;font-weight:700}
.{{css_prefix}}-event-calendar h2{margin:4px 0 0;font-size:26px;letter-spacing:0}
.{{css_prefix}}-event-calendar header strong{font-size:20px}
.{{css_prefix}}-event-calendar__week,.{{css_prefix}}-event-calendar__grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;text-align:center}
.{{css_prefix}}-event-calendar__week span{font-size:13px;color:#6b7280!important}
.{{css_prefix}}-event-calendar__grid span{display:grid;place-items:center;aspect-ratio:1;border-radius:10px;background:#f9fafb;color:#1f2937}
.{{css_prefix}}-event-calendar__grid .is-muted{color:#9ca3af;background:#fff}
.{{css_prefix}}-event-calendar__grid .is-today{background:#111827;color:#fff}
.{{css_prefix}}-event-calendar__grid .has-event{background:#dbeafe;color:#1d4ed8;font-weight:800}
.{{css_prefix}}-event-calendar p{margin:16px 0 0;color:#4b5563}
CSS,
            '',
            [
                ['key' => 'eyebrow', 'label' => '眉标', 'type' => 'text', 'default' => '活动日历'],
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '本月重点安排'],
                ['key' => 'month', 'label' => '月份', 'type' => 'text', 'default' => '2026 / 06'],
                ['key' => 'note', 'label' => '提示', 'type' => 'text', 'default' => '蓝色日期代表已有预约或活动。'],
            ],
            20
        ),

        $make(
            'back-to-top',
            '返回顶部按钮',
            ($tagPrefix . '-back-to-top'),
            'utility',
            $textTargets,
            '滚动后浮现的返回顶部按钮，适合长文章、产品详情和模板页。',
            <<<'HTML'
<button class="{{css_prefix}}-back-to-top" type="button" aria-label="{{label}}">↑</button>
HTML,
            <<<'CSS'
.{{css_prefix}}-back-to-top{position:fixed;right:24px;bottom:24px;z-index:60;width:48px;height:48px;border:0;border-radius:50%;background:#111827;color:#fff;font-size:22px;box-shadow:0 18px 36px rgba(15,23,42,.28);cursor:pointer;opacity:0;pointer-events:none;transform:translateY(12px);transition:.2s ease}
.{{css_prefix}}-back-to-top.is-visible{opacity:1;pointer-events:auto;transform:translateY(0)}
.{{css_prefix}}-back-to-top:hover{background:#2563eb}
CSS,
            <<<'JS'
(function(){
    function update(btn){ btn.classList.toggle('is-visible', window.scrollY > 280); }
    document.querySelectorAll('.{{css_prefix}}-back-to-top').forEach(function(btn){
        if (btn.dataset.ready) return;
        btn.dataset.ready = '1';
        update(btn);
        window.addEventListener('scroll', function(){ update(btn); }, {passive:true});
        btn.addEventListener('click', function(){ window.scrollTo({top:0, behavior:'smooth'}); });
    });
})();
JS,
            [
                ['key' => 'label', 'label' => '无障碍标签', 'type' => 'text', 'default' => '返回顶部'],
            ],
            30
        ),

        $make(
            'animated-headline',
            '动画字体',
            ($tagPrefix . '-animated-title'),
            'motion',
            $textTargets,
            '带关键词轮换效果的标题组件，用于活动标题或品牌标语。',
            <<<'HTML'
<section class="{{css_prefix}}-animated-title" data-words="{{words}}">
    <span>{{eyebrow}}</span>
    <h2>{{prefix}}<strong>{{first_word}}</strong>{{suffix}}</h2>
    <p>{{subtitle}}</p>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-animated-title{text-align:center;padding:42px 18px;color:#111827}
.{{css_prefix}}-animated-title span{display:inline-flex;margin-bottom:10px;color:#059669;font-weight:800}
.{{css_prefix}}-animated-title h2{margin:0;font-size:clamp(34px,6vw,72px);line-height:1.05;letter-spacing:0}
.{{css_prefix}}-animated-title strong{display:inline-block;margin:0 .15em;color:#2563eb;transition:.24s ease}
.{{css_prefix}}-animated-title strong.is-changing{transform:translateY(-10px);opacity:0}
.{{css_prefix}}-animated-title p{max-width:620px;margin:18px auto 0;color:#4b5563;font-size:18px;line-height:1.7}
CSS,
            <<<'JS'
(function(){
    document.querySelectorAll('.{{css_prefix}}-animated-title').forEach(function(root){
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        var target = root.querySelector('strong');
        var words = (root.dataset.words || '').split('|').map(function(item){ return item.trim(); }).filter(Boolean);
        if (!target || words.length < 2) return;
        var index = 0;
        setInterval(function(){
            target.classList.add('is-changing');
            setTimeout(function(){
                index = (index + 1) % words.length;
                target.textContent = words[index];
                target.classList.remove('is-changing');
            }, 220);
        }, 2200);
    });
})();
JS,
            [
                ['key' => 'eyebrow', 'label' => '眉标', 'type' => 'text', 'default' => '动态标题'],
                ['key' => 'prefix', 'label' => '前缀', 'type' => 'text', 'default' => '把内容变得'],
                ['key' => 'first_word', 'label' => '初始词', 'type' => 'text', 'default' => '更清晰'],
                ['key' => 'suffix', 'label' => '后缀', 'type' => 'text', 'default' => '。'],
                ['key' => 'words', 'label' => '轮换词', 'type' => 'text', 'default' => '更清晰|更生动|更好维护'],
                ['key' => 'subtitle', 'label' => '描述', 'type' => 'text', 'default' => '适合在页面标题、活动页或专题页中强调核心价值。'],
            ],
            40
        ),

        $make(
            'faq-accordion',
            '问答折叠',
            ($tagPrefix . '-faq-list'),
            'content',
            $textTargets,
            '常见问题折叠列表，减少长页面的阅读负担。',
            <<<'HTML'
<section class="{{css_prefix}}-faq-list">
    <h2>{{title}}</h2>
    <details open><summary>{{q1}}</summary><p>{{a1}}</p></details>
    <details><summary>{{q2}}</summary><p>{{a2}}</p></details>
    <details><summary>{{q3}}</summary><p>{{a3}}</p></details>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-faq-list{max-width:860px;margin:0 auto;color:#111827}
.{{css_prefix}}-faq-list h2{margin:0 0 18px;font-size:30px;letter-spacing:0}
.{{css_prefix}}-faq-list details{border:1px solid #e5e7eb;border-radius:12px;margin-bottom:12px;background:#fff;overflow:hidden}
.{{css_prefix}}-faq-list summary{cursor:pointer;padding:18px 20px;font-weight:800;list-style:none}
.{{css_prefix}}-faq-list summary::-webkit-details-marker{display:none}
.{{css_prefix}}-faq-list details[open] summary{background:#f8fafc}
.{{css_prefix}}-faq-list p{margin:0;padding:0 20px 18px;color:#4b5563;line-height:1.75}
CSS,
            '',
            [
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '常见问题'],
                ['key' => 'q1', 'label' => '问题一', 'type' => 'text', 'default' => '这个组件如何使用？'],
                ['key' => 'a1', 'label' => '回答一', 'type' => 'text', 'default' => '启用后在页面、文章、产品或模板中写入组件标签即可调用。'],
                ['key' => 'q2', 'label' => '问题二', 'type' => 'text', 'default' => '可以传参吗？'],
                ['key' => 'a2', 'label' => '回答二', 'type' => 'text', 'default' => '可以，直接在组件标签上写属性，例如 title、subtitle、id 等。'],
                ['key' => 'q3', 'label' => '问题三', 'type' => 'text', 'default' => '能继续修改源码吗？'],
                ['key' => 'a3', 'label' => '回答三', 'type' => 'text', 'default' => '使用后会生成系统组件，可在系统组件管理中继续编辑。'],
            ],
            50
        ),

        $make(
            'content-tabs',
            '内容标签页',
            ($tagPrefix . '-content-tabs'),
            'content',
            $textTargets,
            '用于展示产品卖点、服务流程或文章分段的切换面板。',
            <<<'HTML'
<section class="{{css_prefix}}-content-tabs">
    <div class="{{css_prefix}}-content-tabs__nav" role="tablist">
        <button type="button" class="is-active">{{tab_1}}</button>
        <button type="button">{{tab_2}}</button>
        <button type="button">{{tab_3}}</button>
    </div>
    <div class="{{css_prefix}}-content-tabs__panel is-active"><h3>{{title_1}}</h3><p>{{text_1}}</p></div>
    <div class="{{css_prefix}}-content-tabs__panel"><h3>{{title_2}}</h3><p>{{text_2}}</p></div>
    <div class="{{css_prefix}}-content-tabs__panel"><h3>{{title_3}}</h3><p>{{text_3}}</p></div>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-content-tabs{border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:18px;color:#111827}
.{{css_prefix}}-content-tabs__nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.{{css_prefix}}-content-tabs__nav button{border:1px solid #d1d5db;border-radius:999px;background:#fff;color:#374151;padding:10px 16px;cursor:pointer;font-weight:700}
.{{css_prefix}}-content-tabs__nav button.is-active{background:#111827;color:#fff;border-color:#111827}
.{{css_prefix}}-content-tabs__panel{display:none;background:#f8fafc;border-radius:12px;padding:22px}
.{{css_prefix}}-content-tabs__panel.is-active{display:block}
.{{css_prefix}}-content-tabs h3{margin:0 0 8px;font-size:24px;letter-spacing:0}
.{{css_prefix}}-content-tabs p{margin:0;color:#4b5563;line-height:1.75}
CSS,
            <<<'JS'
(function(){
    document.querySelectorAll('.{{css_prefix}}-content-tabs').forEach(function(root){
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        var buttons = Array.prototype.slice.call(root.querySelectorAll('.{{css_prefix}}-content-tabs__nav button'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('.{{css_prefix}}-content-tabs__panel'));
        buttons.forEach(function(button, index){
            button.addEventListener('click', function(){
                buttons.forEach(function(item){ item.classList.remove('is-active'); });
                panels.forEach(function(item){ item.classList.remove('is-active'); });
                button.classList.add('is-active');
                if (panels[index]) panels[index].classList.add('is-active');
            });
        });
    });
})();
JS,
            [
                ['key' => 'tab_1', 'label' => '标签一', 'type' => 'text', 'default' => '亮点'],
                ['key' => 'tab_2', 'label' => '标签二', 'type' => 'text', 'default' => '流程'],
                ['key' => 'tab_3', 'label' => '标签三', 'type' => 'text', 'default' => '保障'],
                ['key' => 'title_1', 'label' => '面板一标题', 'type' => 'text', 'default' => '清晰展示核心卖点'],
                ['key' => 'text_1', 'label' => '面板一内容', 'type' => 'text', 'default' => '用简短的切换内容让访客快速理解重点。'],
                ['key' => 'title_2', 'label' => '面板二标题', 'type' => 'text', 'default' => '适合分步说明'],
                ['key' => 'text_2', 'label' => '面板二内容', 'type' => 'text', 'default' => '可用于服务流程、配置差异或文章段落导航。'],
                ['key' => 'title_3', 'label' => '面板三标题', 'type' => 'text', 'default' => '保持页面紧凑'],
                ['key' => 'text_3', 'label' => '面板三内容', 'type' => 'text', 'default' => '比长段落更易扫描，也更适合移动端阅读。'],
            ],
            60
        ),

        $make(
            'notice-bar',
            '公告条',
            ($tagPrefix . '-notice-bar'),
            'marketing',
            $pageTargets,
            '可关闭的顶部公告条，用于活动提醒、系统通知或优惠信息。',
            <<<'HTML'
<div class="{{css_prefix}}-notice-bar" data-key="{{id}}">
    <p>{{text}}</p>
    <a href="{{url}}">{{link_text}}</a>
    <button type="button" aria-label="关闭">×</button>
</div>
HTML,
            <<<'CSS'
.{{css_prefix}}-notice-bar{display:flex;align-items:center;justify-content:center;gap:14px;min-height:46px;padding:8px 48px;background:#111827;color:#fff;position:relative}
.{{css_prefix}}-notice-bar p{margin:0;font-weight:700}
.{{css_prefix}}-notice-bar a{color:#a7f3d0;text-decoration:none;font-weight:800}
.{{css_prefix}}-notice-bar button{position:absolute;right:14px;top:50%;transform:translateY(-50%);width:30px;height:30px;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;cursor:pointer;font-size:20px}
@media(max-width:640px){.{{css_prefix}}-notice-bar{padding:10px 44px 10px 14px;justify-content:flex-start;flex-wrap:wrap}}
CSS,
            <<<'JS'
(function(){
    document.querySelectorAll('.{{css_prefix}}-notice-bar').forEach(function(bar){
        if (bar.dataset.ready) return;
        bar.dataset.ready = '1';
        var key = 'notice_' + (bar.dataset.key || 'default');
        if (localStorage.getItem(key) === '1') { bar.remove(); return; }
        var close = bar.querySelector('button');
        if (!close) return;
        close.addEventListener('click', function(){
            localStorage.setItem(key, '1');
            bar.remove();
        });
    });
})();
JS,
            [
                ['key' => 'id', 'label' => '公告 ID', 'type' => 'text', 'default' => 'spring-sale'],
                ['key' => 'text', 'label' => '公告文字', 'type' => 'text', 'default' => '限时活动进行中，立即查看最新优惠。'],
                ['key' => 'link_text', 'label' => '链接文字', 'type' => 'text', 'default' => '查看详情'],
                ['key' => 'url', 'label' => '链接', 'type' => 'url', 'default' => '#'],
            ],
            70
        ),

        $make(
            'testimonial-wall',
            '客户评价',
            ($tagPrefix . '-testimonial-wall'),
            'marketing',
            $textTargets,
            '三列客户评价墙，适合服务、案例和产品信任背书。',
            <<<'HTML'
<section class="{{css_prefix}}-testimonial-wall">
    <header><span>{{eyebrow}}</span><h2>{{title}}</h2></header>
    <div>
        <figure><blockquote>{{quote_1}}</blockquote><figcaption>{{name_1}}</figcaption></figure>
        <figure><blockquote>{{quote_2}}</blockquote><figcaption>{{name_2}}</figcaption></figure>
        <figure><blockquote>{{quote_3}}</blockquote><figcaption>{{name_3}}</figcaption></figure>
    </div>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-testimonial-wall{color:#111827}
.{{css_prefix}}-testimonial-wall header{text-align:center;margin-bottom:22px}
.{{css_prefix}}-testimonial-wall span{color:#2563eb;font-weight:800}
.{{css_prefix}}-testimonial-wall h2{margin:8px 0 0;font-size:32px;letter-spacing:0}
.{{css_prefix}}-testimonial-wall>div{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.{{css_prefix}}-testimonial-wall figure{margin:0;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:22px;box-shadow:0 14px 36px rgba(15,23,42,.06)}
.{{css_prefix}}-testimonial-wall blockquote{margin:0;color:#374151;line-height:1.75}
.{{css_prefix}}-testimonial-wall figcaption{margin-top:16px;font-weight:800}
@media(max-width:760px){.{{css_prefix}}-testimonial-wall>div{grid-template-columns:1fr}}
CSS,
            '',
            [
                ['key' => 'eyebrow', 'label' => '眉标', 'type' => 'text', 'default' => '真实反馈'],
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '客户正在这样使用'],
                ['key' => 'quote_1', 'label' => '评价一', 'type' => 'text', 'default' => '上线后页面维护明显轻松了很多。'],
                ['key' => 'name_1', 'label' => '署名一', 'type' => 'text', 'default' => '运营负责人'],
                ['key' => 'quote_2', 'label' => '评价二', 'type' => 'text', 'default' => '组件化以后活动页面搭建速度更快。'],
                ['key' => 'name_2', 'label' => '署名二', 'type' => 'text', 'default' => '市场团队'],
                ['key' => 'quote_3', 'label' => '评价三', 'type' => 'text', 'default' => '结构清晰，后续扩展也很顺手。'],
                ['key' => 'name_3', 'label' => '署名三', 'type' => 'text', 'default' => '产品经理'],
            ],
            80
        ),

        $make(
            'pricing-table',
            '价格卡片',
            ($tagPrefix . '-pricing-table'),
            'commerce',
            $textTargets,
            '三档价格方案展示，适合 SaaS、服务包或会员权益。',
            <<<'HTML'
<section class="{{css_prefix}}-pricing-table">
    <article><h3>{{plan_1}}</h3><strong>{{price_1}}</strong><p>{{desc_1}}</p><a href="{{url_1}}">{{button}}</a></article>
    <article class="is-featured"><span>{{badge}}</span><h3>{{plan_2}}</h3><strong>{{price_2}}</strong><p>{{desc_2}}</p><a href="{{url_2}}">{{button}}</a></article>
    <article><h3>{{plan_3}}</h3><strong>{{price_3}}</strong><p>{{desc_3}}</p><a href="{{url_3}}">{{button}}</a></article>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-pricing-table{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;color:#111827}
.{{css_prefix}}-pricing-table article{position:relative;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:24px;box-shadow:0 14px 32px rgba(15,23,42,.06)}
.{{css_prefix}}-pricing-table article.is-featured{border-color:#2563eb;box-shadow:0 22px 44px rgba(37,99,235,.16)}
.{{css_prefix}}-pricing-table h3{margin:0 0 14px;font-size:22px;letter-spacing:0}
.{{css_prefix}}-pricing-table strong{display:block;margin-bottom:12px;font-size:36px}
.{{css_prefix}}-pricing-table p{margin:0 0 22px;color:#4b5563;line-height:1.7}
.{{css_prefix}}-pricing-table a{display:inline-flex;align-items:center;min-height:42px;padding:0 18px;border-radius:999px;background:#111827;color:#fff;text-decoration:none;font-weight:800}
.{{css_prefix}}-pricing-table span{position:absolute;right:18px;top:18px;border-radius:999px;background:#dbeafe;color:#1d4ed8;padding:5px 10px;font-size:12px;font-weight:800}
@media(max-width:780px){.{{css_prefix}}-pricing-table{grid-template-columns:1fr}}
CSS,
            '',
            [
                ['key' => 'plan_1', 'label' => '方案一', 'type' => 'text', 'default' => '基础版'],
                ['key' => 'price_1', 'label' => '价格一', 'type' => 'text', 'default' => '¥99'],
                ['key' => 'desc_1', 'label' => '描述一', 'type' => 'text', 'default' => '适合小团队快速启动。'],
                ['key' => 'plan_2', 'label' => '方案二', 'type' => 'text', 'default' => '专业版'],
                ['key' => 'price_2', 'label' => '价格二', 'type' => 'text', 'default' => '¥299'],
                ['key' => 'desc_2', 'label' => '描述二', 'type' => 'text', 'default' => '适合持续运营和增长团队。'],
                ['key' => 'plan_3', 'label' => '方案三', 'type' => 'text', 'default' => '企业版'],
                ['key' => 'price_3', 'label' => '价格三', 'type' => 'text', 'default' => '联系咨询'],
                ['key' => 'desc_3', 'label' => '描述三', 'type' => 'text', 'default' => '为复杂业务提供定制支持。'],
                ['key' => 'badge', 'label' => '徽标', 'type' => 'text', 'default' => '推荐'],
                ['key' => 'button', 'label' => '按钮', 'type' => 'text', 'default' => '选择方案'],
                ['key' => 'url_1', 'label' => '链接一', 'type' => 'url', 'default' => '#'],
                ['key' => 'url_2', 'label' => '链接二', 'type' => 'url', 'default' => '#'],
                ['key' => 'url_3', 'label' => '链接三', 'type' => 'url', 'default' => '#'],
            ],
            90
        ),

        $make(
            'countdown-timer',
            '倒计时',
            ($tagPrefix . '-countdown-timer'),
            'marketing',
            $textTargets,
            '活动截止、发售上线或报名结束的倒计时模块。',
            <<<'HTML'
<section class="{{css_prefix}}-countdown-timer" data-target="{{target}}">
    <div><span data-days>00</span><small>天</small></div>
    <div><span data-hours>00</span><small>时</small></div>
    <div><span data-minutes>00</span><small>分</small></div>
    <div><span data-seconds>00</span><small>秒</small></div>
    <p>{{text}}</p>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-countdown-timer{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;border-radius:18px;background:#0f172a;color:#fff;padding:28px}
.{{css_prefix}}-countdown-timer div{min-width:84px;border:1px solid rgba(255,255,255,.14);border-radius:14px;background:rgba(255,255,255,.08);padding:14px;text-align:center}
.{{css_prefix}}-countdown-timer span{display:block;font-size:34px;font-weight:900}
.{{css_prefix}}-countdown-timer small{color:#cbd5e1}
.{{css_prefix}}-countdown-timer p{width:100%;margin:8px 0 0;text-align:center;color:#e0f2fe}
CSS,
            <<<'JS'
(function(){
    function pad(num){ return String(num).padStart(2, '0'); }
    document.querySelectorAll('.{{css_prefix}}-countdown-timer').forEach(function(root){
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        var target = new Date(root.dataset.target || '').getTime();
        if (!target) return;
        function tick(){
            var left = Math.max(0, target - Date.now());
            var days = Math.floor(left / 86400000);
            var hours = Math.floor(left % 86400000 / 3600000);
            var minutes = Math.floor(left % 3600000 / 60000);
            var seconds = Math.floor(left % 60000 / 1000);
            root.querySelector('[data-days]').textContent = pad(days);
            root.querySelector('[data-hours]').textContent = pad(hours);
            root.querySelector('[data-minutes]').textContent = pad(minutes);
            root.querySelector('[data-seconds]').textContent = pad(seconds);
        }
        tick();
        setInterval(tick, 1000);
    });
})();
JS,
            [
                ['key' => 'target', 'label' => '截止时间', 'type' => 'datetime', 'default' => '2026-12-31T23:59:59'],
                ['key' => 'text', 'label' => '说明', 'type' => 'text', 'default' => '活动结束倒计时'],
            ],
            100
        ),

        $make(
            'stat-counter',
            '数字增长',
            ($tagPrefix . '-stat-counter'),
            'motion',
            $textTargets,
            '进入视口后数字递增，适合数据成果和品牌实力。',
            <<<'HTML'
<section class="{{css_prefix}}-stat-counter">
    <div><strong data-count="{{num_1}}">0</strong><span>{{label_1}}</span></div>
    <div><strong data-count="{{num_2}}">0</strong><span>{{label_2}}</span></div>
    <div><strong data-count="{{num_3}}">0</strong><span>{{label_3}}</span></div>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-stat-counter{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;color:#111827}
.{{css_prefix}}-stat-counter div{border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:26px;text-align:center}
.{{css_prefix}}-stat-counter strong{display:block;font-size:42px;line-height:1;font-weight:900;color:#2563eb}
.{{css_prefix}}-stat-counter span{display:block;margin-top:10px;color:#4b5563}
@media(max-width:680px){.{{css_prefix}}-stat-counter{grid-template-columns:1fr}}
CSS,
            <<<'JS'
(function(){
    var observer = new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            if (!entry.isIntersecting) return;
            entry.target.querySelectorAll('[data-count]').forEach(function(el){
                if (el.dataset.done) return;
                el.dataset.done = '1';
                var target = parseInt(el.dataset.count || '0', 10);
                var current = 0;
                var step = Math.max(1, Math.ceil(target / 42));
                var timer = setInterval(function(){
                    current = Math.min(target, current + step);
                    el.textContent = current;
                    if (current >= target) clearInterval(timer);
                }, 28);
            });
            observer.unobserve(entry.target);
        });
    }, {threshold:.24});
    document.querySelectorAll('.{{css_prefix}}-stat-counter').forEach(function(root){ observer.observe(root); });
})();
JS,
            [
                ['key' => 'num_1', 'label' => '数字一', 'type' => 'number', 'default' => '120'],
                ['key' => 'label_1', 'label' => '标签一', 'type' => 'text', 'default' => '服务案例'],
                ['key' => 'num_2', 'label' => '数字二', 'type' => 'number', 'default' => '98'],
                ['key' => 'label_2', 'label' => '标签二', 'type' => 'text', 'default' => '满意度'],
                ['key' => 'num_3', 'label' => '数字三', 'type' => 'number', 'default' => '24'],
                ['key' => 'label_3', 'label' => '标签三', 'type' => 'text', 'default' => '小时响应'],
            ],
            110
        ),

        $make(
            'image-compare',
            '图片对比',
            ($tagPrefix . '-image-compare'),
            'media',
            $textTargets,
            '前后效果对比滑杆，适合案例、装修、修图或产品演示。',
            <<<'HTML'
<section class="{{css_prefix}}-image-compare" style="--pos:50%">
    <img src="{{before}}" alt="{{before_alt}}">
    <div><img src="{{after}}" alt="{{after_alt}}"></div>
    <input type="range" min="0" max="100" value="50" aria-label="图片对比">
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-image-compare{position:relative;overflow:hidden;border-radius:16px;aspect-ratio:16/9;background:#e5e7eb}
.{{css_prefix}}-image-compare img{display:block;width:100%;height:100%;object-fit:cover}
.{{css_prefix}}-image-compare>div{position:absolute;inset:0;width:var(--pos);overflow:hidden;border-right:3px solid #fff}
.{{css_prefix}}-image-compare input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:ew-resize}
CSS,
            <<<'JS'
(function(){
    document.querySelectorAll('.{{css_prefix}}-image-compare').forEach(function(root){
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        var input = root.querySelector('input');
        if (!input) return;
        input.addEventListener('input', function(event){
            root.style.setProperty('--pos', event.target.value + '%');
        });
    });
})();
JS,
            [
                ['key' => 'before', 'label' => '对比前图片', 'type' => 'url', 'default' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80'],
                ['key' => 'after', 'label' => '对比后图片', 'type' => 'url', 'default' => 'https://images.unsplash.com/photo-1500534314209-a25ddb2bd429?auto=format&fit=crop&w=1200&q=80'],
                ['key' => 'before_alt', 'label' => '前图说明', 'type' => 'text', 'default' => '处理前'],
                ['key' => 'after_alt', 'label' => '后图说明', 'type' => 'text', 'default' => '处理后'],
            ],
            120
        ),

        $make(
            'history-timeline',
            '时间线',
            ($tagPrefix . '-history-timeline'),
            'content',
            $textTargets,
            '纵向时间线，适合品牌历程、项目进度和文章节点。',
            <<<'HTML'
<section class="{{css_prefix}}-history-timeline">
    <h2>{{title}}</h2>
    <ol>
        <li><time>{{time_1}}</time><strong>{{event_1}}</strong><p>{{desc_1}}</p></li>
        <li><time>{{time_2}}</time><strong>{{event_2}}</strong><p>{{desc_2}}</p></li>
        <li><time>{{time_3}}</time><strong>{{event_3}}</strong><p>{{desc_3}}</p></li>
    </ol>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-history-timeline{color:#111827}
.{{css_prefix}}-history-timeline h2{margin:0 0 18px;font-size:30px;letter-spacing:0}
.{{css_prefix}}-history-timeline ol{position:relative;margin:0;padding:0 0 0 24px;list-style:none}
.{{css_prefix}}-history-timeline ol:before{content:"";position:absolute;left:6px;top:8px;bottom:8px;width:2px;background:#dbeafe}
.{{css_prefix}}-history-timeline li{position:relative;margin-bottom:18px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;padding:18px}
.{{css_prefix}}-history-timeline li:before{content:"";position:absolute;left:-24px;top:22px;width:12px;height:12px;border-radius:50%;background:#2563eb}
.{{css_prefix}}-history-timeline time{display:block;color:#2563eb;font-weight:800;margin-bottom:6px}
.{{css_prefix}}-history-timeline strong{display:block;font-size:20px}
.{{css_prefix}}-history-timeline p{margin:8px 0 0;color:#4b5563;line-height:1.7}
CSS,
            '',
            [
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '发展时间线'],
                ['key' => 'time_1', 'label' => '时间一', 'type' => 'text', 'default' => '2024'],
                ['key' => 'event_1', 'label' => '事件一', 'type' => 'text', 'default' => '项目启动'],
                ['key' => 'desc_1', 'label' => '描述一', 'type' => 'text', 'default' => '完成基础架构与内容体系建设。'],
                ['key' => 'time_2', 'label' => '时间二', 'type' => 'text', 'default' => '2025'],
                ['key' => 'event_2', 'label' => '事件二', 'type' => 'text', 'default' => '业务扩展'],
                ['key' => 'desc_2', 'label' => '描述二', 'type' => 'text', 'default' => '覆盖更多应用场景并提升运营效率。'],
                ['key' => 'time_3', 'label' => '时间三', 'type' => 'text', 'default' => '2026'],
                ['key' => 'event_3', 'label' => '事件三', 'type' => 'text', 'default' => '持续优化'],
                ['key' => 'desc_3', 'label' => '描述三', 'type' => 'text', 'default' => '围绕用户体验和可维护性持续迭代。'],
            ],
            130
        ),

        $make(
            'modal-cta',
            '弹窗行动',
            ($tagPrefix . '-modal-cta'),
            'conversion',
            $textTargets,
            '点击按钮打开轻量弹窗，适合咨询、留资和活动说明。',
            <<<'HTML'
<section class="{{css_prefix}}-modal-cta">
    <button type="button">{{button}}</button>
    <dialog>
        <form method="dialog"><button aria-label="关闭">×</button></form>
        <h2>{{title}}</h2>
        <p>{{text}}</p>
        <a href="{{url}}">{{link_text}}</a>
    </dialog>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-modal-cta{text-align:center}
.{{css_prefix}}-modal-cta>button,.{{css_prefix}}-modal-cta a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 20px;border-radius:999px;border:0;background:#2563eb;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}
.{{css_prefix}}-modal-cta dialog{width:min(520px,calc(100% - 32px));border:0;border-radius:18px;padding:30px;color:#111827;box-shadow:0 24px 80px rgba(15,23,42,.35)}
.{{css_prefix}}-modal-cta dialog::backdrop{background:rgba(15,23,42,.58)}
.{{css_prefix}}-modal-cta form button{position:absolute;right:16px;top:14px;border:0;background:transparent;font-size:26px;cursor:pointer}
.{{css_prefix}}-modal-cta h2{margin:0 0 12px;font-size:28px;letter-spacing:0}
.{{css_prefix}}-modal-cta p{margin:0 0 22px;color:#4b5563;line-height:1.7}
CSS,
            <<<'JS'
(function(){
    document.querySelectorAll('.{{css_prefix}}-modal-cta').forEach(function(root){
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        var dialog = root.querySelector('dialog');
        var trigger = root.querySelector('button');
        if (trigger) {
            trigger.addEventListener('click', function(){ if (dialog && dialog.showModal) dialog.showModal(); });
        }
    });
})();
JS,
            [
                ['key' => 'button', 'label' => '按钮', 'type' => 'text', 'default' => '打开弹窗'],
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '立即获取方案'],
                ['key' => 'text', 'label' => '内容', 'type' => 'text', 'default' => '留下信息或进入详情页，让用户继续完成关键动作。'],
                ['key' => 'link_text', 'label' => '链接文字', 'type' => 'text', 'default' => '去了解'],
                ['key' => 'url', 'label' => '链接', 'type' => 'url', 'default' => '#'],
            ],
            140
        ),

        $make(
            'sticky-contact',
            '悬浮联系',
            ($tagPrefix . '-sticky-contact'),
            'conversion',
            $pageTargets,
            '页面侧边悬浮联系入口，可用于电话、微信、在线咨询。',
            <<<'HTML'
<aside class="{{css_prefix}}-sticky-contact">
    <a href="{{primary_url}}">{{primary_text}}</a>
    <a href="{{secondary_url}}">{{secondary_text}}</a>
</aside>
HTML,
            <<<'CSS'
.{{css_prefix}}-sticky-contact{position:fixed;right:18px;top:50%;transform:translateY(-50%);z-index:55;display:grid;gap:10px}
.{{css_prefix}}-sticky-contact a{display:flex;align-items:center;justify-content:center;width:92px;min-height:42px;border-radius:999px;background:#111827;color:#fff;text-decoration:none;font-weight:800;box-shadow:0 14px 30px rgba(15,23,42,.2)}
.{{css_prefix}}-sticky-contact a:first-child{background:#059669}
@media(max-width:700px){.{{css_prefix}}-sticky-contact{left:12px;right:12px;top:auto;bottom:12px;transform:none;grid-template-columns:1fr 1fr}.{{css_prefix}}-sticky-contact a{width:auto}}
CSS,
            '',
            [
                ['key' => 'primary_text', 'label' => '主按钮', 'type' => 'text', 'default' => '电话咨询'],
                ['key' => 'primary_url', 'label' => '主链接', 'type' => 'url', 'default' => 'tel:4000000000'],
                ['key' => 'secondary_text', 'label' => '次按钮', 'type' => 'text', 'default' => '在线留言'],
                ['key' => 'secondary_url', 'label' => '次链接', 'type' => 'url', 'default' => '#contact'],
            ],
            150
        ),

        $make(
            'feature-grid',
            '功能网格',
            ($tagPrefix . '-feature-grid'),
            'content',
            $textTargets,
            '四宫格功能亮点，用于产品能力、服务范围或页面摘要。',
            <<<'HTML'
<section class="{{css_prefix}}-feature-grid">
    <header><span>{{eyebrow}}</span><h2>{{title}}</h2></header>
    <div>
        <article><strong>{{icon_1}}</strong><h3>{{item_1}}</h3><p>{{desc_1}}</p></article>
        <article><strong>{{icon_2}}</strong><h3>{{item_2}}</h3><p>{{desc_2}}</p></article>
        <article><strong>{{icon_3}}</strong><h3>{{item_3}}</h3><p>{{desc_3}}</p></article>
        <article><strong>{{icon_4}}</strong><h3>{{item_4}}</h3><p>{{desc_4}}</p></article>
    </div>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-feature-grid{color:#111827}
.{{css_prefix}}-feature-grid header{text-align:center;margin-bottom:24px}
.{{css_prefix}}-feature-grid span{color:#059669;font-weight:800}
.{{css_prefix}}-feature-grid h2{margin:8px 0 0;font-size:32px;letter-spacing:0}
.{{css_prefix}}-feature-grid>div{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.{{css_prefix}}-feature-grid article{border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:22px}
.{{css_prefix}}-feature-grid strong{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:#ecfdf5;margin-bottom:14px}
.{{css_prefix}}-feature-grid h3{margin:0 0 8px;font-size:20px}
.{{css_prefix}}-feature-grid p{margin:0;color:#4b5563;line-height:1.7}
@media(max-width:920px){.{{css_prefix}}-feature-grid>div{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.{{css_prefix}}-feature-grid>div{grid-template-columns:1fr}}
CSS,
            '',
            [
                ['key' => 'eyebrow', 'label' => '眉标', 'type' => 'text', 'default' => '核心能力'],
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '用组件快速组织页面'],
                ['key' => 'icon_1', 'label' => '图标一', 'type' => 'text', 'default' => '01'],
                ['key' => 'item_1', 'label' => '条目一', 'type' => 'text', 'default' => '易复用'],
                ['key' => 'desc_1', 'label' => '描述一', 'type' => 'text', 'default' => '一次启用，多处调用。'],
                ['key' => 'icon_2', 'label' => '图标二', 'type' => 'text', 'default' => '02'],
                ['key' => 'item_2', 'label' => '条目二', 'type' => 'text', 'default' => '可传参'],
                ['key' => 'desc_2', 'label' => '描述二', 'type' => 'text', 'default' => '属性直接进入模板上下文。'],
                ['key' => 'icon_3', 'label' => '图标三', 'type' => 'text', 'default' => '03'],
                ['key' => 'item_3', 'label' => '条目三', 'type' => 'text', 'default' => '可维护'],
                ['key' => 'desc_3', 'label' => '描述三', 'type' => 'text', 'default' => '源码集中管理，页面更干净。'],
                ['key' => 'icon_4', 'label' => '图标四', 'type' => 'text', 'default' => '04'],
                ['key' => 'item_4', 'label' => '条目四', 'type' => 'text', 'default' => '可扩展'],
                ['key' => 'desc_4', 'label' => '描述四', 'type' => 'text', 'default' => '后续可继续沉淀更多组件。'],
            ],
            160
        ),

        $make(
            'breadcrumbs',
            '面包屑',
            ($tagPrefix . '-breadcrumbs'),
            'utility',
            $textTargets,
            '页面路径导航，帮助用户理解当前位置。',
            <<<'HTML'
<nav class="{{css_prefix}}-breadcrumbs" aria-label="面包屑">
    <a href="{{home_href}}">{{home}}</a>
    <span>/</span>
    <a href="{{parent_href}}">{{parent}}</a>
    <span>/</span>
    <strong>{{current}}</strong>
</nav>
HTML,
            <<<'CSS'
.{{css_prefix}}-breadcrumbs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#6b7280;font-size:14px}
.{{css_prefix}}-breadcrumbs a{color:#2563eb;text-decoration:none;font-weight:700}
.{{css_prefix}}-breadcrumbs strong{color:#111827;font-weight:800}
CSS,
            '',
            [
                ['key' => 'home', 'label' => '首页文字', 'type' => 'text', 'default' => '首页'],
                ['key' => 'home_href', 'label' => '首页链接', 'type' => 'url', 'default' => '/'],
                ['key' => 'parent', 'label' => '父级文字', 'type' => 'text', 'default' => '栏目'],
                ['key' => 'parent_href', 'label' => '父级链接', 'type' => 'url', 'default' => '#'],
                ['key' => 'current', 'label' => '当前页', 'type' => 'text', 'default' => '当前内容'],
            ],
            170
        ),

        $make(
            'progress-steps',
            '流程步骤',
            ($tagPrefix . '-progress-steps'),
            'content',
            $textTargets,
            '横向流程步骤条，适合服务流程、下单流程或项目阶段。',
            <<<'HTML'
<section class="{{css_prefix}}-progress-steps">
    <article><span>1</span><h3>{{step_1}}</h3><p>{{desc_1}}</p></article>
    <article><span>2</span><h3>{{step_2}}</h3><p>{{desc_2}}</p></article>
    <article><span>3</span><h3>{{step_3}}</h3><p>{{desc_3}}</p></article>
    <article><span>4</span><h3>{{step_4}}</h3><p>{{desc_4}}</p></article>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-progress-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;color:#111827}
.{{css_prefix}}-progress-steps article{position:relative;border:1px solid #e5e7eb;border-radius:16px;background:#fff;padding:22px}
.{{css_prefix}}-progress-steps span{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;background:#2563eb;color:#fff;font-weight:900;margin-bottom:14px}
.{{css_prefix}}-progress-steps h3{margin:0 0 8px;font-size:20px}
.{{css_prefix}}-progress-steps p{margin:0;color:#4b5563;line-height:1.7}
@media(max-width:860px){.{{css_prefix}}-progress-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.{{css_prefix}}-progress-steps{grid-template-columns:1fr}}
CSS,
            '',
            [
                ['key' => 'step_1', 'label' => '步骤一', 'type' => 'text', 'default' => '沟通需求'],
                ['key' => 'desc_1', 'label' => '描述一', 'type' => 'text', 'default' => '确认目标、范围和上线节奏。'],
                ['key' => 'step_2', 'label' => '步骤二', 'type' => 'text', 'default' => '配置内容'],
                ['key' => 'desc_2', 'label' => '描述二', 'type' => 'text', 'default' => '选择组件并调整参数。'],
                ['key' => 'step_3', 'label' => '步骤三', 'type' => 'text', 'default' => '预览检查'],
                ['key' => 'desc_3', 'label' => '描述三', 'type' => 'text', 'default' => '在实际页面中确认视觉效果。'],
                ['key' => 'step_4', 'label' => '步骤四', 'type' => 'text', 'default' => '发布上线'],
                ['key' => 'desc_4', 'label' => '描述四', 'type' => 'text', 'default' => '完成发布并持续优化。'],
            ],
            180
        ),

        $make(
            'logo-marquee',
            '品牌横幅',
            ($tagPrefix . '-logo-marquee'),
            'motion',
            $textTargets,
            '水平滚动品牌、关键词或合作伙伴列表。',
            <<<'HTML'
<section class="{{css_prefix}}-logo-marquee" aria-label="{{label}}">
    <div>
        <span>{{item_1}}</span><span>{{item_2}}</span><span>{{item_3}}</span><span>{{item_4}}</span><span>{{item_5}}</span>
        <span>{{item_1}}</span><span>{{item_2}}</span><span>{{item_3}}</span><span>{{item_4}}</span><span>{{item_5}}</span>
    </div>
</section>
HTML,
            <<<'CSS'
.{{css_prefix}}-logo-marquee{overflow:hidden;border-block:1px solid #e5e7eb;background:#fff;color:#111827}
.{{css_prefix}}-logo-marquee div{display:flex;width:max-content;gap:34px;padding:18px 0;animation:{{css_prefix}}LogoMarquee 24s linear infinite}
.{{css_prefix}}-logo-marquee span{font-size:22px;font-weight:900;white-space:nowrap;color:#374151}
@keyframes {{css_prefix}}LogoMarquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}
CSS,
            '',
            [
                ['key' => 'label', 'label' => '标签', 'type' => 'text', 'default' => '合作品牌'],
                ['key' => 'item_1', 'label' => '条目一', 'type' => 'text', 'default' => '品牌 A'],
                ['key' => 'item_2', 'label' => '条目二', 'type' => 'text', 'default' => '品牌 B'],
                ['key' => 'item_3', 'label' => '条目三', 'type' => 'text', 'default' => '品牌 C'],
                ['key' => 'item_4', 'label' => '条目四', 'type' => 'text', 'default' => '品牌 D'],
                ['key' => 'item_5', 'label' => '条目五', 'type' => 'text', 'default' => '品牌 E'],
            ],
            190
        ),

        $make(
            'newsletter-box',
            '订阅表单',
            ($tagPrefix . '-newsletter-box'),
            'conversion',
            $textTargets,
            '邮件订阅或线索收集入口，可接入站内表单地址。',
            <<<'HTML'
<form class="{{css_prefix}}-newsletter-box" action="{{action}}" method="post">
    <div>
        <span>{{eyebrow}}</span>
        <h2>{{title}}</h2>
        <p>{{text}}</p>
    </div>
    <label>
        <span>{{label}}</span>
        <input type="email" name="{{field}}" placeholder="{{placeholder}}" required>
    </label>
    <button type="submit">{{button}}</button>
</form>
HTML,
            <<<'CSS'
.{{css_prefix}}-newsletter-box{display:grid;grid-template-columns:1.5fr 1fr auto;align-items:end;gap:14px;border-radius:18px;background:#111827;color:#fff;padding:26px}
.{{css_prefix}}-newsletter-box span:first-child{color:#a7f3d0;font-weight:800}
.{{css_prefix}}-newsletter-box h2{margin:6px 0 8px;font-size:28px;letter-spacing:0}
.{{css_prefix}}-newsletter-box p{margin:0;color:#cbd5e1;line-height:1.7}
.{{css_prefix}}-newsletter-box label span{display:block;margin-bottom:8px;color:#e5e7eb;font-size:14px}
.{{css_prefix}}-newsletter-box input{width:100%;height:46px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:#fff;color:#111827;padding:0 14px}
.{{css_prefix}}-newsletter-box button{height:46px;border:0;border-radius:12px;background:#22c55e;color:#052e16;padding:0 20px;font-weight:900;cursor:pointer}
@media(max-width:780px){.{{css_prefix}}-newsletter-box{grid-template-columns:1fr}}
CSS,
            '',
            [
                ['key' => 'action', 'label' => '提交地址', 'type' => 'url', 'default' => '#'],
                ['key' => 'eyebrow', 'label' => '眉标', 'type' => 'text', 'default' => '订阅更新'],
                ['key' => 'title', 'label' => '标题', 'type' => 'text', 'default' => '获取最新内容'],
                ['key' => 'text', 'label' => '说明', 'type' => 'text', 'default' => '把新品、活动或文章更新发送给感兴趣的用户。'],
                ['key' => 'label', 'label' => '输入框标签', 'type' => 'text', 'default' => '邮箱地址'],
                ['key' => 'field', 'label' => '字段名', 'type' => 'text', 'default' => 'email'],
                ['key' => 'placeholder', 'label' => '占位文字', 'type' => 'text', 'default' => 'name@example.com'],
                ['key' => 'button', 'label' => '按钮', 'type' => 'text', 'default' => '订阅'],
            ],
            200
        ),
    ];
})();
