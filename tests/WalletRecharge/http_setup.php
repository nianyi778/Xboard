<?php

declare(strict_types=1);

require __DIR__ . '/Support.php';

$db = getenv('WR_DB');
$key = getenv('WR_KEY');
$cloudKey = getenv('WR_CLOUD_KEY');
$payStore = getenv('WR_PAY_STORE');
if (!$db || !$key || !$cloudKey || !$payStore) {
    fwrite(STDERR, "WR_DB/WR_KEY/WR_CLOUD_KEY/WR_PAY_STORE required\n");
    exit(2);
}
foreach ([$db, $db . '-wal', $db . '-shm', $payStore] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}
file_put_contents($key, WR_PROBE_SECRET);
file_put_contents($cloudKey, 'cloud-wallet-probe-secret-0123456789abcdef');
file_put_contents($payStore, '{}');
touch($db);

wr_boot_paths($db, $key, $cloudKey);
wr_init_database();
wr_seed_user(5001, 100);
echo "ready\n";
