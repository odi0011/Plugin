<?php
declare(strict_types=1);

use App\Core\AuditTrail;
use App\Core\Database;
use App\Core\OutboundHttpClient;
use App\Core\Security;

final class SearchInsightsService
{
    private const GOOGLE_SCOPES = [
        'https://www.googleapis.com/auth/webmasters',
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/content',
    ];
    private const PROVIDERS = ['google', 'pagespeed'];
    private const METRIC_PROVIDERS = ['gsc', 'ga4', 'bing', 'bing_ai', 'manual'];
    private const GEO_ENGINES = [
        'chatgpt', 'gemini', 'perplexity', 'copilot', 'bing_ai',
        'google_ai_overview', 'other',
    ];
    private const GOOGLE_HOSTS = [
        'oauth2.googleapis.com', 'www.googleapis.com',
        'searchconsole.googleapis.com', 'analyticsdata.googleapis.com',
        'merchantapi.googleapis.com',
    ];
    /** 周期 worker 的租约由核心 checkpoint 续期；每次上游请求都必须经过该边界。 */
    private const MAX_SYNC_ROWS = 5000;
    private const MAX_MERCHANT_PRODUCTS = 100;
    private const MAX_MERCHANT_ISSUES = 25000;

    public static function connectionStatus(bool $includePublicClientId = false): array
    {
        $rows = [];
        foreach (self::PROVIDERS as $provider) {
            $row = self::connection($provider) ?: [];
            $config = self::jsonObject($row['config_json'] ?? null);
            $rows[$provider] = [
                'provider' => $provider,
                'configured' => !empty($row),
                'connected' => (string)($row['status'] ?? '') === 'connected',
                'status' => (string)($row['status'] ?? 'disconnected'),
                'site_url' => (string)($row['site_url'] ?? ''),
                'property_id' => (string)($row['property_id'] ?? ''),
                'ga_property_id' => (string)($config['ga_property_id'] ?? ''),
                'merchant_account_id' => (string)($config['merchant_account_id'] ?? ''),
                'has_client_secret' => trim((string)($row['client_secret_envelope'] ?? '')) !== '',
                'has_refresh_token' => trim((string)($row['refresh_token_envelope'] ?? '')) !== '',
                'has_api_key' => trim((string)($row['api_key_envelope'] ?? '')) !== '',
                'last_synced_at' => (string)($row['last_synced_at'] ?? ''),
                'last_error_code' => (string)($row['last_error_code'] ?? ''),
            ];
            if ($includePublicClientId) $rows[$provider]['client_id'] = (string)($row['client_id'] ?? '');
        }
        return [
            'connections' => $rows,
            'verification' => [
                'google_configured' => trim((string)get_plugin_setting('search-insights', 'google_verification', '')) !== '',
                'bing_configured' => trim((string)get_plugin_setting('search-insights', 'bing_verification', '')) !== '',
            ],
            'freshness' => self::freshness(),
        ];
    }

    public static function saveConnection(string $provider, array $input, int $actorId): array
    {
        $provider = self::provider($provider);
        $existing = self::connection($provider) ?: [];
        $siteUrl = self::connectionSiteUrl((string)($input['site_url'] ?? $existing['site_url'] ?? ''));
        $propertyId = trim((string)($input['property_id'] ?? $existing['property_id'] ?? ''));
        $clientId = trim((string)($input['client_id'] ?? $existing['client_id'] ?? ''));
        $config = self::jsonObject($existing['config_json'] ?? null);

        if ($provider === 'google') {
            if ($propertyId === '' || (!str_starts_with($propertyId, 'sc-domain:') && !self::validHttpsUrl($propertyId))) {
                throw new InvalidArgumentException('Search Console property 必须是 HTTPS URL 或 sc-domain:domain');
            }
            if ($clientId === '' || strlen($clientId) > 500) {
                throw new InvalidArgumentException('Google OAuth client_id 不能为空');
            }
            $gaProperty = trim((string)($input['ga_property_id'] ?? $config['ga_property_id'] ?? ''));
            if ($gaProperty !== '' && !preg_match('/^[0-9]{1,20}$/D', $gaProperty)) {
                throw new InvalidArgumentException('GA4 property ID 必须是数字');
            }
            $config['ga_property_id'] = $gaProperty;
            $merchantAccount = trim((string)($input['merchant_account_id'] ?? $config['merchant_account_id'] ?? ''));
            if ($merchantAccount !== '' && !preg_match('/^[0-9]{1,20}$/D', $merchantAccount)) {
                throw new InvalidArgumentException('Merchant Center account ID 必须是数字');
            }
            if ($merchantAccount !== (string)($config['merchant_account_id'] ?? '')) {
                unset(
                    $config['merchant_cursor'],
                    $config['merchant_account_cursor'],
                    $config['merchant_account_done'],
                    $config['merchant_cycle_started_at']
                );
            }
            $config['merchant_account_id'] = $merchantAccount;
        } elseif ($provider === 'pagespeed') {
            $propertyId = '';
            $clientId = '';
        }

        $clientSecretInput = trim((string)($input['client_secret'] ?? ''));
        $clientSecret = self::encryptedSecret(
            $clientSecretInput,
            (string)($existing['client_secret_envelope'] ?? '')
        );
        $apiKey = self::encryptedSecret(
            (string)($input['api_key'] ?? ''),
            (string)($existing['api_key_envelope'] ?? '')
        );
        if ($provider === 'google' && $clientSecret === '') {
            throw new InvalidArgumentException('Google OAuth client_secret 不能为空');
        }
        if ($provider === 'pagespeed' && $apiKey === '') {
            throw new InvalidArgumentException('PageSpeed API key 不能为空');
        }

        $oauthCredentialsChanged = $provider === 'google'
            && $existing !== []
            && self::googleOAuthCredentialsChanged(
                $existing,
                $clientId,
                $clientSecretInput
            );
        $now = date('Y-m-d H:i:s');
        $data = [
            'site_url' => $siteUrl,
            'property_id' => $propertyId !== '' ? $propertyId : null,
            'client_id' => $clientId !== '' ? $clientId : null,
            'client_secret_envelope' => $clientSecret !== '' ? $clientSecret : null,
            'api_key_envelope' => $apiKey !== '' ? $apiKey : null,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $provider === 'google'
                ? (!$oauthCredentialsChanged && (string)($existing['refresh_token_envelope'] ?? '') !== '' ? 'connected' : 'configured')
                : 'connected',
            'last_error_code' => null,
            'updated_by' => $actorId > 0 ? $actorId : null,
            'updated_at' => $now,
        ];
        if ($oauthCredentialsChanged) {
            $data['access_token_envelope'] = null;
            $data['refresh_token_envelope'] = null;
            $data['token_expires_at'] = null;
            $data['scopes_json'] = null;
        }
        if ($existing) {
            Database::table('plugin_search_insights_connections')->where('provider', $provider)->update($data);
        } else {
            Database::table('plugin_search_insights_connections')->insert($data + [
                'provider' => $provider,
                'created_by' => $actorId > 0 ? $actorId : null,
                'created_at' => $now,
            ]);
        }
        self::audit('search_insights.connection.update', $provider, $actorId, [
            'site_url' => $siteUrl,
            'property_id' => $propertyId,
            'has_client_secret' => $clientSecret !== '',
            'has_api_key' => $apiKey !== '',
            'oauth_reauth_required' => $oauthCredentialsChanged,
        ]);
        return self::connectionStatus()['connections'][$provider];
    }

