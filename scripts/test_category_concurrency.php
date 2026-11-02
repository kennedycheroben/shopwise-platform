<?php
/**
 * ShopWise Platform - Multi-Process Category Concurrency Test
 *
 * Verifies that concurrent attempts to create categories with identical names
 * are safely serialized/resolved by CategoryService unique slug generator
 * and MariaDB uq_categories_slug unique constraint without crashes or duplicate collisions.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

putenv('SHOPWISE_CONFIG=testing');
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = '127.0.0.1';

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database\Database;
use App\Services\CategoryService;

$mode = $argv[1] ?? 'coordinator';

if ($mode === 'worker') {
    $workerId = (int)($argv[2] ?? 0);
    $barrierFile = $argv[3] ?? '';
    $categoryName = $argv[4] ?? 'Concurrent Race Shoes';

    // Wait for start barrier signal
    while (!file_exists($barrierFile)) {
        usleep(500);
    }

    try {
        $catId = CategoryService::create([
            'name'        => $categoryName,
            'description' => "Worker {$workerId} concurrent category",
        ], 1, '127.0.0.1');

        $cat = CategoryService::find($catId);
        echo json_encode([
            'worker'  => $workerId,
            'success' => true,
            'id'      => $catId,
            'slug'    => $cat['slug'] ?? null,
        ]) . "\n";
    } catch (\Throwable $e) {
        echo json_encode([
            'worker'  => $workerId,
            'success' => false,
            'error'   => $e->getMessage(),
        ]) . "\n";
    }
    exit(0);
}

// Coordinator mode
$pdo = Database::getInstance();
$activeDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($activeDb !== 'shopwise_test') {
    fwrite(STDERR, "FATAL: Concurrency test must ONLY run on shopwise_test. Connected to {$activeDb}\n");
    exit(1);
}

$numWorkers = 6;
$categoryName = "Concurrent Race " . uniqid();
$barrierFile = tempnam(sys_get_temp_dir(), 'cat_barrier_');
unlink($barrierFile); // Remove so workers wait

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$processes = [];
$pipes = [];

for ($i = 1; $i <= $numWorkers; $i++) {
    $cmd = sprintf(
        'SHOPWISE_CONFIG=testing php %s worker %d %s %s',
        escapeshellarg(__FILE__),
        $i,
        escapeshellarg($barrierFile),
        escapeshellarg($categoryName)
    );
    $proc = proc_open($cmd, $descriptors, $pipes[$i]);
    if (is_resource($proc)) {
        $processes[$i] = $proc;
    }
}

// Brief pause to allow all worker processes to spin up and await barrier
usleep(100000); // 100ms

// Signal barrier release
touch($barrierFile);

$results = [];
$createdIds = [];

foreach ($processes as $i => $proc) {
    $stdout = stream_get_contents($pipes[$i][1]);
    $stderr = stream_get_contents($pipes[$i][2]);
    fclose($pipes[$i][0]);
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    proc_close($proc);

    $parsed = json_decode(trim($stdout), true);
    if ($parsed) {
        $results[] = $parsed;
        if (!empty($parsed['id'])) {
            $createdIds[] = (int)$parsed['id'];
        }
    } else {
        $results[] = [
            'worker'  => $i,
            'success' => false,
            'error'   => "Raw output: {$stdout} | Stderr: {$stderr}",
        ];
    }
}

@unlink($barrierFile);

// Cleanup created test categories from test database
if (!empty($createdIds)) {
    $inList = implode(',', $createdIds);
    $pdo->query("DELETE FROM categories WHERE id IN ({$inList})");
}

$slugs = [];
$successCount = 0;
$uniqueSlugs = 0;

foreach ($results as $res) {
    if ($res['success']) {
        $successCount++;
        $slugs[] = $res['slug'];
    }
}

$uniqueSlugs = count(array_unique($slugs));

echo json_encode([
    'total_workers' => $numWorkers,
    'success_count' => $successCount,
    'unique_slugs'  => $uniqueSlugs,
    'slugs'         => $slugs,
    'all_unique'    => ($successCount === $uniqueSlugs && $successCount === $numWorkers),
]) . "\n";

