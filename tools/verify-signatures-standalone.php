<?php
declare(strict_types=1);

/**
 * Verify the published marketplace documents without loading ODCMS.
 *
 * This is the public-repository gate used by CI. It deliberately trusts only
 * index.json.pub from this checkout and performs the same RSA-SHA256 operation
 * as the CMS verifier. The local verify-signatures.php additionally compares
 * the repository key with ODCMS's built-in trust anchor.
 *
 * @return list<string>
 */
function verifyMarketplaceSignaturesStandalone(string $root): array
{
    if (!extension_loaded('openssl')) {
        return ['The PHP OpenSSL extension is required.'];
    }

    $errors = [];
    $publicKeyPath = rtrim($root, '/\\') . '/index.json.pub';
    $publicKeyPem = is_file($publicKeyPath) ? file_get_contents($publicKeyPath) : false;
    $publicKey = $publicKeyPem !== false ? @openssl_pkey_get_public($publicKeyPem) : false;
    $details = $publicKey !== false ? openssl_pkey_get_details($publicKey) : false;
    if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
        $errors[] = 'index.json.pub must be a readable RSA public key.';
        $publicKey = false;
        $details = false;
    }
    // This fingerprint is the CMS built-in trust anchor. It keeps the public
    // repository gate aligned with production even when the private CMS source
    // is unavailable to GitHub Actions.
    $der = $publicKeyPem !== false
        ? base64_decode((string)preg_replace('/-----[^-]+-----|\s+/', '', $publicKeyPem), true)
        : false;
    if ($der === false || hash('sha256', $der) !== '3adc78476213971ff2dcca22ca970a8aeb31b717a1cdcd8b28cb8bb1b8fdebfd') {
        $errors[] = 'index.json.pub does not match the ODCMS built-in public-key fingerprint.';
    }
    $signatureBytes = $details !== false
        ? (int)ceil((int)($details['bits'] ?? 0) / 8)
        : 0;

    foreach (['index.json', 'revoked.json'] as $file) {
        $path = rtrim($root, '/\\') . '/' . $file;
        $signaturePath = $path . '.sig';
        $body = is_file($path) ? file_get_contents($path) : false;
        $encoded = is_file($signaturePath) ? file_get_contents($signaturePath) : false;
        if ($body === false || $encoded === false) {
            $errors[] = $file . ' and its .sig file are both required.';
            continue;
        }

        try {
            $document = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($document)) {
                $errors[] = $file . ' must contain a JSON object.';
            } else {
                foreach (marketplaceDocumentErrors($file, $document) as $error) {
                    $errors[] = $error;
                }
            }
        } catch (JsonException $error) {
            $errors[] = $file . ' is not valid JSON: ' . $error->getMessage();
        }

        $encoded = trim($encoded);
        if ($encoded === '' || strlen($encoded) % 4 !== 0
            || preg_match('/\A[A-Za-z0-9+\/]*={0,2}\z/D', $encoded) !== 1) {
            $errors[] = $file . '.sig must be Base64 text (not a binary signature).';
            continue;
        }
        $signature = base64_decode($encoded, true);
        if ($signature === false || $signature === '') {
            $errors[] = $file . '.sig is not valid Base64.';
            continue;
        }
        if ($signatureBytes > 0 && strlen($signature) !== $signatureBytes) {
            $errors[] = $file . '.sig has an unexpected RSA signature length.';
            continue;
        }
        if ($publicKey === false || !is_string($body)) {
            continue;
        }
        $verified = openssl_verify($body, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            $errors[] = $file . '.sig failed RSA-SHA256 verification.';
        }
    }
    return $errors;
}

/** @return list<string> */
function marketplaceDocumentErrors(string $file, array $document): array
{
    $errors = [];
    if ((int)($document['schema_version'] ?? 0) !== 1) {
        $errors[] = $file . ' must use schema_version 1.';
    }
    if ($file === 'index.json') {
        $source = $document['source'] ?? null;
        if (!is_array($source) || !in_array((string)($source['type'] ?? ''), ['github-static', 'registry'], true)) {
            $errors[] = 'index.json source.type must be github-static or registry.';
        }
        if (!is_array($document['plugins'] ?? null)) {
            $errors[] = 'index.json plugins must be an array.';
        }
    } elseif (!is_array($document['entries'] ?? null)) {
        $errors[] = 'revoked.json entries must be an array.';
    }
    return $errors;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if ($argc > 2) {
        fwrite(STDERR, "Usage: php tools/verify-signatures-standalone.php [marketplace-directory]\n");
        exit(1);
    }
    $root = $argc === 2 ? $argv[1] : dirname(__DIR__);
    $errors = verifyMarketplaceSignaturesStandalone($root);
    if ($errors !== []) {
        fwrite(STDERR, implode("\n", $errors) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: marketplace JSON and Base64 RSA-SHA256 signatures are valid.\n");
}