    public static function disconnectGoogle(int $actorId): void
    {
        Database::table('plugin_search_insights_connections')->where('provider', 'google')->update([
            'access_token_envelope' => null,
            'refresh_token_envelope' => null,
            'token_expires_at' => null,
            'status' => 'configured',
            'last_error_code' => null,
            'updated_by' => $actorId > 0 ? $actorId : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        self::audit('search_insights.google.disconnect', 'google', $actorId, []);
    }

    public static function googleAuthorizationUrl(int $actorId): string
    {
        $connection = self::requireConnection('google');
        $clientId = trim((string)($connection['client_id'] ?? ''));
        if ($clientId === '' || trim((string)($connection['client_secret_envelope'] ?? '')) === '') {
            throw new RuntimeException('请先保存 Google OAuth 客户端配置');
        }
        $state = bin2hex(random_bytes(24));
        $verifier = self::base64Url(random_bytes(48));
        $_SESSION['search_insights_google_oauth'] = [
            'state_hash' => hash('sha256', $state),
            'verifier' => $verifier,
            'actor_id' => $actorId,
            'expires_at' => time() + 600,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::googleRedirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::GOOGLE_SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function completeGoogleAuthorization(string $code, string $state, int $actorId): void
    {
        $pending = $_SESSION['search_insights_google_oauth'] ?? null;
        unset($_SESSION['search_insights_google_oauth']);
        if (!is_array($pending)
            || (int)($pending['expires_at'] ?? 0) < time()
            || (int)($pending['actor_id'] ?? 0) !== $actorId
            || $state === ''
            || !hash_equals((string)($pending['state_hash'] ?? ''), hash('sha256', $state))) {
            throw new RuntimeException('Google OAuth state 已失效，请重新连接');
        }
        if ($code === '' || strlen($code) > 4096) throw new InvalidArgumentException('Google authorization code 无效');
        $connection = self::requireConnection('google');
        $response = self::requestForm('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => (string)$connection['client_id'],
            'client_secret' => self::decryptSecret((string)$connection['client_secret_envelope']),
            'redirect_uri' => self::googleRedirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => (string)$pending['verifier'],
        ]);
        $access = trim((string)($response['access_token'] ?? ''));
        $refresh = trim((string)($response['refresh_token'] ?? ''));
        if ($access === '' || ($refresh === '' && trim((string)($connection['refresh_token_envelope'] ?? '')) === '')) {
            throw new RuntimeException('Google OAuth 未返回可用 token');
        }
        $scopeValues = preg_split('/\s+/', trim((string)($response['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $grantedScopes = array_values(array_intersect(self::GOOGLE_SCOPES, $scopeValues));
        if (!in_array('https://www.googleapis.com/auth/webmasters', $grantedScopes, true)) {
            throw new RuntimeException('Google OAuth 未授予 Search Console 权限，请重新授权');
        }
        $changes = [
            'access_token_envelope' => Security::encryptApiKey($access),
            'token_expires_at' => date('Y-m-d H:i:s', time() + max(60, (int)($response['expires_in'] ?? 3600))),
            'scopes_json' => json_encode($grantedScopes, JSON_UNESCAPED_SLASHES),
            'status' => 'connected',
            'last_error_code' => null,
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($refresh !== '') $changes['refresh_token_envelope'] = Security::encryptApiKey($refresh);
        Database::table('plugin_search_insights_connections')->where('provider', 'google')->update($changes);
        self::audit('search_insights.google.connect', 'google', $actorId, ['scopes' => $grantedScopes]);
    }

    public static function sync(string $provider = 'google', string $dateFrom = '', string $dateTo = '', array $context = []): array
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['google', 'gsc', 'ga4'], true)) {
            throw new InvalidArgumentException('自动同步仅支持 google、gsc 或 ga4；Bing AI Performance 请使用规范化导入');
        }
        [$dateFrom, $dateTo] = self::dateRange($dateFrom, $dateTo, 90);
        $checkpoint = self::checkpoint($context);
        if ($checkpoint !== null) $checkpoint(true);
        $connection = self::requireGoogleConnected();
        $config = self::jsonObject($connection['config_json'] ?? null);
        $cursor = is_array($config['sync_cursor'] ?? null) ? $config['sync_cursor'] : [];
        if ((string)($cursor['provider'] ?? '') !== $provider
            || (string)($cursor['date_from'] ?? '') !== $dateFrom
            || (string)($cursor['date_to'] ?? '') !== $dateTo) {
            $cursor = [];
        }
        $result = [
            'gsc' => 0, 'ga4' => 0, 'merchant' => ['skipped' => 'not_configured'],
            'date_from' => $dateFrom, 'date_to' => $dateTo, 'truncated' => false,
            'cycle_complete' => true, 'next_sync_continues_cycle' => false,
        ];
        $nextCursor = [];
        $hasMoreOverall = false;
        if ($provider !== 'ga4') {
            $gsc = self::syncSearchConsole($dateFrom, $dateTo, [
                'query_offset' => (int)($cursor['gsc_query_offset'] ?? 0),
                'page_offset' => (int)($cursor['gsc_page_offset'] ?? 0),
                'query_done' => !empty($cursor['gsc_query_done']),
                'page_done' => !empty($cursor['gsc_page_done']),
            ], $checkpoint);
            $result['gsc'] = $gsc['rows'];
            $result['gsc_diagnostics'] = $gsc;
            $result['truncated'] = $result['truncated'] || $gsc['truncated'];
            foreach ((array)($gsc['next_cursors'] ?? []) as $key => $value) {
                if (is_bool($value)) $nextCursor['gsc_' . $key] = $value;
                elseif ($value !== null) $nextCursor['gsc_' . $key] = (int)$value;
            }
            $hasMoreOverall = !empty($gsc['has_more']);
        }
        if ($provider !== 'gsc' && empty($cursor['ga4_done'])) {
            $ga = self::syncGa4($dateFrom, $dateTo, (int)($cursor['ga4'] ?? 0), $checkpoint);
            $result['ga4'] = $ga['rows'];
            $result['ga4_diagnostics'] = $ga;
            $result['truncated'] = $result['truncated'] || $ga['truncated'];
            if (($ga['next_cursor'] ?? null) !== null) $nextCursor['ga4'] = (int)$ga['next_cursor'];
            else $nextCursor['ga4_done'] = true;
            $hasMoreOverall = $hasMoreOverall || !empty($ga['truncated']);
        } elseif ($provider !== 'gsc') {
            $nextCursor['ga4_done'] = true;
        }
        if ($provider === 'google' && self::merchantAccountId(false) !== '') {
            $merchant = self::syncMerchantDiagnostics('zh-CN', 100, $context);
            $result['merchant'] = $merchant;
            $result['truncated'] = $result['truncated'] || !empty($merchant['truncated']);
            // Merchant 自己的服务端游标也是这一轮同步的一部分；不能在 GSC/GA4
            // 完成时把全局 cycle_complete 错报为 true。
            $hasMoreOverall = $hasMoreOverall || !empty($merchant['next_sync_continues_cycle']);
            unset($config['merchant_cursor'], $config['merchant_account_cursor'], $config['merchant_account_done'], $config['merchant_cycle_started_at']);
            $merchantConnection = self::connection('google') ?: [];
            $merchantConfig = self::jsonObject($merchantConnection['config_json'] ?? null);
            foreach (['merchant_cursor', 'merchant_account_cursor', 'merchant_account_done', 'merchant_cycle_started_at'] as $key) {
                if (array_key_exists($key, $merchantConfig)) $config[$key] = $merchantConfig[$key];
            }
        }
        if ($hasMoreOverall) {
            $config['sync_cursor'] = $nextCursor + [
                'provider' => $provider, 'date_from' => $dateFrom, 'date_to' => $dateTo,
            ];
            $result['cycle_complete'] = false;
            $result['next_sync_continues_cycle'] = true;
        } else {
            unset($config['sync_cursor']);
        }
        Database::table('plugin_search_insights_connections')->where('provider', 'google')->update([
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error_code' => null,
            'status' => 'connected',
        ]);
        return $result;
    }

    public static function scheduledSync(array $payload, array $context): array
    {
        $connection = self::connection('google');
        if (!$connection
            || !in_array((string)($connection['status'] ?? ''), ['connected', 'error'], true)
            || trim((string)($connection['refresh_token_envelope'] ?? '')) === '') {
            return ['ok' => true, 'skipped' => 'google_not_connected'];
        }
        try {
            $stableDate = date('Y-m-d', strtotime('-2 days'));
            $result = self::sync(
                (string)($payload['provider'] ?? 'google'),
                (string)($payload['date_from'] ?? $stableDate),
                (string)($payload['date_to'] ?? $stableDate),
                $context
            );
            self::audit('search_insights.metrics.sync', 'google', 0, $result, 'system');
            return ['ok' => true, 'data' => $result];
        } catch (Throwable $error) {
            self::recordConnectionError('google', self::errorCode($error));
            throw $error;
        }
    }

    public static function syncMerchantDiagnostics(string $languageCode = 'zh-CN', int $maxProducts = 100, array $context = []): array
    {
        $authorized = self::requireGoogleConnected();
        $grantedScopes = json_decode((string)($authorized['scopes_json'] ?? ''), true);
        if (!is_array($grantedScopes)
            || !in_array('https://www.googleapis.com/auth/content', $grantedScopes, true)) {
            throw new RuntimeException('Merchant Center 权限尚未授权，请重新连接 Google OAuth');
        }
        $accountId = self::merchantAccountId();
        $languageCode = self::merchantLanguage($languageCode);
        $maxProducts = max(1, min(self::MAX_MERCHANT_PRODUCTS, $maxProducts));
        $connection = self::requireConnection('google');
        $checkpoint = self::checkpoint($context);
        if ($checkpoint !== null) $checkpoint(true);
        $config = self::jsonObject($connection['config_json'] ?? null);
        $cursor = self::bounded((string)($config['merchant_cursor'] ?? ''), 2048);
        $accountCursor = self::bounded((string)($config['merchant_account_cursor'] ?? ''), 2048);
        $accountDone = !empty($config['merchant_account_done']);
        $cycleStartedAt = self::dateTime((string)($config['merchant_cycle_started_at'] ?? ''));
        if (($cursor === '' && $accountCursor === '') || $cycleStartedAt === null) {
            $cursor = '';
            $accountCursor = '';
            $accountDone = false;
            $cycleStartedAt = date('Y-m-d H:i:s');
        }

        $accountIssues = 0;
        $accountTruncated = false;
        if (!$accountDone) {
            $pageToken = $accountCursor;
            do {
                if ($checkpoint !== null) $checkpoint();
                $query = [
                    'pageSize' => 100,
                    'languageCode' => $languageCode,
                    'fields' => 'accountIssues(name,title,severity,impactedDestinations(reportingContext,impacts(regionCode,severity)),detail,documentationUri),nextPageToken',
                ];
                if ($pageToken !== '') $query['pageToken'] = $pageToken;
                $response = self::googleJson(
                    'GET',
                    'https://merchantapi.googleapis.com/accounts/v1/accounts/' . rawurlencode($accountId)
                        . '/issues?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                    null
                );
                if ($checkpoint !== null) $checkpoint();
                foreach (array_slice((array)($response['accountIssues'] ?? []), 0, 100) as $issue) {
                    if (!is_array($issue)) continue;
                    self::storeMerchantAccountIssue($accountId, $issue, $cycleStartedAt);
                    $accountIssues++;
                    if ($accountIssues >= self::MAX_MERCHANT_ISSUES) break;
                }
                $pageToken = self::bounded((string)($response['nextPageToken'] ?? ''), 2048);
            } while ($pageToken !== '' && $accountIssues < self::MAX_MERCHANT_ISSUES);
            $accountTruncated = $pageToken !== '';
            $accountCursor = $accountTruncated ? $pageToken : '';
            $accountDone = !$accountTruncated;
            if (!$accountTruncated) {
                Database::exec(
                    'DELETE FROM ' . self::table('plugin_search_insights_merchant_issues')
                        . ' WHERE `account_id` = ? AND `scope` = ? AND `last_seen_at` < ?',
                    [$accountId, 'account', $cycleStartedAt]
                );
            }
        }

        $productsScanned = 0;
        $productIssues = 0;
        $issueListsTruncated = false;
        $nextToken = $cursor;
        do {
            if ($checkpoint !== null) $checkpoint();
            $query = [
                'pageSize' => min(20, $maxProducts - $productsScanned),
                'fields' => 'products(offerId,productStatus(itemLevelIssues(code,severity,resolution,attribute,reportingContext,description,detail,documentation,applicableCountries))),nextPageToken',
            ];
            if ($nextToken !== '') $query['pageToken'] = $nextToken;
            $response = self::googleJson(
                'GET',
                'https://merchantapi.googleapis.com/products/v1/accounts/' . rawurlencode($accountId)
                    . '/products?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                null
            );
            if ($checkpoint !== null) $checkpoint();
            $products = array_slice((array)($response['products'] ?? []), 0, (int)$query['pageSize']);
            foreach ($products as $product) {
                if (!is_array($product)) continue;
                $productsScanned++;
                $offerId = self::bounded((string)($product['offerId'] ?? ''), 191);
                $status = is_array($product['productStatus'] ?? null) ? $product['productStatus'] : [];
                $itemIssues = (array)($status['itemLevelIssues'] ?? []);
                if (count($itemIssues) > 200) $issueListsTruncated = true;
                foreach (array_slice($itemIssues, 0, 200) as $issue) {
                    if (!is_array($issue) || $productIssues >= self::MAX_MERCHANT_ISSUES) continue;
                    self::storeMerchantProductIssue($accountId, $offerId, $issue, $cycleStartedAt);
                    $productIssues++;
                }
            }
            $nextToken = self::bounded((string)($response['nextPageToken'] ?? ''), 2048);
        } while ($nextToken !== ''
            && $productsScanned < $maxProducts
            && $productIssues < self::MAX_MERCHANT_ISSUES);

        $complete = $nextToken === '';
        $accountComplete = $accountDone;
        if ($complete && $accountComplete) {
            Database::exec(
                'DELETE FROM ' . self::table('plugin_search_insights_merchant_issues')
                    . ' WHERE `account_id` = ? AND `scope` = ? AND `last_seen_at` < ?',
                [$accountId, 'product', $cycleStartedAt]
            );
            unset($config['merchant_cursor'], $config['merchant_account_cursor'], $config['merchant_account_done'], $config['merchant_cycle_started_at']);
        } else {
            if (!$complete) $config['merchant_cursor'] = $nextToken;
            else unset($config['merchant_cursor']);
            if (!$accountComplete) $config['merchant_account_cursor'] = $accountCursor;
            else unset($config['merchant_account_cursor']);
            if (!$accountComplete) $config['merchant_account_done'] = false;
            else unset($config['merchant_account_done']);
            $config['merchant_cycle_started_at'] = $cycleStartedAt;
        }
        Database::table('plugin_search_insights_connections')->where('provider', 'google')->update([
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_synced_at' => date('Y-m-d H:i:s'),
            'last_error_code' => null,
            'status' => 'connected',
        ]);

        return [
            'account_id' => $accountId,
            'account_issues' => $accountIssues,
            'account_issues_truncated' => $accountTruncated,
            'products_scanned' => $productsScanned,
            'product_issues' => $productIssues,
            'product_issue_lists_truncated' => $issueListsTruncated,
            'cycle_complete' => $complete && $accountComplete,
            'truncated' => !$complete || !$accountComplete || $accountTruncated || $issueListsTruncated
                || $productIssues >= self::MAX_MERCHANT_ISSUES,
            'next_sync_continues_cycle' => !($complete && $accountComplete),
            'synced_at' => date(DATE_ATOM),
        ];
    }

    public static function merchantDiagnostics(array $filters): array
    {
        $scope = strtolower(trim((string)($filters['scope'] ?? '')));
        if ($scope !== '' && !in_array($scope, ['account', 'product'], true)) {
            throw new InvalidArgumentException('scope 只接受 account 或 product');
        }
        $severity = strtoupper(trim((string)($filters['severity'] ?? '')));
        if ($severity !== '' && !preg_match('/^[A-Z][A-Z0-9_]{0,29}$/D', $severity)) {
            throw new InvalidArgumentException('severity 无效');
        }
        $q = self::bounded(trim((string)($filters['q'] ?? '')), 200);
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int)($filters['per_page'] ?? 20)));
        $where = [];
        $params = [];
        if ($scope !== '') { $where[] = '`scope` = ?'; $params[] = $scope; }
        if ($severity !== '') { $where[] = '`severity` = ?'; $params[] = $severity; }
        if ($q !== '') {
            $where[] = '(`subject_key` LIKE ? OR `issue_code` LIKE ? OR `title` LIKE ? OR `detail` LIKE ?)';
            for ($i = 0; $i < 4; $i++) $params[] = '%' . $q . '%';
        }
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $table = self::table('plugin_search_insights_merchant_issues');
        $count = (int)Database::exec("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params)->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $rows = Database::exec(
            "SELECT `id`,`account_id`,`scope`,`subject_key`,`issue_code`,`title`,`detail`,`severity`,"
                . "`resolution`,`attribute_name`,`reporting_context`,`countries_json`,`documentation_url`,"
                . "`observed_at`,`last_seen_at` FROM {$table} WHERE {$whereSql} "
                . "ORDER BY FIELD(`severity`,'CRITICAL','DISAPPROVED','ERROR','DEMOTED','SUGGESTION'),`last_seen_at` DESC,`id` DESC "
                . "LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
        foreach ($rows as &$row) {
            $countries = json_decode((string)($row['countries_json'] ?? ''), true);
            $row['countries'] = is_array($countries) ? array_values($countries) : [];
            unset($row['countries_json']);
        }
        unset($row);
        $summary = Database::exec(
            "SELECT `scope`,`severity`,COUNT(*) AS `issues` FROM {$table} WHERE {$whereSql} "
                . "GROUP BY `scope`,`severity` ORDER BY `scope`,`severity`",
            $params
        )->fetchAll();
        return self::pageResult($rows, $count, $page, $perPage) + ['summary' => $summary];
    }

    public static function inspectUrl(string $url, string $languageCode = 'zh-CN'): array
    {
        $url = self::sameOriginUrl($url);
        $connection = self::requireGoogleConnected();
        $response = self::googleJson(
            'POST',
            'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
            [
                'inspectionUrl' => $url,
                'siteUrl' => (string)$connection['property_id'],
                'languageCode' => preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $languageCode) ? $languageCode : 'en-US',
            ]
        );
        $status = is_array($response['inspectionResult']['indexStatusResult'] ?? null)
            ? $response['inspectionResult']['indexStatusResult'] : [];
        $rich = is_array($response['inspectionResult']['richResultsResult'] ?? null)
            ? $response['inspectionResult']['richResultsResult'] : [];
        $lastCrawl = self::dateTime((string)($status['lastCrawlTime'] ?? ''));
        $data = [
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'verdict' => self::bounded((string)($status['verdict'] ?? ''), 80),
            'coverage_state' => self::bounded((string)($status['coverageState'] ?? ''), 191),
            'robots_state' => self::bounded((string)($status['robotsTxtState'] ?? ''), 80),
            'indexing_state' => self::bounded((string)($status['indexingState'] ?? ''), 80),
            'google_canonical' => self::bounded((string)($status['googleCanonical'] ?? ''), 2048),
            'user_canonical' => self::bounded((string)($status['userCanonical'] ?? ''), 2048),
            'last_crawl_at' => $lastCrawl,
            'rich_results_json' => json_encode(self::richResultSummary($rich), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'inspected_at' => date('Y-m-d H:i:s'),
        ];
        self::upsert('plugin_search_insights_url_inspections', ['url_hash' => $data['url_hash']], $data);
        return $data;
    }

    public static function submitSitemap(string $sitemapUrl): array
    {
        $sitemapUrl = self::sameOriginUrl($sitemapUrl !== '' ? $sitemapUrl : url('/sitemap.xml'));
        if (!str_ends_with(strtolower((string)parse_url($sitemapUrl, PHP_URL_PATH)), '.xml')) {
            throw new InvalidArgumentException('Sitemap URL 必须指向 .xml');
        }
        $connection = self::requireGoogleConnected();
        $endpoint = 'https://www.googleapis.com/webmasters/v3/sites/'
            . rawurlencode((string)$connection['property_id']) . '/sitemaps/' . rawurlencode($sitemapUrl);
        $result = self::googleRaw('PUT', $endpoint, '');
        if ($result['status'] < 200 || $result['status'] >= 300) throw new RuntimeException('Google Sitemap 提交失败');
        return ['sitemap_url' => $sitemapUrl, 'submitted_at' => date(DATE_ATOM), 'status' => $result['status']];
    }

    public static function pageSpeed(string $url, string $strategy = 'mobile'): array
    {
        $url = self::sameOriginUrl($url);
        $strategy = strtolower(trim($strategy));
        if (!in_array($strategy, ['mobile', 'desktop'], true)) throw new InvalidArgumentException('strategy 只接受 mobile 或 desktop');
        $connection = self::requireConnection('pagespeed');
        $apiKey = self::decryptSecret((string)($connection['api_key_envelope'] ?? ''));
        if ($apiKey === '') throw new RuntimeException('PageSpeed API key 不可用');
        $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
            'url' => $url,
            'strategy' => $strategy,
            'key' => $apiKey,
            'fields' => 'lighthouseResult(categories(performance/score,accessibility/score,best-practices/score,seo/score),audits(largest-contentful-paint/numericValue,cumulative-layout-shift/numericValue,interaction-to-next-paint/numericValue,first-contentful-paint/numericValue,total-blocking-time/numericValue))',
        ], '', '&', PHP_QUERY_RFC3986)
            . '&category=performance&category=accessibility&category=best-practices&category=seo';
        $response = self::requestJson('GET', $endpoint, null, [], ['www.googleapis.com']);
        $lighthouse = is_array($response['lighthouseResult'] ?? null) ? $response['lighthouseResult'] : [];
        $categories = is_array($lighthouse['categories'] ?? null) ? $lighthouse['categories'] : [];
        $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];
        $data = [
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'strategy' => $strategy,
            'performance_score' => self::score($categories['performance']['score'] ?? null),
            'accessibility_score' => self::score($categories['accessibility']['score'] ?? null),
            'best_practices_score' => self::score($categories['best-practices']['score'] ?? null),
            'seo_score' => self::score($categories['seo']['score'] ?? null),
            'lcp_ms' => self::auditNumber($audits, 'largest-contentful-paint'),
            'cls' => self::auditNumber($audits, 'cumulative-layout-shift'),
            'inp_ms' => self::auditNumber($audits, 'interaction-to-next-paint'),
            'fcp_ms' => self::auditNumber($audits, 'first-contentful-paint'),
            'tbt_ms' => self::auditNumber($audits, 'total-blocking-time'),
            'checked_at' => date('Y-m-d H:i:s'),
        ];
        self::upsert('plugin_search_insights_pagespeed', [
            'url_hash' => $data['url_hash'], 'strategy' => $strategy,
        ], $data);
        return $data;
    }

