<?php
/**
 * 可视化编辑器：插件自有存储（1.2.0 新增）。
 *
 * 1.1.0 把编辑树塞进核心内容字段里的 <script data-ve-tree>。它能用，但两件事
 * 让人不放心：字段体积随树翻倍，而且用户的**原始内容**一旦被渲染产物覆盖就找不回来了。
 *
 * 1.2.0 起分成两处存：
 *   - 核心内容字段：只写渲染产物（结构 HTML + 作用域 CSS），是干净的静态 HTML。
 *     插件停用或卸载后，页面照原样显示，没有任何依赖插件的东西残留。
 *   - 插件目录（本类）：编辑树 + 首次接管前的原始内容备份 + 校验用哈希。
 *     JSON 单文件，一个内容源一个：STORAGE_PATH/visual-editor/page-12.json
 *
 * 用文件而不是建表：插件不该为了自己的中间态去动用户的数据库；卸载时删一个目录就干净了。
 * 目录里只有本类写出的 JSON，文件名由 sourceKey 决定，形态被 safeKey() 收紧到
 * ^[a-z][a-z0-9_-]{1,29}-[1-9][0-9]{0,9}$，路径穿越无从下手。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorStore
{
    /** 记录格式版本。读到更高的版本就当读不到，避免旧代码写坏新结构。 */
    public const RECORD_VERSION = 1;

    /** 单个文档记录的字节上限：树本身有 MAX_SECTIONS / MAX_WIDGETS_TOTAL 兜着，这里只防病态输入。 */
    private const MAX_BYTES = 4194304;

    /** 存储根目录。不存在时创建（0700：只有站点进程需要读它）。 */
    public static function dir(): string
    {
        $base = defined('STORAGE_PATH') ? rtrim((string)STORAGE_PATH, "/\\") : sys_get_temp_dir();
        return $base . DIRECTORY_SEPARATOR . 'visual-editor';
    }

    /** 某个源的记录路径。sourceKey 不合法时返回 null——调用方一律当作「没有记录」。 */
    public static function path(string $sourceKey): ?string
    {
        if (VisualEditorStyleCompiler::safeKey($sourceKey) !== $sourceKey) return null;
        return self::dir() . DIRECTORY_SEPARATOR . $sourceKey . '.json';
    }

    /**
     * 读取记录。任何异常（文件缺失 / JSON 坏 / 版本不认识）都返回 null，
     * 让上层退回到「从核心字段导入」这条永远可行的路径上。
     *
     * @return array{version:int,source_key:string,tree:array,original:array,rendered_hash:string,updated_at:string}|null
     */
    public static function load(string $sourceKey): ?array
    {
        $path = self::path($sourceKey);
        if ($path === null || !is_file($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES) return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        if ((int)($data['version'] ?? 0) !== self::RECORD_VERSION) return null;
        if (!is_array($data['tree'] ?? null)) return null;
        return [
            'version' => self::RECORD_VERSION,
            'source_key' => (string)($data['source_key'] ?? $sourceKey),
            'tree' => VisualEditorDocumentShape::normalize($data['tree']),
            'original' => is_array($data['original'] ?? null) ? $data['original'] : [],
            'rendered_hash' => (string)($data['rendered_hash'] ?? ''),
            'updated_at' => (string)($data['updated_at'] ?? ''),
        ];
    }

    /** 只要树，取不到给 null。读路径上最常用的一个便捷封装。 */
    public static function tree(string $sourceKey): ?array
    {
        $record = self::load($sourceKey);
        return $record === null ? null : $record['tree'];
    }

    /**
     * 写入记录。
     *
     * $originalContent 只在**首次**接管时写进备份：之后每次保存传进来的都是上一次的
     * 渲染产物，如果每次都覆盖，用户最初那份手写内容第二次保存就没了。
     *
     * @param string $renderedHtml 本次写进核心字段的结构 HTML，存哈希用于判断字段是否被外部改过
     */
    public static function save(string $sourceKey, array $tree, string $renderedHtml, ?string $originalContent = null): bool
    {
        $path = self::path($sourceKey);
        if ($path === null) return false;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
        self::hardenDir($dir);

        $existing = self::load($sourceKey);
        $original = is_array($existing['original'] ?? null) ? $existing['original'] : [];
        if (($original['content'] ?? null) === null && $originalContent !== null) {
            $original = [
                'content' => $originalContent,
                'hash' => hash('sha256', $originalContent),
                'bytes' => strlen($originalContent),
                'captured_at' => gmdate('c'),
            ];
        }

        $payload = [
            'version' => self::RECORD_VERSION,
            'source_key' => $sourceKey,
            'tree' => $tree,
            'original' => $original,
            'rendered_hash' => hash('sha256', trim($renderedHtml)),
            'updated_at' => gmdate('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES) return false;

        // 同目录临时文件 + rename：并发保存下读到的永远是完整 JSON，不会是半个文件。
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** 渲染产物哈希是否与核心字段当前内容一致——不一致说明有人在别的模式里改过。 */
    public static function matchesRendered(string $sourceKey, string $renderedHtml): bool
    {
        $record = self::load($sourceKey);
        if ($record === null || $record['rendered_hash'] === '') return false;
        return hash_equals($record['rendered_hash'], hash('sha256', trim($renderedHtml)));
    }

    /** 首次接管前的原始内容。没有备份返回 null。 */
    public static function original(string $sourceKey): ?string
    {
        $record = self::load($sourceKey);
        $content = $record['original']['content'] ?? null;
        return is_string($content) ? $content : null;
    }

    /** 删除某个源的记录。 */
    public static function forget(string $sourceKey): void
    {
        $path = self::path($sourceKey);
        if ($path !== null && is_file($path)) @unlink($path);
    }

    /** 清空整个存储目录。uninstall.php 用；只删本类写出的 .json 与残留 .tmp。 */
    public static function purge(): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) return 0;
        $removed = 0;
        foreach ((array)@scandir($dir) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') continue;
            if (!preg_match('/\.(json|tmp)$/', $name)) continue;
            if (@unlink($dir . DIRECTORY_SEPARATOR . $name)) $removed++;
        }
        @unlink($dir . DIRECTORY_SEPARATOR . '.htaccess');
        @unlink($dir . DIRECTORY_SEPARATOR . 'index.html');
        @rmdir($dir);
        return $removed;
    }

    /**
     * 目录防护。STORAGE_PATH 通常在 web 根之外，但站点部署千差万别，
     * 多写这两个文件的成本几乎为零，换来「就算被放进可访问路径也拿不到」。
     */
    private static function hardenDir(string $dir): void
    {
        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!is_file($index)) @file_put_contents($index, '');
    }
}
