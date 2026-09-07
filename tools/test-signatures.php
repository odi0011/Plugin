<?php
declare(strict_types=1);

require_once __DIR__ . '/verify-signatures-standalone.php';

if ($argc > 2) {
    fwrite(STDERR, "Usage: php tools/test-signatures.php [odcms-source-directory]\n");
    exit(1);
}

$cmsRoot = $argc === 2 ? $argv[1] : null;
if ($cmsRoot !== null) {
    require_once __DIR__ . '/verify-signatures.php';
    loadMarketplaceVerifier($cmsRoot);
}

$fixture = sys_get_temp_dir() . '/odcms-signatures-' . bin2hex(random_bytes(8));
$files = ['index.json', 'index.json.sig', 'index.json.pub', 'revoked.json', 'revoked.json.sig'];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
};

try {
    $verify = static fn (string $directory): array => $cmsRoot === null
        ? verifyMarketplaceSignaturesStandalone($directory)
        : verifyMarketplaceSignatures($directory);
    if (!mkdir($fixture, 0700)) {
        throw new RuntimeException('Cannot create the temporary fixture directory.');
    }
    foreach ($files as $file) {
        if (!copy(dirname(__DIR__) . '/' . $file, $fixture . '/' . $file)) {
            throw new RuntimeException('Cannot copy fixture: ' . $file);
        }
    }
    $assert($verify($fixture) === [], 'Published signatures must pass.');

    foreach (['index.json', 'revoked.json'] as $file) {
        $path = $fixture . '/' . $file;
        $body = (string)file_get_contents($path);
        $signature = (string)file_get_contents($path . '.sig');
        $binary = base64_decode(trim($signature), true);
        $assert($binary !== false && $binary !== '', $file . ' must have a Base64 signature.');

        file_put_contents($path . '.sig', $binary);
        $assert($verify($fixture) !== [], $file . ' must reject binary signatures.');
        file_put_contents($path . '.sig', $signature);

        file_put_contents($path, $body . "\n");
        $assert($verify($fixture) !== [], $file . ' must reject changed document bytes.');
        file_put_contents($path, $body);

        unlink($path . '.sig');
        $assert($verify($fixture) !== [], $file . ' must reject a missing signature.');
        file_put_contents($path . '.sig', $signature);
    }

    // Change one byte inside the RSA modulus, preserving a parseable public key.
    $pem = (string)file_get_contents($fixture . '/index.json.pub');
    $keyData = preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    $der = base64_decode((string)$keyData, true);
    $details = openssl_pkey_get_details(openssl_pkey_get_public($pem));
    $modulus = $details['rsa']['n'];
    $offset = strpos($der, $modulus);
    $assert($offset !== false, 'The public key must contain the expected RSA modulus.');
    $der[$offset + 1] = chr(ord($der[$offset + 1]) ^ 1);
    $wrongKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
    $assert(openssl_pkey_get_public($wrongKey) !== false, 'The wrong-key fixture must remain valid PEM.');
    file_put_contents($fixture . '/index.json.pub', $wrongKey);
    $assert($verify($fixture) !== [], 'A different repository key must fail.');
    if ($cmsRoot !== null) {
        $assert(empty(App\Core\PluginMarketplaceService::verifyWithKey(
            (string)file_get_contents($fixture . '/index.json'),
            (string)file_get_contents($fixture . '/index.json.sig'),
            $wrongKey
        )['ok']), 'A signature must fail with a different RSA key.');
    }
    file_put_contents($fixture . '/index.json.pub', $pem);
    $assert($verify($fixture) === [], 'Restored artifacts must pass.');
    fwrite(STDOUT, 'PASS: ' . $checks . " signature contract checks.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($files as $file) {
        if (is_file($fixture . '/' . $file)) {
            unlink($fixture . '/' . $file);
        }
    }
    if (is_dir($fixture)) {
        rmdir($fixture);
    }
}
