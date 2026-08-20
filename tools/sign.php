<?php
declare(strict_types=1);

/**
 * 对 index.json / revoked.json 做 RSA-SHA256 签名。
 * 用法：php tools/sign.php <私钥路径>
 * 产出：index.json.sig、revoked.json.sig（base64）
 */
if ($argc < 2) { fwrite(STDERR, "用法：php tools/sign.php <私钥路径>\n"); exit(1); }
$keyPath = $argv[1];
$root = dirname(__DIR__);

$privateKey = openssl_pkey_get_private((string)file_get_contents($keyPath));
if ($privateKey === false) { fwrite(STDERR, "无法读取私钥\n"); exit(1); }

foreach (['index.json', 'revoked.json'] as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) continue;
    $signature = '';
    if (!openssl_sign((string)file_get_contents($path), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        fwrite(STDERR, "签名失败：$file\n");
        exit(1);
    }
    file_put_contents($path . '.sig', base64_encode($signature));
    fwrite(STDOUT, "已签名：$file.sig\n");
}
