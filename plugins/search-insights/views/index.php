<?php
$this->extend('admin/views/layouts/main');
$this->section('title', '搜索数据中心');
$this->startSection('content');
$connections = is_array($status['connections'] ?? null) ? $status['connections'] : [];
$metrics = is_array($report['data'] ?? null) ? $report['data'] : [];
$geoRows = is_array($geo['data'] ?? null) ? $geo['data'] : [];
$merchantRows = is_array($merchant['data'] ?? null) ? $merchant['data'] : [];
$merchantSummary = is_array($merchant['summary'] ?? null) ? $merchant['summary'] : [];
$inspectionRows = is_array($inspections['data'] ?? null) ? $inspections['data'] : [];
$freshness = is_array($status['freshness'] ?? null) ? $status['freshness'] : [];
$google = is_array($connections['google'] ?? null) ? $connections['google'] : [];
$psi = is_array($connections['pagespeed'] ?? null) ? $connections['pagespeed'] : [];
?>

<div class="d-flex align-items-center justify-content-between gap-3 mb-3">
    <div>
        <h4 class="mb-1">搜索数据中心</h4>
        <div class="text-muted small">搜索表现、索引状态、体验指标与答案引擎引用</div>
    </div>
    <?php if ($canSync): ?>
    <form method="post" action="<?= admin_url('/search-insights/sync') ?>" class="d-flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="provider" value="google">
        <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat"></i> 同步 Google</button>
    </form>
    <?php endif; ?>
</div>