    public static function importMetric(array $input): array
    {
        $provider = strtolower(trim((string)($input['provider'] ?? '')));
        if (!in_array($provider, self::METRIC_PROVIDERS, true)) throw new InvalidArgumentException('provider 不受支持');
        $date = self::date((string)($input['metric_date'] ?? ''));
        $dimension = strtolower(trim((string)($input['dimension'] ?? 'manual')));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $dimension)) throw new InvalidArgumentException('dimension 无效');
        $key = self::bounded(trim((string)($input['dimension_key'] ?? '')), 2000);
        if ($key === '') throw new InvalidArgumentException('dimension_key 不能为空');
        $row = [
            'provider' => $provider,
            'property_key' => self::bounded((string)($input['property_key'] ?? 'manual'), 191) ?: 'manual',
            'metric_date' => $date,
            'dimension' => $dimension,
            'dimension_key' => $key,
            'dimension_hash' => hash('sha256', $key),
            'clicks' => self::number($input['clicks'] ?? 0, 0, 1e15),
            'impressions' => self::number($input['impressions'] ?? 0, 0, 1e15),
            'ctr' => self::number($input['ctr'] ?? 0, 0, 1),
            'avg_position' => self::number($input['position'] ?? 0, 0, 1e9),
            'users' => self::number($input['users'] ?? 0, 0, 1e15),
            'sessions' => self::number($input['sessions'] ?? 0, 0, 1e15),
            'pageviews' => self::number($input['pageviews'] ?? 0, 0, 1e15),
            'conversions' => self::number($input['conversions'] ?? 0, 0, 1e15),
            'citations' => self::number($input['citations'] ?? 0, 0, 1e15),
        ];
        self::storeMetric($row);
        return $row;
    }

    public static function report(array $filters): array
    {
        $provider = strtolower(trim((string)($filters['provider'] ?? '')));
        if ($provider !== '' && !in_array($provider, self::METRIC_PROVIDERS, true)) throw new InvalidArgumentException('provider 不受支持');
        $dimension = strtolower(trim((string)($filters['dimension'] ?? '')));
        if ($dimension !== '' && !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $dimension)) throw new InvalidArgumentException('dimension 无效');
        [$dateFrom, $dateTo] = self::dateRange((string)($filters['date_from'] ?? ''), (string)($filters['date_to'] ?? ''), 366);
        $q = self::bounded(trim((string)($filters['q'] ?? '')), 200);
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int)($filters['per_page'] ?? 20)));
        $where = ['`metric_date` BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];
        if ($provider !== '') { $where[] = '`provider` = ?'; $params[] = $provider; }
        if ($dimension !== '') { $where[] = '`dimension` = ?'; $params[] = $dimension; }
        if ($q !== '') { $where[] = '`dimension_key` LIKE ?'; $params[] = '%' . $q . '%'; }
        $table = self::table('plugin_search_insights_metrics');
        $whereSql = implode(' AND ', $where);
        $count = (int)Database::exec("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params)->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $rows = Database::exec(
            "SELECT `id`,`provider`,`property_key`,`metric_date`,`dimension`,`dimension_key`,"
            . "`clicks`,`impressions`,`ctr`,`avg_position`,`users`,`sessions`,`pageviews`,`conversions`,`citations`,`updated_at` "
            . "FROM {$table} WHERE {$whereSql} ORDER BY `metric_date` DESC,`id` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
        return self::pageResult($rows, $count, $page, $perPage);
    }

    public static function recordGeoObservation(array $input, int $actorId): array
    {
        $engine = strtolower(trim((string)($input['engine'] ?? '')));
        if (!in_array($engine, self::GEO_ENGINES, true)) throw new InvalidArgumentException('engine 不受支持');
        $prompt = self::bounded(trim((string)($input['prompt'] ?? '')), 4000);
        if ($prompt === '') throw new InvalidArgumentException('prompt 不能为空');
        $url = trim((string)($input['cited_url'] ?? ''));
        if ($url !== '') $url = self::sameOriginUrl($url);
        $observedAt = self::dateTime((string)($input['observed_at'] ?? '')) ?: date('Y-m-d H:i:s');
        $position = (int)($input['citation_position'] ?? 0);
        if ($position < 0 || $position > 1000) throw new InvalidArgumentException('citation_position 超出范围');
        $sentiment = strtolower(trim((string)($input['sentiment'] ?? 'neutral')));
        if (!in_array($sentiment, ['positive', 'neutral', 'negative', 'mixed'], true)) throw new InvalidArgumentException('sentiment 无效');
        $row = [
            'engine' => $engine,
            'observed_at' => $observedAt,
            'prompt' => $prompt,
            'prompt_hash' => hash('sha256', $prompt),
            'cited_url' => $url !== '' ? $url : null,
            'cited_url_hash' => $url !== '' ? hash('sha256', $url) : null,
            'citation_position' => $position > 0 ? $position : null,
            'sentiment' => $sentiment,
            'answer_summary' => self::bounded(trim((string)($input['answer_summary'] ?? '')), 4000) ?: null,
            'created_by' => $actorId > 0 ? $actorId : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $row['id'] = Database::table('plugin_search_insights_geo')->insert($row);
        return $row;
    }

    public static function geoReport(array $filters): array
    {
        $engine = strtolower(trim((string)($filters['engine'] ?? '')));
        if ($engine !== '' && !in_array($engine, self::GEO_ENGINES, true)) throw new InvalidArgumentException('engine 不受支持');
        [$dateFrom, $dateTo] = self::dateRange((string)($filters['date_from'] ?? ''), (string)($filters['date_to'] ?? ''), 366);
        $q = self::bounded(trim((string)($filters['q'] ?? '')), 200);
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int)($filters['per_page'] ?? 20)));
        $where = ['`observed_at` >= ? AND `observed_at` < DATE_ADD(?, INTERVAL 1 DAY)'];
        $params = [$dateFrom . ' 00:00:00', $dateTo];
        if ($engine !== '') { $where[] = '`engine` = ?'; $params[] = $engine; }
        if ($q !== '') { $where[] = '(`prompt` LIKE ? OR `cited_url` LIKE ?)'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
        $table = self::table('plugin_search_insights_geo');
        $whereSql = implode(' AND ', $where);
        $count = (int)Database::exec("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params)->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $rows = Database::exec(
            "SELECT `id`,`engine`,`observed_at`,`prompt`,`cited_url`,`citation_position`,`sentiment`,`answer_summary`,`created_at` "
            . "FROM {$table} WHERE {$whereSql} ORDER BY `observed_at` DESC,`id` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
        $summary = Database::exec(
            "SELECT `engine`,COUNT(*) AS `observations`,SUM(CASE WHEN `cited_url` IS NULL THEN 0 ELSE 1 END) AS `citations` "
            . "FROM {$table} WHERE {$whereSql} GROUP BY `engine` ORDER BY `engine`",
            $params
        )->fetchAll();
        return self::pageResult($rows, $count, $page, $perPage) + ['summary' => $summary];
    }

    public static function updateVerification(string $googleToken, string $bingToken, int $actorId): array
    {
        $googleToken = self::verificationToken($googleToken, 'Google');
        $bingToken = self::verificationToken($bingToken, 'Bing');
        set_plugin_setting('search-insights', 'google_verification', $googleToken);
        set_plugin_setting('search-insights', 'bing_verification', $bingToken);
        self::audit('search_insights.verification.update', 'site', $actorId, [
            'google_configured' => $googleToken !== '',
            'bing_configured' => $bingToken !== '',
        ]);
        return ['google_configured' => $googleToken !== '', 'bing_configured' => $bingToken !== ''];
    }

    public static function latestPageSpeed(int $limit = 10): array
    {
        return Database::table('plugin_search_insights_pagespeed')
            ->orderBy('checked_at', 'desc')->limit(max(1, min(50, $limit)))->get();
    }

    public static function urlInspections(array $filters): array
    {
        $q = self::bounded(trim((string)($filters['q'] ?? '')), 200);
        $verdict = strtoupper(self::bounded(trim((string)($filters['verdict'] ?? '')), 80));
        if ($verdict !== '' && preg_match('/^[A-Z][A-Z0-9_ -]{0,79}$/D', $verdict) !== 1) {
            throw new InvalidArgumentException('verdict 无效');
        }
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int)($filters['per_page'] ?? 20)));
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(`url` LIKE ? OR `coverage_state` LIKE ? OR `google_canonical` LIKE ?)';
            for ($i = 0; $i < 3; $i++) $params[] = '%' . $q . '%';
        }
        if ($verdict !== '') { $where[] = 'UPPER(`verdict`) = ?'; $params[] = $verdict; }
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);
        $table = self::table('plugin_search_insights_url_inspections');
        $count = (int)Database::exec("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params)->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $rows = Database::exec(
            "SELECT `id`,`url`,`verdict`,`coverage_state`,`robots_state`,`indexing_state`,`google_canonical`,"
                . "`user_canonical`,`last_crawl_at`,`rich_results_json`,`inspected_at` FROM {$table} "
                . "WHERE {$whereSql} ORDER BY `inspected_at` DESC,`id` DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['rich_results'] = self::jsonObject($row['rich_results_json'] ?? null);
            unset($row['rich_results_json']);
        }
        unset($row);
        return self::pageResult($rows, $count, $page, $perPage);
    }

    private static function syncSearchConsole(string $dateFrom, string $dateTo, array $startCursors = [], ?callable $checkpoint = null): array
    {
        $connection = self::requireGoogleConnected();
        $site = (string)$connection['property_id'];
        $total = 0;
        $truncated = false;
        $dimensionRows = [];
        $nextCursors = [];
        $hasMoreAny = false;
        $perDimensionLimit = max(1, intdiv(self::MAX_SYNC_ROWS, 2));
        foreach (['query', 'page'] as $dimension) {
            $doneKey = $dimension . '_done';
            $offsetKey = $dimension . '_offset';
            if (!empty($startCursors[$doneKey])) {
                $nextCursors[$doneKey] = true;
                continue;
            }
            $offset = max(0, min(1000000000, (int)($startCursors[$offsetKey] ?? 0)));
            $dimensionTotal = 0;
            $dimensionScanned = 0;
            $lastBatchFull = false;
            do {
                if ($checkpoint !== null) $checkpoint();
                $limit = min(250, $perDimensionLimit - $dimensionScanned);
                if ($limit < 1) break;
                $response = self::googleJson(
                    'POST',
                    'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/searchAnalytics/query',
                    [
                        'startDate' => $dateFrom,
                        'endDate' => $dateTo,
                        'dimensions' => ['date', $dimension],
                        'type' => 'web',
                        'dataState' => 'final',
                        'rowLimit' => $limit,
                        'startRow' => $offset,
                    ]
                );
                if ($checkpoint !== null) $checkpoint();
                $rows = is_array($response['rows'] ?? null) ? $response['rows'] : [];
                $lastBatchFull = count($rows) === $limit;
                $dimensionScanned += count($rows);
                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $keys = is_array($row['keys'] ?? null) ? $row['keys'] : [];
                    if (count($keys) < 2) continue;
                    self::storeMetric([
                        'provider' => 'gsc', 'property_key' => self::bounded($site, 191),
                        'metric_date' => self::date((string)$keys[0]), 'dimension' => $dimension,
                        'dimension_key' => self::bounded((string)$keys[1], 2000),
                        'dimension_hash' => hash('sha256', (string)$keys[1]),
                        'clicks' => self::number($row['clicks'] ?? 0, 0, 1e15),
                        'impressions' => self::number($row['impressions'] ?? 0, 0, 1e15),
                        'ctr' => self::number($row['ctr'] ?? 0, 0, 1),
                        'avg_position' => self::number($row['position'] ?? 0, 0, 1e9),
                        'users' => 0, 'sessions' => 0, 'pageviews' => 0, 'conversions' => 0, 'citations' => 0,
                    ]);
                    $total++;
                    $dimensionTotal++;
                }
                $offset += count($rows);
            } while ($lastBatchFull && $dimensionScanned < $perDimensionLimit);
            $dimensionRows[$dimension] = $dimensionTotal;
            $hasMore = $lastBatchFull && $dimensionScanned >= $perDimensionLimit;
            $nextCursors[$doneKey] = !$hasMore;
            $nextCursors[$offsetKey] = $hasMore ? $offset : null;
            if ($hasMore) {
                $truncated = true;
                $hasMoreAny = true;
            }
        }
        return [
            'rows' => $total,
            'dimension_rows' => $dimensionRows,
            'truncated' => $truncated,
            'next_cursors' => $nextCursors,
            'has_more' => $hasMoreAny,
        ];
    }

    private static function syncGa4(string $dateFrom, string $dateTo, int $startOffset = 0, ?callable $checkpoint = null): array
    {
        $connection = self::requireGoogleConnected();
        $config = self::jsonObject($connection['config_json'] ?? null);
        $property = trim((string)($config['ga_property_id'] ?? ''));
        if ($property === '') return ['rows' => 0, 'truncated' => false];
        if (!self::googleHasScope($connection, 'https://www.googleapis.com/auth/analytics.readonly')) {
            throw new RuntimeException('Google OAuth 未授予 GA4 只读权限，请重新授权');
        }
        $total = 0;
        $offset = max(0, min(1000000000, $startOffset));
        $scanned = 0;
        $lastBatchFull = false;
        do {
            if ($checkpoint !== null) $checkpoint();
            $limit = min(500, self::MAX_SYNC_ROWS - $scanned);
            if ($limit < 1) break;
            $response = self::googleJson(
                'POST',
                'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property) . ':runReport',
                [
                    'dateRanges' => [['startDate' => $dateFrom, 'endDate' => $dateTo]],
                    // Query strings can contain PII or bearer/reset tokens. The
                    // normalized report stores only the path dimension.
                    'dimensions' => [['name' => 'date'], ['name' => 'pagePath']],
                    'metrics' => [
                        ['name' => 'activeUsers'], ['name' => 'sessions'],
                        ['name' => 'screenPageViews'], ['name' => 'keyEvents'],
                    ],
                    'limit' => (string)$limit,
                    'offset' => (string)$offset,
                ]
            );
            if ($checkpoint !== null) $checkpoint();
            $rows = is_array($response['rows'] ?? null) ? $response['rows'] : [];
            $lastBatchFull = count($rows) === $limit;
            $scanned += count($rows);
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $dimensions = is_array($row['dimensionValues'] ?? null) ? $row['dimensionValues'] : [];
                $metrics = is_array($row['metricValues'] ?? null) ? $row['metricValues'] : [];
                $date = (string)($dimensions[0]['value'] ?? '');
                if (preg_match('/^[0-9]{8}$/D', $date)) $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
                $key = self::gaPagePath((string)($dimensions[1]['value'] ?? ''));
                if ($key === '') continue;
                self::storeMetric([
                    'provider' => 'ga4', 'property_key' => $property,
                    'metric_date' => self::date($date), 'dimension' => 'page',
                    'dimension_key' => $key, 'dimension_hash' => hash('sha256', $key),
                    'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'avg_position' => 0,
                    'users' => self::number($metrics[0]['value'] ?? 0, 0, 1e15),
                    'sessions' => self::number($metrics[1]['value'] ?? 0, 0, 1e15),
                    'pageviews' => self::number($metrics[2]['value'] ?? 0, 0, 1e15),
                    'conversions' => self::number($metrics[3]['value'] ?? 0, 0, 1e15),
                    'citations' => 0,
                ]);
                $total++;
            }
            $offset += count($rows);
        } while ($lastBatchFull && $scanned < self::MAX_SYNC_ROWS);
        $truncated = $lastBatchFull && $scanned >= self::MAX_SYNC_ROWS;
        return [
            'rows' => $total,
            'truncated' => $truncated,
            'next_cursor' => $truncated ? $offset : null,
        ];
    }

    private static function googleJson(string $method, string $url, ?array $payload): array
    {
        $token = self::googleAccessToken();
        return self::requestJson($method, $url, $payload, ['Authorization' => 'Bearer ' . $token], self::GOOGLE_HOSTS);
    }

    private static function googleRaw(string $method, string $url, string $body): array
    {
        self::assertHost($url, self::GOOGLE_HOSTS);
        return OutboundHttpClient::request($method, $url, $body, [
            'Authorization' => 'Bearer ' . self::googleAccessToken(),
            'Accept' => 'application/json',
        ], 20, 524288);
    }

    private static function googleAccessToken(): string
    {
        $connection = self::requireGoogleConnected();
        $expires = strtotime((string)($connection['token_expires_at'] ?? '')) ?: 0;
        if ($expires > time() + 120 && trim((string)($connection['access_token_envelope'] ?? '')) !== '') {
            return self::decryptSecret((string)$connection['access_token_envelope']);
        }
        $refresh = self::decryptSecret((string)($connection['refresh_token_envelope'] ?? ''));
        if ($refresh === '') throw new RuntimeException('Google refresh token 不可用，请重新授权');
        $response = self::requestForm('https://oauth2.googleapis.com/token', [
            'client_id' => (string)$connection['client_id'],
            'client_secret' => self::decryptSecret((string)$connection['client_secret_envelope']),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '') throw new RuntimeException('Google token 刷新失败');
        Database::table('plugin_search_insights_connections')->where('provider', 'google')->update([
            'access_token_envelope' => Security::encryptApiKey($token),
            'token_expires_at' => date('Y-m-d H:i:s', time() + max(60, (int)($response['expires_in'] ?? 3600))),
            'status' => 'connected', 'last_error_code' => null,
        ]);
        return $token;
    }

    private static function requestForm(string $url, array $payload): array
    {
        self::assertHost($url, self::GOOGLE_HOSTS);
        $result = OutboundHttpClient::request(
            'POST', $url, http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'],
            20, 262144
        );
        if ($result['status'] < 200 || $result['status'] >= 300) throw new RuntimeException('OAuth token 请求失败');
        return self::decodeJson($result['body']);
    }

    private static function requestJson(string $method, string $url, ?array $payload, array $headers, array $hosts): array
    {
        self::assertHost($url, $hosts);
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = OutboundHttpClient::request(
            $method, $url, $body,
            ($payload !== null ? ['Content-Type' => 'application/json'] : []) + $headers,
            25, 1048576
        );
        if ($result['status'] < 200 || $result['status'] >= 300) throw new RuntimeException('上游 API 请求失败');
        return self::decodeJson($result['body']);
    }

    private static function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('上游 API 返回无效 JSON', 0, $error);
        }
        if (!is_array($decoded)) throw new RuntimeException('上游 API 返回格式无效');
        return $decoded;
    }

    private static function storeMetric(array $row): void
    {
        $table = self::table('plugin_search_insights_metrics');
        Database::exec(
            "INSERT INTO {$table} (`provider`,`property_key`,`metric_date`,`dimension`,`dimension_key`,`dimension_hash`,"
            . "`clicks`,`impressions`,`ctr`,`avg_position`,`users`,`sessions`,`pageviews`,`conversions`,`citations`) "
            . "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE "
            . "`dimension_key`=VALUES(`dimension_key`),`clicks`=VALUES(`clicks`),`impressions`=VALUES(`impressions`),"
            . "`ctr`=VALUES(`ctr`),`avg_position`=VALUES(`avg_position`),`users`=VALUES(`users`),"
            . "`sessions`=VALUES(`sessions`),`pageviews`=VALUES(`pageviews`),`conversions`=VALUES(`conversions`),"
            . "`citations`=VALUES(`citations`),`updated_at`=CURRENT_TIMESTAMP",
            [
                $row['provider'], $row['property_key'], $row['metric_date'], $row['dimension'],
                $row['dimension_key'], $row['dimension_hash'], $row['clicks'], $row['impressions'],
                $row['ctr'], $row['avg_position'], $row['users'], $row['sessions'],
                $row['pageviews'], $row['conversions'], $row['citations'],
            ]
        );
    }

    private static function upsert(string $tableName, array $identity, array $data): void
    {
        $query = Database::table($tableName);
        foreach ($identity as $key => $value) $query->where($key, $value);
        $existing = $query->first();
        if ($existing) {
            $update = Database::table($tableName);
            foreach ($identity as $key => $value) $update->where($key, $value);
            $update->update($data);
        } else {
            Database::table($tableName)->insert($data);
        }
    }

    private static function storeMerchantAccountIssue(string $accountId, array $issue, string $seenAt): void
    {
        $contexts = [];
        $countries = [];
        foreach (array_slice((array)($issue['impactedDestinations'] ?? []), 0, 100) as $destination) {
            if (!is_array($destination)) continue;
            $context = self::bounded(strtoupper((string)($destination['reportingContext'] ?? '')), 100);
            if ($context !== '') $contexts[] = $context;
            foreach (array_slice((array)($destination['impacts'] ?? []), 0, 200) as $impact) {
                if (!is_array($impact)) continue;
                $country = strtoupper(self::bounded((string)($impact['regionCode'] ?? ''), 10));
                if ($country !== '') $countries[] = $country;
            }
        }
        $name = self::bounded((string)($issue['name'] ?? ''), 500);
        $segments = array_values(array_filter(explode('/', $name), static fn(string $part): bool => $part !== ''));
        $code = self::bounded((string)(end($segments) ?: $issue['title'] ?? 'account_issue'), 191);
        self::storeMerchantIssue([
            'account_id' => $accountId,
            'scope' => 'account',
            'subject_key' => 'account',
            'issue_code' => $code !== '' ? $code : 'account_issue',
            'title' => self::bounded((string)($issue['title'] ?? $code), 500),
            'detail' => self::bounded((string)($issue['detail'] ?? ''), 4000),
            'severity' => self::merchantSeverity((string)($issue['severity'] ?? '')),
            'resolution' => '',
            'attribute_name' => '',
            'reporting_context' => self::bounded(implode(',', array_values(array_unique($contexts))), 500),
            'countries' => array_values(array_unique($countries)),
            'documentation_url' => self::merchantDocumentationUrl((string)($issue['documentationUri'] ?? '')),
        ], $seenAt);
    }

    private static function storeMerchantProductIssue(
        string $accountId,
        string $offerId,
        array $issue,
        string $seenAt
    ): void {
        $code = self::bounded((string)($issue['code'] ?? 'product_issue'), 191);
        $countries = [];
        foreach (array_slice((array)($issue['applicableCountries'] ?? []), 0, 250) as $country) {
            $country = strtoupper(self::bounded((string)$country, 10));
            if ($country !== '') $countries[] = $country;
        }
        self::storeMerchantIssue([
            'account_id' => $accountId,
            'scope' => 'product',
            'subject_key' => $offerId !== '' ? $offerId : 'unknown_offer',
            'issue_code' => $code !== '' ? $code : 'product_issue',
            'title' => self::bounded((string)($issue['description'] ?? $code), 500),
            'detail' => self::bounded((string)($issue['detail'] ?? ''), 4000),
            'severity' => self::merchantSeverity((string)($issue['severity'] ?? '')),
            'resolution' => self::bounded((string)($issue['resolution'] ?? ''), 100),
            'attribute_name' => self::bounded((string)($issue['attribute'] ?? ''), 191),
            'reporting_context' => self::bounded(strtoupper((string)($issue['reportingContext'] ?? '')), 100),
            'countries' => array_values(array_unique($countries)),
            'documentation_url' => self::merchantDocumentationUrl((string)($issue['documentation'] ?? '')),
        ], $seenAt);
    }

    private static function storeMerchantIssue(array $row, string $seenAt): void
    {
        $identity = implode('|', [
            (string)$row['account_id'], (string)$row['scope'], (string)$row['subject_key'],
            (string)$row['issue_code'], (string)$row['attribute_name'], (string)$row['reporting_context'],
        ]);
        $data = [
            'account_id' => (string)$row['account_id'],
            'scope' => (string)$row['scope'],
            'subject_key' => (string)$row['subject_key'],
            'issue_code' => (string)$row['issue_code'],
            'issue_hash' => hash('sha256', $identity),
            'title' => (string)$row['title'],
            'detail' => (string)$row['detail'],
            'severity' => (string)$row['severity'],
            'resolution' => (string)$row['resolution'] ?: null,
            'attribute_name' => (string)$row['attribute_name'] ?: null,
            'reporting_context' => (string)$row['reporting_context'] ?: null,
            'countries_json' => json_encode((array)$row['countries'], JSON_UNESCAPED_SLASHES),
            'documentation_url' => (string)$row['documentation_url'] ?: null,
            'observed_at' => $seenAt,
            'last_seen_at' => date('Y-m-d H:i:s'),
        ];
        self::upsert('plugin_search_insights_merchant_issues', ['issue_hash' => $data['issue_hash']], $data);
    }

    private static function merchantAccountId(bool $required = true): string
    {
        $connection = self::connection('google');
        $config = self::jsonObject($connection['config_json'] ?? null);
        $accountId = trim((string)($config['merchant_account_id'] ?? ''));
        if ($accountId !== '' && preg_match('/^[0-9]{1,20}$/D', $accountId)) return $accountId;
        if ($required) throw new RuntimeException('请先配置 Merchant Center account ID 并重新完成 Google OAuth 授权');
        return '';
    }

    private static function merchantLanguage(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/D', $value) ? $value : 'zh-CN';
    }

    private static function merchantSeverity(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z][A-Z0-9_]{0,29}$/D', $value) ? $value : 'SEVERITY_UNSPECIFIED';
    }

    private static function merchantDocumentationUrl(string $value): string
    {
        $value = self::bounded($value, 2048);
        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || (string)($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        return $value;
    }

    private static function gaPagePath(string $value): string
    {
        $value = self::bounded($value, 2000);
        if ($value === '' || !str_starts_with($value, '/') || preg_match('/[\x00-\x20\x7F]/', $value)) return '';
        $path = parse_url($value, PHP_URL_PATH);
        return is_string($path) && str_starts_with($path, '/') ? self::bounded($path, 2000) : '';
    }

    private static function freshness(): array
    {
        $rows = Database::exec(
            'SELECT `provider`,MAX(`metric_date`) AS `metric_date`,MAX(`updated_at`) AS `updated_at`,COUNT(*) AS `rows` '
            . 'FROM ' . self::table('plugin_search_insights_metrics') . ' GROUP BY `provider` ORDER BY `provider`'
        )->fetchAll();
        return array_map(static fn(array $row): array => [
            'provider' => (string)$row['provider'], 'metric_date' => (string)$row['metric_date'],
            'updated_at' => (string)$row['updated_at'], 'rows' => (int)$row['rows'],
        ], $rows);
    }

    private static function connection(string $provider): ?array
    {
        try {
            $row = Database::table('plugin_search_insights_connections')->where('provider', $provider)->first();
            return is_array($row) ? $row : null;
        } catch (Throwable $_) {
            return null;
        }
    }

    private static function requireConnection(string $provider): array
    {
        $row = self::connection($provider);
        if (!$row) throw new RuntimeException($provider . ' 连接尚未配置');
        return $row;
    }

    private static function requireGoogleConnected(): array
    {
        $row = self::requireConnection('google');
        if (!in_array((string)($row['status'] ?? ''), ['connected', 'error'], true)
            || trim((string)($row['refresh_token_envelope'] ?? '')) === '') {
            throw new RuntimeException('Google 尚未完成 OAuth 授权');
        }
        if (!self::googleHasScope($row, 'https://www.googleapis.com/auth/webmasters')) {
            throw new RuntimeException('Google OAuth 未授予 Search Console 权限，请重新授权');
        }
        return $row;
    }

    private static function googleHasScope(array $connection, string $scope): bool
    {
        $scopes = json_decode((string)($connection['scopes_json'] ?? ''), true);
        return is_array($scopes) && in_array($scope, $scopes, true);
    }

    /** @param array<string,mixed> $context */
    private static function checkpoint(array $context): ?callable
    {
        $callback = $context['checkpoint'] ?? null;
        return is_callable($callback) ? $callback : null;
    }

    /** 比较 Google OAuth 客户端配置，不记录明文；空 secret 表示沿用后台已有值。 */
    private static function googleOAuthCredentialsChanged(array $existing, string $clientId, string $clientSecretInput): bool
    {
        if ($clientId !== trim((string)($existing['client_id'] ?? ''))) return true;
        if ($clientSecretInput === '') return false;
        try {
            return !hash_equals(
                self::decryptSecret((string)($existing['client_secret_envelope'] ?? '')),
                $clientSecretInput
            );
        } catch (Throwable $_) {
            // An unreadable envelope must not keep stale tokens attached to a new secret.
            return true;
        }
    }

    private static function encryptedSecret(string $plain, string $existing): string
    {
        $plain = trim($plain);
        if ($plain === '') return $existing;
        if (strlen($plain) > 8192 || preg_match('/[\x00-\x1F\x7F]/', $plain)) throw new InvalidArgumentException('凭证格式无效');
        return Security::encryptApiKey($plain);
    }

    private static function decryptSecret(string $stored): string
    {
        return $stored === '' ? '' : trim(Security::decryptApiKey($stored));
    }

    private static function connectionSiteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') $url = function_exists('base_url') ? (string)base_url() : '';
        if (!self::validHttpsUrl($url)) throw new InvalidArgumentException('站点 URL 必须是 HTTPS 完整地址');
        return rtrim($url, '/');
    }

    private static function sameOriginUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') throw new InvalidArgumentException('URL 不能为空');
        if (str_starts_with($url, '/')) $url = url($url);
        if (!self::validHttpsUrl($url)) throw new InvalidArgumentException('URL 必须是 HTTPS 完整地址');
        $base = parse_url((string)base_url());
        $parts = parse_url($url);
        if (!is_array($base) || !is_array($parts)
            || strtolower((string)($base['host'] ?? '')) !== strtolower((string)($parts['host'] ?? ''))
            || (int)($base['port'] ?? 443) !== (int)($parts['port'] ?? 443)) {
            throw new InvalidArgumentException('URL 必须属于当前站点');
        }
        return $url;
    }

    private static function validHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && (string)($parts['host'] ?? '') !== ''
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function assertHost(string $url, array $allowed): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || !in_array($host, $allowed, true)) throw new RuntimeException('上游 API host 不在允许列表');
    }

    private static function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, self::PROVIDERS, true)) throw new InvalidArgumentException('provider 不受支持');
        return $provider;
    }

    private static function dateRange(string $from, string $to, int $maxDays): array
    {
        $to = $to !== '' ? self::date($to) : date('Y-m-d', strtotime('-2 days'));
        $from = $from !== '' ? self::date($from) : date('Y-m-d', strtotime($to . ' -27 days'));
        $start = strtotime($from);
        $end = strtotime($to);
        if ($start === false || $end === false || $start > $end || ($end - $start) / 86400 + 1 > $maxDays) {
            throw new InvalidArgumentException('日期范围无效或超过 ' . $maxDays . ' 天');
        }
        return [$from, $to];
    }

    private static function date(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) throw new InvalidArgumentException('日期必须是 YYYY-MM-DD');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('日期无效');
        return $value;
    }

    private static function dateTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        $time = strtotime($value);
        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    private static function number(mixed $value, float $min, float $max): float
    {
        if (!is_numeric($value)) throw new InvalidArgumentException('指标值必须是数字');
        $number = (float)$value;
        if (!is_finite($number) || $number < $min || $number > $max) throw new InvalidArgumentException('指标值超出范围');
        return $number;
    }

    private static function score(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int)round((float)$value * 100))) : null;
    }

    private static function auditNumber(array $audits, string $key): ?float
    {
        $value = $audits[$key]['numericValue'] ?? null;
        return is_numeric($value) ? round((float)$value, 6) : null;
    }

    private static function richResultSummary(array $rich): array
    {
        $items = [];
        foreach (array_slice((array)($rich['detectedItems'] ?? []), 0, 50) as $item) {
            if (!is_array($item)) continue;
            $items[] = [
                'rich_result_type' => self::bounded((string)($item['richResultType'] ?? ''), 100),
                'items' => count((array)($item['items'] ?? [])),
            ];
        }
        return ['verdict' => self::bounded((string)($rich['verdict'] ?? ''), 80), 'detected' => $items];
    }

    private static function verificationToken(string $value, string $label): string
    {
        $value = trim($value);
        if ($value !== '' && (strlen($value) > 255 || !preg_match('/^[A-Za-z0-9._:-]+$/D', $value))) {
            throw new InvalidArgumentException($label . ' verification token 无效');
        }
        return $value;
    }

    private static function pageResult(array $rows, int $total, int $page, int $perPage): array
    {
        return [
            'data' => array_values($rows), 'total' => $total, 'page' => $page,
            'per_page' => $perPage, 'last_page' => max(1, (int)ceil($total / $perPage)),
            'truncated' => false, 'next_page' => $page * $perPage < $total ? $page + 1 : null,
        ];
    }

    private static function jsonObject(mixed $value): array
    {
        if (is_array($value) && !array_is_list($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    private static function bounded(string $value, int $max): string
    {
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private static function table(string $name): string
    {
        return '`' . str_replace('`', '', Database::prefix() . $name) . '`';
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function googleRedirectUri(): string
    {
        return admin_url('/search-insights/google/callback');
    }

    private static function recordConnectionError(string $provider, string $code): void
    {
        try {
            Database::table('plugin_search_insights_connections')->where('provider', $provider)->update([
                'status' => 'error', 'last_error_code' => self::bounded($code, 100),
            ]);
        } catch (Throwable $_) {}
    }

    private static function errorCode(Throwable $error): string
    {
        return strtolower((new ReflectionClass($error))->getShortName());
    }

    public static function audit(
        string $action,
        string $targetId,
        int $actorId,
        array $metadata,
        string $source = ''
    ): void
    {
        $event = [
            'action' => $action,
            'target_type' => 'search_integration', 'target_id' => $targetId,
            'outcome' => 'success', 'risk' => 'R3', 'actor_type' => 'user',
            'actor_id' => $actorId > 0 ? $actorId : null,
            'summary' => '搜索平台配置已更新', 'metadata' => $metadata,
        ];
        if ($source !== '') $event['source'] = $source;
        AuditTrail::tryRecord($event);
    }
}

final class SearchInsightsApi
{
    public static function status(array $arguments, array $context): array
    {
        return self::ok('搜索平台状态已读取', SearchInsightsService::connectionStatus());
    }

    public static function report(array $arguments, array $context): array
    {
        return self::guard(static fn(): array => self::ok('搜索分析数据已读取', SearchInsightsService::report($arguments)));
    }

    public static function sync(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::sync(
                (string)($arguments['provider'] ?? 'google'),
                (string)($arguments['date_from'] ?? ''),
                (string)($arguments['date_to'] ?? '')
            );
            SearchInsightsService::audit('search_insights.metrics.sync', (string)($arguments['provider'] ?? 'google'), $actorId, $result);
            return self::ok('搜索平台同步完成', $result);
        });
    }

    public static function inspectUrl(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::inspectUrl(
                (string)($arguments['url'] ?? ''), (string)($arguments['language_code'] ?? 'zh-CN')
            );
            SearchInsightsService::audit('search_insights.url.inspect', (string)($result['url_hash'] ?? 'url'), $actorId, [
                'url' => (string)($result['url'] ?? ''), 'verdict' => (string)($result['verdict'] ?? ''),
            ]);
            return self::ok('URL Inspection 完成', $result);
        });
    }

    public static function submitSitemap(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::submitSitemap((string)($arguments['sitemap_url'] ?? ''));
            SearchInsightsService::audit('search_insights.sitemap.submit', hash('sha256', (string)$result['sitemap_url']), $actorId, [
                'sitemap_url' => (string)$result['sitemap_url'], 'status' => (int)$result['status'],
            ]);
            return self::ok('Sitemap 已提交', $result);
        });
    }

    public static function pageSpeed(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::pageSpeed(
                (string)($arguments['url'] ?? ''), (string)($arguments['strategy'] ?? 'mobile')
            );
            SearchInsightsService::audit('search_insights.pagespeed.run', (string)($result['url_hash'] ?? 'url'), $actorId, [
                'url' => (string)($result['url'] ?? ''), 'strategy' => (string)($result['strategy'] ?? ''),
            ]);
            return self::ok('PageSpeed 检查完成', $result);
        });
    }

    public static function importMetric(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::importMetric($arguments);
            SearchInsightsService::audit('search_insights.metric.import', hash('sha256', (string)$result['provider'] . '|' . (string)$result['metric_date'] . '|' . (string)$result['dimension_key']), $actorId, [
                'provider' => (string)$result['provider'], 'metric_date' => (string)$result['metric_date'],
                'dimension' => (string)$result['dimension'],
            ]);
            return self::ok('指标已导入', $result, 201);
        });
    }

    public static function geoReport(array $arguments, array $context): array
    {
        return self::guard(static fn(): array => self::ok('GEO 引用数据已读取', SearchInsightsService::geoReport($arguments)));
    }

    public static function recordGeoObservation(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::recordGeoObservation($arguments, $actorId);
            SearchInsightsService::audit('search_insights.geo.observe', (string)($result['id'] ?? ''), $actorId, [
                'engine' => (string)($result['engine'] ?? ''),
                'cited' => !empty($result['cited_url']),
                'observed_at' => (string)($result['observed_at'] ?? ''),
            ]);
            return self::ok('GEO 引用观测已记录', $result, 201);
        });
    }

    public static function merchantDiagnostics(array $arguments, array $context): array
    {
        return self::guard(static fn(): array => self::ok(
            'Merchant Center 诊断已读取',
            SearchInsightsService::merchantDiagnostics($arguments)
        ));
    }

    public static function urlInspections(array $arguments, array $context): array
    {
        return self::guard(static fn(): array => self::ok(
            'URL Inspection 历史已读取',
            SearchInsightsService::urlInspections($arguments)
        ));
    }

    public static function syncMerchantDiagnostics(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static function () use ($arguments, $actorId): array {
            $result = SearchInsightsService::syncMerchantDiagnostics(
                (string)($arguments['language_code'] ?? 'zh-CN'),
                (int)($arguments['max_products'] ?? 100)
            );
            SearchInsightsService::audit(
                'search_insights.merchant.sync',
                (string)($result['account_id'] ?? 'merchant'),
                $actorId,
                [
                    'account_issues' => (int)($result['account_issues'] ?? 0),
                    'products_scanned' => (int)($result['products_scanned'] ?? 0),
                    'product_issues' => (int)($result['product_issues'] ?? 0),
                    'cycle_complete' => !empty($result['cycle_complete']),
                    'truncated' => !empty($result['truncated']),
                ]
            );
            return self::ok('Merchant Center 诊断同步完成', $result);
        });
    }

    public static function updateVerification(array $arguments, array $context): array
    {
        $actorId = (int)($context['user']['id'] ?? 0);
        return self::guard(static fn(): array => self::ok('站点验证标签已更新', SearchInsightsService::updateVerification(
            (string)($arguments['google_token'] ?? ''), (string)($arguments['bing_token'] ?? ''), $actorId
        )));
    }

    private static function guard(callable $callback): array
    {
        try {
            return $callback();
        } catch (InvalidArgumentException $error) {
            return ['ok' => false, 'message' => $error->getMessage(), 'error_code' => 'validation_error', 'http_status' => 422];
        } catch (Throwable $error) {
            return ['ok' => false, 'message' => '搜索平台操作失败，请检查连接状态', 'error_code' => 'search_integration_failed', 'http_status' => 503];
        }
    }

    private static function ok(string $message, array $data, int $status = 200): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data, 'http_status' => $status];
    }
}
