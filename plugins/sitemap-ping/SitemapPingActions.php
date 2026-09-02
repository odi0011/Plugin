<?php

/** Queue, rebuild, diff and submit sitemap URLs through IndexNow. */
final class SitemapPingActions
{
    private const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
    private const MAX_BATCH = 1000;
    private const MAX_QUEUE_ROWS = 10000;
    private const MAX_SITEMAP_FILES = 200;
    private const MAX_SITEMAP_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_RETRIES = 5;
    private const MAX_REQUEST_BYTES = 900000;
    private const MAX_BATCHES_PER_RUN = 10;
    private static bool $queueOverflowed = false;

    /** Run by AutomationWorker after the plugin schedule lease is acquired. */
    public static function run(array $payload, array $context): array
    {
        self::$queueOverflowed = false;
        if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') {
            return ['submitted' => 0, 'queued' => 0, 'skipped' => 'disabled'];
        }
        $batchLimit = max(1, min(
            self::MAX_BATCH,
            (int)get_plugin_setting('sitemap-ping', 'batch_limit', (string)($payload['batch_limit'] ?? 100))
        ));
        $before = self::readSitemapUrls();
        $build = null;
        try {
            $controller = new \App\Frontend\Controllers\SitemapController(new \App\Core\Request());
            $build = $controller->build();
        } catch (\Throwable $error) {
            throw new \RuntimeException('Sitemap rebuild failed', 0, $error);
        }
        if (!is_array($build) || empty($build['ok'])) {
            throw new \RuntimeException('Sitemap rebuild failed');
        }
        $after = self::readSitemapUrls();
        self::queueDiff($before, $after);
        $result = ['submitted' => 0, 'failed' => 0, 'queued' => self::queueCount()];
        for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++) {
            $part = self::submitQueued($batchLimit, $context);
            $result['submitted'] += (int)($part['submitted'] ?? 0);
            $result['failed'] += (int)($part['failed'] ?? 0);
            $result['queued'] = (int)($part['queued'] ?? self::queueCount());
            if ((int)($part['submitted'] ?? 0) < 1) break;
        }
        $result['sitemap_rebuilt'] = true;
        $result['added'] = count(array_diff_key($after, $before));
        $result['deleted'] = count(array_diff_key($before, $after));
        $result['queue_overflow'] = self::$queueOverflowed;
        return $result;
    }

    /** Add/update or delete one URL based on a content record. */
    public static function queueRecord(string $type, int $id, array $record, bool $deleted = false): void
    {
        if ($id < 1) return;
        $type = strtolower(trim($type));
        if (!$deleted) {
            try {
                $fresh = match ($type) {
                    'page' => \App\Models\Page::find($id),
                    'article' => \App\Models\Article::find($id),
                    'product' => \App\Models\Product::find($id),
                    default => \App\Models\ContentEntry::find($id),
                };
                if (is_array($fresh) && $fresh !== []) $record = $fresh;
            } catch (\Throwable $_) {
                // A partial hook payload is unsafe for URL derivation; the
                // fallback below will only queue a URL when it is complete.
            }
        }
        $record['id'] = $id;
        if ($type !== 'page' && $type !== 'article' && $type !== 'product') {
            $record['content_type'] = $type;
        }
        $url = self::recordUrl($type, $record);
        if ($url === '') return;
        $changeType = $deleted || !self::isPublished($record) ? 'delete' : 'upsert';
        foreach (self::localizedRecordUrls($url) as $localized) self::upsertQueueRow($localized, $changeType);
    }

    /** Return the decrypted key only to the internal transport/key-location path. */
    public static function indexNowKey(): string
    {
        try {
            $stored = trim((string)\App\Core\Setting::get('plugin.sitemap-ping.indexnow_key_envelope', ''));
            if ($stored === '' || !class_exists(\App\Core\Security::class)) return '';
            $key = trim((string)\App\Core\Security::decryptApiKey($stored));
            return preg_match('/^[A-Za-z0-9-]{8,128}$/D', $key) === 1 ? $key : '';
        } catch (\Throwable $_) {
            return '';
        }
    }

    /** @return array{ok:bool,message:string,data:array<string,mixed>} */
    public static function status(array $arguments, array $context): array
    {
        $counts = ['queued' => 0, 'running' => 0, 'failed' => 0, 'succeeded' => 0];
        try {
            foreach ($counts as $state => $_) {
                $counts[$state] = (int)\App\Core\Database::table('plugin_sitemap_submission_urls')
                    ->where('status', $state)->count();
            }
        } catch (\Throwable $_) {
        }
        return [
            'ok' => true,
            'message' => 'IndexNow 提交队列状态',
            'data' => [
                'enabled' => (string)get_plugin_setting('sitemap-ping', 'enabled', '0') === '1',
                'key_configured' => self::indexNowKey() !== '',
                'key_location' => function_exists('url') ? (string)url('/indexnow-key.txt') : '',
                'counts' => $counts,
            ],
        ];
    }

    /** @return array{ok:bool,message:string,data:array<string,mixed>} */
    public static function requestSubmit(array $arguments, array $context): array
    {
        $runId = function_exists('sitemap_ping_enqueue_run') ? sitemap_ping_enqueue_run('api') : null;
        if ($runId === null) {
            return ['ok' => false, 'message' => '无法加入 IndexNow 异步队列', 'error_code' => 'enqueue_failed', 'http_status' => 503];
        }
        return [
            'ok' => true,
            'message' => 'IndexNow 提交已加入异步队列',
            'data' => ['run_id' => $runId],
        ];
    }

    /** @return array<string,string> */
    private static function readSitemapUrls(): array
    {
        $dir = rtrim(STORAGE_PATH, '/\\') . '/cache/seo';
        $rootPath = $dir . '/sitemap-root.xml';
        if (!is_file($rootPath) || is_link($rootPath) || (int)@filesize($rootPath) > self::MAX_SITEMAP_FILE_BYTES) return [];
        $rootXml = @file_get_contents($rootPath);
        if (!is_string($rootXml) || !str_contains($rootXml, '<sitemapindex ')) return [];
        preg_match_all('/<loc>[^<]*(?:\?|&amp;)part=([^<&]+)<\/loc>/i', $rootXml, $partMatches);
        $files = [];
        $realRoot = realpath($dir);
        if ($realRoot === false) return [];
        foreach (array_slice((array)($partMatches[1] ?? []), 0, self::MAX_SITEMAP_FILES) as $encoded) {
            $filename = rawurldecode(html_entity_decode((string)$encoded, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if (preg_match('/^sitemap-[a-z0-9_-]+-[0-9]{14}-[a-f0-9]{10}-[0-9]+\.xml$/iD', $filename) !== 1) continue;
            $path = $dir . '/' . $filename;
            $real = realpath($path);
            if ($real === false || is_link($path)
                || !str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) continue;
            $files[] = $real;
        }
        $urls = [];
        $count = 0;
        foreach ($files as $file) {
            if ($count++ >= self::MAX_SITEMAP_FILES || !is_file($file) || is_link($file)) continue;
            $size = @filesize($file);
            if (!is_int($size) || $size < 1 || $size > self::MAX_SITEMAP_FILE_BYTES) continue;
            $xml = @file_get_contents($file);
            if (!is_string($xml) || $xml === '') continue;
            if (!preg_match_all('#<url\b[^>]*>\s*<loc>(.*?)</loc>.*?</url>#is', $xml, $matches, PREG_SET_ORDER)) continue;
            foreach ($matches as $match) {
                $raw = (string)($match[1] ?? '');
                $url = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $url = self::sameOriginUrl($url);
                if ($url !== '') $urls[$url] = hash('sha256', (string)($match[0] ?? $url));
            }
        }
        return $urls;
    }

    /** @param array<string,string> $before @param array<string,string> $after */
    private static function queueDiff(array $before, array $after): void
    {
        foreach ($after as $url => $fingerprint) {
            if (!isset($before[$url]) || !hash_equals((string)$before[$url], (string)$fingerprint)) {
                self::upsertQueueRow((string)$url, 'upsert');
            }
        }
        foreach (array_diff_key($before, $after) as $url => $_fingerprint) self::upsertQueueRow((string)$url, 'delete');
    }

    private static function upsertQueueRow(string $url, string $changeType): void
    {
        $url = self::sameOriginUrl($url);
        if ($url === '' || !in_array($changeType, ['upsert', 'delete'], true)) return;
        $table = '`' . str_replace('`', '', \App\Core\Database::prefix() . 'plugin_sitemap_submission_urls') . '`';
        $hash = hash('sha256', $url);
        $existing = \App\Core\Database::table('plugin_sitemap_submission_urls')
            ->select('id')->where('url_hash', $hash)->first();
        if (!is_array($existing) && !self::ensureQueueCapacity()) {
            self::$queueOverflowed = true;
            if (function_exists('logger')) \logger('[sitemap-ping] IndexNow queue capacity reached', 'warning');
            return;
        }
        \App\Core\Database::pdo()->prepare(
            "INSERT INTO {$table} (`url_hash`,`url`,`change_type`,`status`,`attempts`,`available_at`,`created_at`,`updated_at`) "
            . "VALUES (?,?,?,'queued',0,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) "
            . "ON DUPLICATE KEY UPDATE `url`=VALUES(`url`),`change_type`=VALUES(`change_type`),`status`='queued',"
            . "`attempts`=0,`last_error`=NULL,`locked_until`=NULL,`claim_token`=NULL,"
            . "`completed_at`=NULL,`updated_at`=CURRENT_TIMESTAMP"
        )->execute([$hash, $url, $changeType]);
    }

    /** @return array{submitted:int,failed:int,queued:int} */
    private static function submitQueued(int $batchLimit, array $context): array
    {
        $key = self::indexNowKey();
        if ($key === '') throw new \RuntimeException('IndexNow key is unavailable');
        $rows = self::claimRows($batchLimit);
        if ($rows === []) return ['submitted' => 0, 'failed' => 0, 'queued' => self::queueCount()];

        $host = self::siteHost();
        if ($host === '') {
            self::failRows($rows, 'site_host_invalid', false);
            throw new \RuntimeException('Site host is unavailable');
        }
        $keyLocation = function_exists('url') ? (string)url('/indexnow-key.txt') : '';
        $rows = self::fitRowsToRequest($rows, $host, $key, $keyLocation);
        if ($rows === []) return ['submitted' => 0, 'failed' => 0, 'queued' => self::queueCount(), 'deferred' => true];
        $urls = array_values(array_map(static fn(array $row): string => (string)$row['url'], $rows));
        $body = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => $keyLocation,
            'urlList' => $urls,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = \App\Core\OutboundHttpClient::postJson(
                self::INDEXNOW_ENDPOINT,
                $body,
                ['User-Agent' => 'ODCMS-IndexNow/1.0'],
                15,
                65536
            );
            $status = (int)($response['status'] ?? 0);
            if (!in_array($status, [200, 202], true)) {
                $transient = $status === 429 || $status >= 500 || $status === 0;
                self::failRows($rows, 'indexnow_http_' . ($status > 0 ? $status : 'error'), $transient);
                self::logResult($status, count($rows), false);
                if ($transient) throw new \RuntimeException('IndexNow temporary failure');
                return ['submitted' => 0, 'failed' => count($rows), 'queued' => self::queueCount()];
            }
            self::succeedRows($rows);
            self::logResult($status, count($rows), true);
            return ['submitted' => count($rows), 'failed' => 0, 'queued' => self::queueCount()];
        } catch (\Throwable $error) {
            // Do not include request body/key or provider response in logs.
            if (!str_contains($error->getMessage(), 'IndexNow temporary failure')) {
                self::failRows($rows, 'indexnow_transport_error', true);
                self::logResult(0, count($rows), false);
            }
            throw $error;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function claimRows(int $limit): array
    {
        $rows = [];
        $now = date('Y-m-d H:i:s');
        try {
            // A dead worker must not leave URLs permanently running.
            \App\Core\Database::table('plugin_sitemap_submission_urls')
                ->where('status', 'running')
                ->where('locked_until', '<', $now)
                ->update([
                    'status' => 'failed',
                    'available_at' => $now,
                    'locked_until' => null,
                    'claim_token' => null,
                    'last_error' => 'submission_lease_expired',
                    'updated_at' => $now,
                ]);
            $rows = \App\Core\Database::table('plugin_sitemap_submission_urls')
                ->whereRaw("(`status`='queued' OR (`status`='failed' AND `attempts` < ?)) AND `available_at` <= ?", [self::MAX_RETRIES, $now])
                ->orderBy('id', 'asc')->limit($limit)->get();
        } catch (\Throwable $_) {
            return [];
        }
        $claimed = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) continue;
            try {
                $claimToken = hash('sha256', random_bytes(32));
            } catch (\Throwable $_) {
                continue;
            }
            $changed = (int)\App\Core\Database::table('plugin_sitemap_submission_urls')
                ->where('id', $id)
                ->whereIn('status', ['queued', 'failed'])
                ->update([
                    'status' => 'running',
                    'locked_until' => date('Y-m-d H:i:s', time() + 300),
                    'claim_token' => $claimToken,
                    'updated_at' => $now,
                ]);
            if ($changed === 1) {
                $row['status'] = 'running';
                $row['claim_token'] = $claimToken;
                $claimed[] = $row;
            }
        }
        return $claimed;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function succeedRows(array $rows): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $claimToken = (string)($row['claim_token'] ?? '');
            if ($id < 1 || preg_match('/^[a-f0-9]{64}$/D', $claimToken) !== 1) continue;
            \App\Core\Database::table('plugin_sitemap_submission_urls')
                ->where('id', $id)->where('status', 'running')->where('claim_token', $claimToken)->update([
                'status' => 'succeeded', 'completed_at' => $now,
                'locked_until' => null, 'claim_token' => null,
                'last_error' => null, 'updated_at' => $now,
            ]);
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function failRows(array $rows, string $errorCode, bool $retry): void
    {
        $now = time();
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $claimToken = (string)($row['claim_token'] ?? '');
            if ($id < 1 || preg_match('/^[a-f0-9]{64}$/D', $claimToken) !== 1) continue;
            $attempts = (int)($row['attempts'] ?? 0) + 1;
            $canRetry = $retry && $attempts < self::MAX_RETRIES;
            \App\Core\Database::table('plugin_sitemap_submission_urls')
                ->where('id', $id)->where('status', 'running')->where('claim_token', $claimToken)->update([
                'status' => $canRetry ? 'failed' : 'failed',
                'attempts' => $canRetry ? $attempts : self::MAX_RETRIES,
                'available_at' => date('Y-m-d H:i:s', $now + ($canRetry ? min(3600, 60 * (2 ** min(5, $attempts))) : 86400)),
                'locked_until' => null,
                'claim_token' => null,
                'last_error' => substr(preg_replace('/[^a-z0-9_.-]+/i', '_', $errorCode) ?: 'submission_failed', 0, 180),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** Keep the JSON body below the core transport cap and release extras safely. */
    private static function fitRowsToRequest(array $rows, string $host, string $key, string $keyLocation): array
    {
        $selected = [];
        foreach ($rows as $row) {
            $candidate = $selected;
            $candidate[] = $row;
            $urls = array_values(array_map(static fn(array $item): string => (string)$item['url'], $candidate));
            $encoded = json_encode([
                'host' => $host, 'key' => $key, 'keyLocation' => $keyLocation, 'urlList' => $urls,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || strlen($encoded) > self::MAX_REQUEST_BYTES) break;
            $selected = $candidate;
        }
        if (count($selected) < count($rows)) self::releaseRows(array_slice($rows, count($selected)));
        return $selected;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function releaseRows(array $rows): void
    {
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $token = (string)($row['claim_token'] ?? '');
            if ($id < 1 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) continue;
            \App\Core\Database::table('plugin_sitemap_submission_urls')
                ->where('id', $id)->where('status', 'running')->where('claim_token', $token)->update([
                    'status' => 'queued', 'available_at' => $now, 'locked_until' => null,
                    'claim_token' => null, 'updated_at' => $now,
                ]);
        }
    }

    private static function queueCount(): int
    {
        try {
            return (int)\App\Core\Database::table('plugin_sitemap_submission_urls')
                ->whereRaw(
                    "`status` IN ('queued','running') OR (`status`='failed' AND `attempts` < ?)",
                    [self::MAX_RETRIES]
                )->count();
        } catch (\Throwable $_) {
            return 0;
        }
    }

    private static function ensureQueueCapacity(): bool
    {
        try {
            $count = (int)\App\Core\Database::table('plugin_sitemap_submission_urls')->count();
            if ($count < self::MAX_QUEUE_ROWS) return true;
            $rows = \App\Core\Database::table('plugin_sitemap_submission_urls')
                ->select('id')
                ->whereRaw(
                    "`status`='succeeded' OR (`status`='failed' AND `attempts` >= ?)",
                    [self::MAX_RETRIES]
                )
                ->orderBy('id', 'asc')
                ->limit(min(1000, $count - self::MAX_QUEUE_ROWS + 1))
                ->get();
            $ids = array_values(array_filter(array_map(
                static fn(array $row): int => (int)($row['id'] ?? 0),
                $rows
            ), static fn(int $id): bool => $id > 0));
            if ($ids !== []) {
                \App\Core\Database::table('plugin_sitemap_submission_urls')->whereIn('id', $ids)->delete();
                $count -= count($ids);
            }
            return $count < self::MAX_QUEUE_ROWS;
        } catch (\Throwable $_) {
            return false;
        }
    }

    private static function logResult(int $status, int $count, bool $ok): void
    {
        try {
            if (function_exists('sitemap_ping_log')) {
                sitemap_ping_log(self::INDEXNOW_ENDPOINT, 'indexnow', 0, $ok, 'HTTP ' . $status . '; urls=' . $count);
            }
        } catch (\Throwable $_) {
        }
    }

    private static function recordUrl(string $type, array $record): string
    {
        try {
            return match ($type) {
                'page' => function_exists('page_permalink') ? page_permalink($record) : '',
                'article' => function_exists('article_permalink') ? article_permalink($record) : '',
                'product' => function_exists('product_permalink') ? product_permalink($record) : '',
                default => function_exists('content_entry_permalink') ? content_entry_permalink($record + ['content_type' => $type]) : '',
            };
        } catch (\Throwable $_) {
            return '';
        }
    }

    /** @return array<int,string> */
    private static function localizedRecordUrls(string $url): array
    {
        $urls = [];
        $safe = self::sameOriginUrl($url);
        if ($safe !== '') $urls[$safe] = $safe;
        try {
            if (!\App\Core\Setting::bool('multilingual_enabled', false)) return array_values($urls);
            $parts = parse_url($url);
            if (!is_array($parts)) return array_values($urls);
            $source = (string)($parts['path'] ?? '/')
                . (!empty($parts['query']) ? '?' . (string)$parts['query'] : '');
            foreach (\App\Core\LanguageService::enabledLanguages() as $language) {
                $code = \App\Core\LanguageService::normalizeCode((string)($language['code'] ?? ''));
                if ($code === '') continue;
                $localized = self::sameOriginUrl(\App\Core\LanguageService::localizedUrl($source, $code));
                if ($localized !== '') $urls[$localized] = $localized;
            }
        } catch (\Throwable $_) {
        }
        return array_values($urls);
    }

    private static function isPublished(array $record): bool
    {
        if ((int)($record['status'] ?? 0) !== 1) return false;
        $publishedAt = trim((string)($record['published_at'] ?? ''));
        return $publishedAt === '' || (($stamp = strtotime($publishedAt)) !== false && $stamp <= time());
    }

    private static function sameOriginUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $url)) return '';
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || trim((string)($parts['host'] ?? '')) === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return '';
        $base = function_exists('base_url') ? (string)base_url() : (function_exists('url') ? (string)url('/') : '');
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || strcasecmp((string)$parts['host'], (string)($baseParts['host'] ?? '')) !== 0) return '';
        $port = (int)($parts['port'] ?? 443);
        $basePort = (int)($baseParts['port'] ?? 443);
        if ($port !== $basePort) return '';
        return $url;
    }

    private static function siteHost(): string
    {
        $base = function_exists('base_url') ? (string)base_url() : (function_exists('url') ? (string)url('/') : '');
        $host = parse_url($base, PHP_URL_HOST);
        return is_string($host) && preg_match('/^[a-z0-9.-]+$/i', $host) === 1 ? strtolower($host) : '';
    }
}