<section class="mb-4" aria-labelledby="searchOverviewTitle">
    <h5 id="searchOverviewTitle" class="mb-3">数据状态</h5>
    <div class="row g-3">
        <?php foreach (['google' => 'Google', 'pagespeed' => 'PageSpeed'] as $provider => $label): ?>
            <?php $row = is_array($connections[$provider] ?? null) ? $connections[$provider] : []; ?>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong><?= e($label) ?></strong>
                        <?= dg_badge(!empty($row['connected']) ? '已连接' : (!empty($row['configured']) ? '待授权' : '未配置'), !empty($row['connected']) ? 'success' : 'neutral') ?>
                    </div>
                    <div class="small text-muted text-truncate"><?= e((string)($row['site_url'] ?? '') ?: '-') ?></div>
                    <div class="small mt-2">最近同步：<?= e((string)($row['last_synced_at'] ?? '') ?: '-') ?></div>
                    <?php if ($provider === 'google' && !empty($row['merchant_account_id'])): ?>
                    <div class="small mt-1">Merchant：<?= e((string)$row['merchant_account_id']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="d-flex align-items-center justify-content-between mb-2"><strong>Bing</strong><?= dg_badge('规范化导入', 'info') ?></div>
                <div class="small text-muted">Webmaster 与 AI Performance 数据通过受控 API 导入，不调用已退役的旧接口。</div>
            </div>
        </div>
    </div>
    <?php if ($freshness): ?>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <?php foreach ($freshness as $row): ?>
            <span class="badge text-bg-light border"><?= e(strtoupper((string)$row['provider'])) ?> · <?= e((string)$row['metric_date']) ?> · <?= (int)$row['rows'] ?> 条</span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="searchMetricsTitle">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h5 id="searchMetricsTitle" class="mb-0">搜索与访问指标</h5>
        <span class="text-muted small"><?= (int)($report['total'] ?? 0) ?> 条</span>
    </div>
    <form method="get" action="<?= admin_url('/search-insights') ?>" class="row g-2 mb-3">
        <div class="col-md-2"><select class="form-select" name="provider">
            <?php foreach (['' => '所有平台', 'gsc' => 'GSC', 'ga4' => 'GA4', 'bing' => 'Bing', 'bing_ai' => 'Bing AI', 'manual' => '人工导入'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= (string)($filters['provider'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-md-2"><input class="form-control" type="date" name="date_from" value="<?= e((string)($filters['date_from'] ?? '')) ?>"></div>
        <div class="col-md-2"><input class="form-control" type="date" name="date_to" value="<?= e((string)($filters['date_to'] ?? '')) ?>"></div>
        <div class="col-md-4"><input class="form-control" name="q" value="<?= e((string)($filters['q'] ?? '')) ?>" placeholder="查询词或 URL"></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-funnel"></i> 筛选</button></div>
    </form>
    <?php
    echo data_grid([
        'id' => 'search-insights-metrics-grid',
        'compact' => true,
        'columns' => [
            ['key' => 'metric_date', 'label' => '日期', 'width' => '110px'],
            ['key' => 'provider', 'label' => '平台', 'width' => '90px', 'cell' => static fn($r) => dg_badge(strtoupper((string)$r['provider']), 'info')],
            ['key' => 'dimension_key', 'label' => '查询词 / URL', 'cell' => static fn($r) => '<span class="dg-primary">' . e((string)$r['dimension_key']) . '</span><small class="dg-secondary">' . e((string)$r['dimension']) . '</small>'],
            ['key' => 'clicks', 'label' => '点击', 'align' => 'end'],
            ['key' => 'impressions', 'label' => '曝光', 'align' => 'end'],
            ['key' => 'avg_position', 'label' => '排名', 'align' => 'end'],
            ['key' => 'pageviews', 'label' => '浏览', 'align' => 'end'],
            ['key' => 'conversions', 'label' => '转化', 'align' => 'end'],
            ['key' => 'citations', 'label' => '引用', 'align' => 'end'],
        ],
        'rows' => $metrics,
        'rowKey' => 'id',
        'empty' => ['title' => '暂无指标数据', 'description' => ''],
        'pagination' => [
            'page' => (int)($report['page'] ?? 1),
            'lastPage' => (int)($report['last_page'] ?? 1),
            'total' => (int)($report['total'] ?? 0),
        ],
    ]);
    ?>
</section>

<?php if ($canSync): ?>
<section id="operations" class="mb-4" aria-labelledby="searchOperationsTitle">
    <h5 id="searchOperationsTitle" class="mb-3">索引与数据操作</h5>
    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" action="<?= admin_url('/search-insights/inspect-url') ?>" class="border rounded p-3 mb-3">
                <?= csrf_field() ?>
                <strong class="d-block mb-3">Google URL Inspection</strong>
                <div class="row g-2">
                    <div class="col-md-8"><input type="url" class="form-control" name="url" required placeholder="<?= e(url('/')) ?>"></div>
                    <div class="col-md-4"><input class="form-control" name="language_code" value="zh-CN" pattern="[a-z]{2,3}(-[A-Z]{2})?"></div>
                </div>
                <button class="btn btn-outline-primary mt-3" type="submit"><i class="bi bi-search"></i> 检查索引</button>
            </form>
            <form method="post" action="<?= admin_url('/search-insights/submit-sitemap') ?>" class="border rounded p-3">
                <?= csrf_field() ?>
                <strong class="d-block mb-3">提交 Sitemap</strong>
                <input type="url" class="form-control" name="sitemap_url" required value="<?= e(url('/sitemap.xml')) ?>">
                <button class="btn btn-outline-primary mt-3" type="submit"><i class="bi bi-send"></i> 提交到 Google</button>
            </form>
        </div>
        <div class="col-lg-6">
            <form method="post" action="<?= admin_url('/search-insights/metric-import') ?>" class="border rounded p-3">
                <?= csrf_field() ?>
                <strong class="d-block mb-3">规范化指标导入</strong>
                <div class="row g-2">
                    <div class="col-md-4"><select class="form-select" name="provider"><option value="bing">Bing</option><option value="bing_ai">Bing AI</option><option value="manual">其他</option></select></div>
                    <div class="col-md-4"><input type="date" class="form-control" name="metric_date" required value="<?= e(date('Y-m-d')) ?>"></div>
                    <div class="col-md-4"><select class="form-select" name="dimension"><option value="query">查询词</option><option value="page">URL</option><option value="prompt">AI Prompt</option></select></div>
                    <div class="col-12"><input class="form-control" name="dimension_key" required maxlength="2000" placeholder="查询词、URL 或 Prompt"></div>
                    <?php foreach (['clicks' => '点击', 'impressions' => '曝光', 'position' => '平均排名', 'citations' => 'AI 引用'] as $name => $label): ?>
                    <div class="col-md-3"><label class="form-label small"><?= e($label) ?></label><input type="number" min="0" step="0.0001" class="form-control" name="<?= e($name) ?>" value="0"></div>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-outline-primary mt-3" type="submit"><i class="bi bi-upload"></i> 导入</button>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>

<section id="merchant" class="mb-4" aria-labelledby="merchantTitle">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h5 id="merchantTitle" class="mb-0">Merchant Center 诊断</h5>
        <span class="text-muted small"><?= (int)($merchant['total'] ?? 0) ?> 个当前问题</span>
    </div>
    <?php if ($canSync): ?>
    <form method="post" action="<?= admin_url('/search-insights/merchant-sync') ?>" class="row g-2 mb-3">
        <?= csrf_field() ?>
        <div class="col-md-3">
            <label class="form-label small">诊断语言</label>
            <input class="form-control" name="language_code" value="zh-CN" pattern="[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}">
        </div>
        <div class="col-md-3">
            <label class="form-label small">本批商品上限</label>
            <select class="form-select" name="max_products"><option value="25">25</option><option value="50">50</option><option value="100" selected>100</option></select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-arrow-repeat"></i> 同步诊断</button>
        </div>
    </form>
    <?php endif; ?>
    <form method="get" action="<?= admin_url('/search-insights') ?>" class="row g-2 mb-3">
        <input type="hidden" name="merchant_page" value="1">
        <div class="col-md-3"><select class="form-select" name="merchant_scope"><option value="">全部范围</option><option value="account" <?= (string)($merchantFilters['scope'] ?? '') === 'account' ? 'selected' : '' ?>>账户</option><option value="product" <?= (string)($merchantFilters['scope'] ?? '') === 'product' ? 'selected' : '' ?>>商品</option></select></div>
        <div class="col-md-3"><input class="form-control" name="merchant_severity" value="<?= e((string)($merchantFilters['severity'] ?? '')) ?>" placeholder="严重度，如 CRITICAL"></div>
        <div class="col-md-4"><input class="form-control" name="merchant_q" value="<?= e((string)($merchantFilters['q'] ?? '')) ?>" placeholder="Offer ID、问题代码或标题"></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-funnel"></i> 筛选</button></div>
    </form>
    <?php if ($merchantSummary): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($merchantSummary as $row): ?>
        <span class="badge text-bg-light border"><?= e((string)$row['scope']) ?> · <?= e((string)$row['severity']) ?> · <?= (int)$row['issues'] ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    echo data_grid([
        'id' => 'search-insights-merchant-grid', 'compact' => true,
        'columns' => [
            ['key' => 'scope', 'label' => '范围', 'width' => '90px', 'cell' => static fn($r) => dg_badge((string)$r['scope'], 'info')],
            ['key' => 'subject_key', 'label' => '账户 / Offer ID', 'width' => '160px'],
            ['key' => 'title', 'label' => '问题', 'cell' => static function ($r) {
                $html = '<span class="dg-primary">' . e((string)$r['title']) . '</span>'
                    . '<small class="dg-secondary">' . e((string)$r['issue_code']) . '</small>';
                if (!empty($r['documentation_url'])) {
                    $html .= '<a class="small" href="' . e((string)$r['documentation_url']) . '" target="_blank" rel="noopener noreferrer">查看修复文档</a>';
                }
                return $html;
            }],
            ['key' => 'severity', 'label' => '严重度', 'width' => '130px', 'cell' => static fn($r) => dg_badge((string)$r['severity'], in_array((string)$r['severity'], ['CRITICAL', 'DISAPPROVED', 'ERROR'], true) ? 'danger' : 'warning')],
            ['key' => 'reporting_context', 'label' => '投放范围', 'cell' => static fn($r) => e((string)($r['reporting_context'] ?? '-')) . '<small class="dg-secondary">' . e(implode(', ', (array)($r['countries'] ?? []))) . '</small>'],
            ['key' => 'last_seen_at', 'label' => '最近发现', 'width' => '170px'],
        ],
        'rows' => $merchantRows, 'rowKey' => 'id',
        'pagination' => [
            'page' => (int)($merchant['page'] ?? 1), 'lastPage' => (int)($merchant['last_page'] ?? 1), 'total' => (int)($merchant['total'] ?? 0),
            'url' => static function (int $page) use ($merchantFilters): string {
                $query = ['merchant_scope' => $merchantFilters['scope'] ?? '', 'merchant_severity' => $merchantFilters['severity'] ?? '', 'merchant_q' => $merchantFilters['q'] ?? '', 'merchant_page' => $page];
                return admin_url('/search-insights') . '?' . http_build_query(array_filter($query, static fn($v) => $v !== '')) . '#merchant';
            },
        ],
        'empty' => ['title' => '暂无 Merchant 诊断', 'description' => ''],
    ]);
    ?>
</section>

<section id="inspections" class="mb-4" aria-labelledby="inspectionsTitle">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
        <h5 id="inspectionsTitle" class="mb-0">Google URL Inspection 历史</h5>
        <span class="text-muted small"><?= (int)($inspections['total'] ?? 0) ?> 条</span>
    </div>
    <form method="get" action="<?= admin_url('/search-insights') ?>" class="row g-2 mb-3">
        <input type="hidden" name="inspection_page" value="1">
        <div class="col-md-5"><input class="form-control" name="inspection_q" value="<?= e((string)($inspectionFilters['q'] ?? '')) ?>" placeholder="URL、覆盖状态或 Google canonical"></div>
        <div class="col-md-4"><input class="form-control" name="inspection_verdict" value="<?= e((string)($inspectionFilters['verdict'] ?? '')) ?>" placeholder="Verdict"></div>
        <div class="col-md-3"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-funnel"></i> 筛选</button></div>
    </form>
    <?php
    echo data_grid([
        'id' => 'search-insights-inspections-grid', 'compact' => true,
        'columns' => [
            ['key' => 'url', 'label' => 'URL', 'cell' => static fn($r) => '<span class="dg-primary">' . e((string)$r['url']) . '</span><small class="dg-secondary">' . e((string)($r['inspected_at'] ?? '')) . '</small>'],
            ['key' => 'verdict', 'label' => 'Verdict', 'width' => '150px'],
            ['key' => 'coverage_state', 'label' => '覆盖状态'],
            ['key' => 'robots_state', 'label' => 'Robots'],
            ['key' => 'indexing_state', 'label' => '索引状态'],
            ['key' => 'google_canonical', 'label' => 'Google canonical', 'cell' => static fn($r) => e((string)($r['google_canonical'] ?? '-'))],
        ],
        'rows' => $inspectionRows, 'rowKey' => 'id',
        'pagination' => [
            'page' => (int)($inspections['page'] ?? 1), 'lastPage' => (int)($inspections['last_page'] ?? 1), 'total' => (int)($inspections['total'] ?? 0),
            'url' => static function (int $page) use ($inspectionFilters): string {
                $query = ['inspection_q' => $inspectionFilters['q'] ?? '', 'inspection_verdict' => $inspectionFilters['verdict'] ?? '', 'inspection_page' => $page];
                return admin_url('/search-insights') . '?' . http_build_query(array_filter($query, static fn($v) => $v !== '')) . '#inspections';
            },
        ],
        'empty' => ['title' => '暂无 URL Inspection 记录', 'description' => ''],
    ]);
    ?>
</section>

<section id="pagespeed" class="mb-4" aria-labelledby="pageSpeedTitle">
    <h5 id="pageSpeedTitle" class="mb-3">页面体验</h5>
    <?php if ($canSync): ?>
    <form method="post" action="<?= admin_url('/search-insights/pagespeed') ?>" class="row g-2 mb-3">
        <?= csrf_field() ?>
        <div class="col-md-7"><input type="url" name="url" class="form-control" required placeholder="<?= e(url('/')) ?>"></div>
        <div class="col-md-2"><select name="strategy" class="form-select"><option value="mobile">Mobile</option><option value="desktop">Desktop</option></select></div>
        <div class="col-md-3"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-speedometer2"></i> 检查</button></div>
    </form>
    <?php endif; ?>
    <?php
    echo data_grid([
        'id' => 'search-insights-pagespeed-grid', 'compact' => true,
        'columns' => [
            ['key' => 'url', 'label' => 'URL', 'cell' => static fn($r) => '<span class="dg-primary">' . e((string)$r['url']) . '</span><small class="dg-secondary">' . e((string)$r['strategy']) . '</small>'],
            ['key' => 'performance_score', 'label' => '性能', 'align' => 'end'],
            ['key' => 'accessibility_score', 'label' => '可访问性', 'align' => 'end'],
            ['key' => 'seo_score', 'label' => 'SEO', 'align' => 'end'],
            ['key' => 'lcp_ms', 'label' => 'LCP ms', 'align' => 'end'],
            ['key' => 'cls', 'label' => 'CLS', 'align' => 'end'],
            ['key' => 'checked_at', 'label' => '检查时间', 'width' => '170px'],
        ],
        'rows' => $pageSpeed, 'rowKey' => 'id',
        'empty' => ['title' => '暂无 PageSpeed 结果', 'description' => ''],
    ]);
    ?>
</section>

<section id="geo" class="mb-4" aria-labelledby="geoTitle">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 id="geoTitle" class="mb-0">GEO 引用观测</h5>
        <span class="text-muted small"><?= (int)($geo['total'] ?? 0) ?> 条</span>
    </div>
    <?php if ($canSync): ?>
    <form method="post" action="<?= admin_url('/search-insights/geo') ?>" class="row g-2 mb-3">
        <?= csrf_field() ?>
        <div class="col-md-2"><select class="form-select" name="engine" required>
            <?php foreach (['chatgpt','gemini','perplexity','copilot','bing_ai','google_ai_overview','other'] as $engine): ?><option value="<?= e($engine) ?>"><?= e($engine) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-3"><input class="form-control" name="prompt" required maxlength="4000" placeholder="观测问题"></div>
        <div class="col-md-3"><input class="form-control" type="url" name="cited_url" placeholder="站内引用 URL"></div>
        <div class="col-md-2"><input class="form-control" type="number" name="citation_position" min="0" max="1000" placeholder="引用位次"></div>
        <div class="col-md-2"><select class="form-select" name="sentiment"><option value="neutral">中性</option><option value="positive">正面</option><option value="negative">负面</option><option value="mixed">混合</option></select></div>
        <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-plus-lg"></i> 记录</button></div>
        <div class="col-12"><textarea class="form-control" name="answer_summary" maxlength="4000" rows="2" placeholder="答案摘要（不填也可）"></textarea></div>
    </form>
    <?php endif; ?>
    <?php
    echo data_grid([
        'id' => 'search-insights-geo-grid', 'compact' => true,
        'columns' => [
            ['key' => 'observed_at', 'label' => '观测时间', 'width' => '170px'],
            ['key' => 'engine', 'label' => '引擎', 'width' => '140px', 'cell' => static fn($r) => dg_badge((string)$r['engine'], 'info')],
            ['key' => 'prompt', 'label' => '问题', 'cell' => static fn($r) => '<span class="dg-primary">' . e((string)$r['prompt']) . '</span><small class="dg-secondary">' . e((string)($r['cited_url'] ?? '未引用站内 URL')) . '</small>'],
            ['key' => 'citation_position', 'label' => '引用位次', 'align' => 'end'],
            ['key' => 'sentiment', 'label' => '倾向', 'width' => '100px'],
        ],
        'rows' => $geoRows, 'rowKey' => 'id',
        'empty' => ['title' => '暂无 GEO 观测', 'description' => ''],
    ]);
    ?>
</section>

<?php if ($canManage): ?>
<section id="connections" class="mb-4" aria-labelledby="connectionsTitle">
    <h5 id="connectionsTitle" class="mb-3">平台连接</h5>
    <div class="row g-3">
        <div class="col-lg-6">
            <form method="post" action="<?= admin_url('/search-insights/settings/google') ?>" class="border rounded p-3 h-100">
                <?= csrf_field() ?>
                <div class="d-flex justify-content-between align-items-center mb-3"><strong>Google</strong><?= dg_badge(!empty($google['connected']) ? '已授权' : '未授权', !empty($google['connected']) ? 'success' : 'neutral') ?></div>
                <div class="mb-2"><label class="form-label">站点 URL</label><input type="url" class="form-control" name="site_url" required value="<?= e((string)($google['site_url'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Search Console property</label><input class="form-control" name="property_id" required value="<?= e((string)($google['property_id'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">GA4 property ID</label><input class="form-control" name="ga_property_id" value="<?= e((string)($google['ga_property_id'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Merchant Center account ID</label><input class="form-control" name="merchant_account_id" inputmode="numeric" pattern="[0-9]{1,20}" value="<?= e((string)($google['merchant_account_id'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">OAuth client ID</label><input class="form-control" name="client_id" required value="<?= e((string)($google['client_id'] ?? '')) ?>"></div>
                <div class="mb-3"><label class="form-label">OAuth client secret</label><input type="password" class="form-control" name="client_secret" autocomplete="new-password" placeholder="<?= !empty($google['has_client_secret']) ? '已保存，留空保持不变' : '' ?>"></div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> 保存</button>
                    <a class="btn btn-outline-primary" href="<?= admin_url('/search-insights/google/connect') ?>"><i class="bi bi-link-45deg"></i> 授权</a>
                </div>
            </form>
        </div>
        <div class="col-lg-6">
            <form method="post" action="<?= admin_url('/search-insights/settings/pagespeed') ?>" class="border rounded p-3 mb-3">
                <?= csrf_field() ?>
                <strong class="d-block mb-3">PageSpeed</strong>
                <div class="mb-2"><label class="form-label">站点 URL</label><input type="url" class="form-control" name="site_url" required value="<?= e((string)($psi['site_url'] ?? '')) ?>"></div>
                <div class="mb-3"><label class="form-label">API key</label><input type="password" class="form-control" name="api_key" autocomplete="new-password" placeholder="<?= !empty($psi['has_api_key']) ? '已保存，留空保持不变' : '' ?>"></div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> 保存</button>
            </form>
        </div>
    </div>
</section>

<section id="verification" class="mb-4" aria-labelledby="verificationTitle">
    <h5 id="verificationTitle" class="mb-3">站点验证</h5>
    <form method="post" action="<?= admin_url('/search-insights/verification') ?>" class="row g-3">
        <?= csrf_field() ?>
        <div class="col-md-5"><label class="form-label">Google token</label><input class="form-control" name="google_token" value="<?= e($googleVerification) ?>"></div>
        <div class="col-md-5"><label class="form-label">Bing token</label><input class="form-control" name="bing_token" value="<?= e($bingVerification) ?>"></div>
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2"></i> 保存</button></div>
    </form>
</section>
<?php endif; ?>

<?php $this->endSection(); ?>
