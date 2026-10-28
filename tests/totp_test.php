<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/common.php';

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
if (!hash_equals('287082', totpCode($secret, 59))) {
    fwrite(STDERR, "TOTP RFC test failed\n");
    exit(1);
}
if (!verifyTotp($secret, totpCode($secret, time()))) {
    fwrite(STDERR, "Current-step TOTP verification failed\n");
    exit(1);
}
if (verifyTotp($secret, 'abcdef')) {
    fwrite(STDERR, "Malformed TOTP code was accepted\n");
    exit(1);
}
$original = 'TEST-TOTP-SECRET';
$encrypted = encryptTotpSecret($original);
if (!hash_equals($original, decryptTotpSecret($encrypted))) {
    fwrite(STDERR, "TOTP secret encryption round-trip failed\n");
    exit(1);
}
fwrite(STDOUT, "TOTP tests passed\n");
