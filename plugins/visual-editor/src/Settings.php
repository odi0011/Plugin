<?php
/**
 * 可视化编辑器：设置读取。
 *
 * 清单里的 settings 段由核心渲染表单并写入 settings 表，这里只负责**读**——
 * 而且每个读取点都自带边界钳制。理由是设置值可能被别的途径改过（导入、直改库），
 * 渲染时再钳一次，坏值最多让页面回到默认布局，不会编译出无效 CSS。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorSettings
{
    public const SLUG = 'visual-editor';

    /** 前台路径前缀，已去掉首尾斜杠。空串表示挂在站点根下。 */
    public static function urlPrefix(): string
    {
        $prefix = strtolower(trim((string)\get_plugin_setting(self::SLUG, 'url_prefix', '')));
        $prefix = trim($prefix, '/');
        return preg_match('/^[a-z0-9][a-z0-9-]{0,38}[a-z0-9]?$/', $prefix) === 1 ? $prefix : '';
    }

    public static function revisionLimit(): int
    {
        return self::intSetting('revision_limit', 20, 3, 100);
    }

    public static function containerMax(): int
    {
        return self::intSetting('container_max', 1200, 600, 2400);
    }

    /**
     * 两个断点一起返回，因为它们有互相约束：手机断点必须严格小于平板断点。
     * 单独钳制会出现「两个都在自己范围内、合起来无意义」的组合。
     *
     * @return array{tablet:int,mobile:int}
     */
    public static function breakpoints(): array
    {
        $tablet = self::intSetting('breakpoint_tablet', 1024, 768, 1600);
        $mobile = self::intSetting('breakpoint_mobile', 767, 320, 1024);
        if ($mobile >= $tablet) {
            return ['tablet' => 1024, 'mobile' => 767];
        }
        return ['tablet' => $tablet, 'mobile' => $mobile];
    }

    private static function intSetting(string $key, int $default, int $min, int $max): int
    {
        $raw = \get_plugin_setting(self::SLUG, $key, (string)$default);
        if (!is_numeric($raw)) return $default;
        return max($min, min($max, (int)round((float)$raw)));
    }
}
