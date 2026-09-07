<?php
declare(strict_types=1);

require_once __DIR__ . '/verify-signatures-standalone.php';

use App\Core\PluginMarketplaceService;

/** @return list<string> */
function verifyMarketplaceSignatures(string $root): array
{
    $errors = verifyMarketplaceSignaturesStandalone($root);
    $publicKeyPath = $root . '/index.json.pub';
    $publicKeyPem = is_file($publicKeyPath) ? file_get_contents($publicKeyPath) : false;
    $publicKey = $publicKeyPem !== false ? @openssl_pkey_get_public($publicKeyPem) : false;
    $trustedKey = @openssl_pkey_get_public(PluginMarketplaceService::publicKeyPem());
    $publicDetails = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
    $trustedDetails = $trustedKey !== false ? openssl_pkey_get_details($trustedKey) : false;
    if ($publicDetails === false || $trustedDetails === false
        || $publicDetails['type'] !== OPENSSL_KEYTYPE_RSA
        || $trustedDetails['type'] !== OPENSSL_KEYTYPE_RSA
        || !hash_equals($trustedDetails['key'], $publicDetails['key'])) {
        $errors[] = 'index.json.pub must match the RSA public key built into ODCMS.';
    }

    foreach (['index.json', 'revoked.json'] as $file) {
        $path = $root . '/' . $file;
        $body = is_file($path) ? file_get_contents($path) : false;
        $signature = is_file($path . '.sig') ? file_get_contents($path . '.sig') : false;
        if ($body === false || $signature === false) {
            continue;
        }
        $verified = PluginMarketplaceService::verifySignature($body, $signature);
        if (empty($verified['ok'])) {
            $errors[] = $file . '.sig must be a valid Base64 RSA-SHA256 signature: '
                . (string)($verified['error'] ?? 'verification failed');
        }
    }
    return $errors;
}

/**
 * Keep this file safe to include from the regression script. The CLI entry point
 * below is intentionally the only place that reads argv or writes output.
 */

function loadMarketplaceVerifier(string $cmsRoot): void
{
    $service = rtrim($cmsRoot, '/\\') . '/app/core/PluginMarketplaceService.php';
    if (!is_file($service)) {
        throw new RuntimeException('ODCMS source not found at ' . $service);
    }
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('The PHP OpenSSL extension is required.');
    }
    require_once $service;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if ($argc !== 2) {
        fwrite(STDERR, "Usage: php tools/verify-signatures.php <odcms-source-directory>\n");
        exit(1);
    }
    try {
        loadMarketplaceVerifier($argv[1]);
        $errors = verifyMarketplaceSignatures(dirname(__DIR__));
        if ($errors !== []) {
            fwrite(STDERR, implode("\n", $errors) . "\n");
            exit(1);
        }
        fwrite(STDOUT, "PASS: index.json, revoked.json and index.json.pub match the ODCMS signature contract.\n");
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
