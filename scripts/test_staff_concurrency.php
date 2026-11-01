<?php
/**
 * ShopWise Kenya - Genuine StaffService Service-Level Concurrency Test
 *
 * Implements an explicit two-phase synchronization barrier ensuring genuine
 * overlapping execution of StaffService operations across independent PHP processes.
 *
 * Strictly executes against shopwise_test.
 */
putenv('SHOPWISE_CONFIG=testing');
$_SERVER['SHOPWISE_CONFIG'] = 'testing';
require __DIR__ . '/../app/bootstrap.php';

use App\Database\Database;
use App\Services\StaffService;

$pdo = Database::getInstance();
$activeDb = $pdo->query("SELECT DATABASE()")->fetchColumn();
if ($activeDb !== 'shopwise_test') {
    fwrite(STDERR, "FATAL: Must only run on shopwise_test. Connected to: $activeDb\n");
    exit(1);
}

$mode = $argv[1] ?? 'coordinator';

// ==============================================================================
// WORKER PROCESS
// ==============================================================================
if ($mode === 'worker') {
    $workerId   = (int)$argv[2];
    $targetId   = (int)$argv[3];
    $actorId    = (int)$argv[4];
    $actionType = $argv[5]; // 'update' or 'toggle'
    $barrierDir = $argv[6];

    $readyFile   = $barrierDir . "/worker_{$workerId}.ready";
    $releaseFile = $barrierDir . "/release.go";

    // 1. Verify DB connection is active and responsive
    $dbCheck = $pdo->query("SELECT 1")->fetchColumn();
    if ((int)$dbCheck !== 1) {
        echo json_encode(['worker' => $workerId, 'status' => 'error', 'message' => 'DB connection unavailable']);
        exit(1);
    }

    // 2. Signal readiness at the synchronization barrier
    $readyTime = microtime(true);
    file_put_contents($readyFile, json_encode(['worker' => $workerId, 'ready_at' => $readyTime]));

    // 3. Wait at barrier until coordinator releases both workers
    $waitTimeout = 5.0; // 5 seconds maximum wait
    $startTime = microtime(true);
    $released = false;

    while ((microtime(true) - $startTime) < $waitTimeout) {
        if (file_exists($releaseFile)) {
            $released = true;
            break;
        }
        usleep(1000); // 1ms sleep
    }

    if (!$released) {
        echo json_encode(['worker' => $workerId, 'status' => 'error', 'message' => 'Barrier timeout waiting for release']);
        exit(1);
    }

    // 4. Execute the actual StaffService method
    $execStart = microtime(true);
    try {
        if ($actionType === 'update') {
            StaffService::update($targetId, ['roles' => ['staff']], $actorId, '127.0.0.1');
            $execEnd = microtime(true);
            echo json_encode([
                'worker'     => $workerId,
                'status'     => 'success',
                'action'     => 'update',
                'target'     => $targetId,
                'exec_start' => $execStart,
                'exec_end'   => $execEnd,
                'duration'   => ($execEnd - $execStart),
            ]);
            exit(0);
        } else {
            $newStatus = StaffService::toggleStatus($targetId, $actorId, '127.0.0.1');
            $execEnd = microtime(true);
            echo json_encode([
                'worker'     => $workerId,
                'status'     => 'success',
                'action'     => 'toggle',
                'target'     => $targetId,
                'new_status' => $newStatus,
                'exec_start' => $execStart,
                'exec_end'   => $execEnd,
                'duration'   => ($execEnd - $execStart),
            ]);
            exit(0);
        }
    } catch (\DomainException $e) {
        $execEnd = microtime(true);
        echo json_encode([
            'worker'     => $workerId,
            'status'     => 'rejected',
            'action'     => $actionType,
            'target'     => $targetId,
            'reason'     => $e->getMessage(),
            'exec_start' => $execStart,
            'exec_end'   => $execEnd,
            'duration'   => ($execEnd - $execStart),
        ]);
        exit(0);
    } catch (\Throwable $e) {
        echo json_encode([
            'worker'  => $workerId,
            'status'  => 'error',
            'target'  => $targetId,
            'message' => $e->getMessage(),
        ]);
        exit(1);
    }
}

// ==============================================================================
// COORDINATOR PROCESS
// ==============================================================================
echo "==============================================================================\n";
echo "   SHOPWISE — GENUINE SYNCHRONIZED STAFFSERVICE CONCURRENCY VERIFICATION\n";
echo "==============================================================================\n";

function runSynchronizedConcurrencyRound(PDO $pdo, string $actionType): bool
{
    $methodName = ($actionType === 'update') ? 'StaffService::update()' : 'StaffService::toggleStatus()';
    echo "\n--- Synchronized Concurrency Round: {$methodName} ---\n";

    $barrierDir = sys_get_temp_dir() . '/sw_barrier_' . bin2hex(random_bytes(6));
    if (!mkdir($barrierDir, 0700, true)) {
        fwrite(STDERR, "FATAL: Could not create barrier directory '{$barrierDir}'\n");
        return false;
    }

    $sa1 = null;
    $sa2 = null;
    $stashedAdmins = [];
    $p1 = null;
    $p2 = null;

    try {
        // Step 1: Stash any existing active super_admins in shopwise_test
        $stashedAdmins = $pdo->query("
            SELECT id FROM users
            WHERE status = 'active' AND (role IN ('super_admin', 'admin') OR id IN (SELECT user_id FROM user_roles WHERE role IN ('super_admin', 'admin')))
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($stashedAdmins)) {
            $ids = implode(',', array_map('intval', $stashedAdmins));
            $pdo->exec("UPDATE users SET status = 'blocked' WHERE id IN ($ids)");
        }

        // Step 2: Provision exactly TWO active Super Administrators
        $hash = password_hash('TestPass123!', PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password_hash, role, status, created_at)
            VALUES ('SyncA', 'Admin', 'sync_sa_1@test.local', :h, 'super_admin', 'active', NOW())
        ")->execute([':h' => $hash]);
        $sa1 = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password_hash, role, status, created_at)
            VALUES ('SyncB', 'Admin', 'sync_sa_2@test.local', :h, 'super_admin', 'active', NOW())
        ")->execute([':h' => $hash]);
        $sa2 = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (:id, 'super_admin')")->execute([':id' => $sa1]);
        $pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (:id, 'super_admin')")->execute([':id' => $sa2]);

        $initActive = (int)$pdo->query("
            SELECT COUNT(*) FROM users
            WHERE status = 'active' AND (role IN ('super_admin', 'admin') OR id IN (SELECT user_id FROM user_roles WHERE role IN ('super_admin', 'admin')))
        ")->fetchColumn();

        echo "  [1/6] Provisioned 2 Super Admins (ID #{$sa1}, ID #{$sa2}). Active count: {$initActive}\n";
        if ($initActive !== 2) {
            echo "  [FAIL] Expected baseline active count of 2, got {$initActive}\n";
            return false;
        }

        // Step 3: Spawn both worker processes with barrier directory
        $scriptPath = __FILE__;
        $cmd1 = "SHOPWISE_CONFIG=testing php " . escapeshellarg($scriptPath) . " worker 1 $sa1 $sa2 $actionType " . escapeshellarg($barrierDir);
        $cmd2 = "SHOPWISE_CONFIG=testing php " . escapeshellarg($scriptPath) . " worker 2 $sa2 $sa1 $actionType " . escapeshellarg($barrierDir);

        echo "  [2/6] Launching 2 independent worker processes...\n";
        $p1 = popen($cmd1, 'r');
        $p2 = popen($cmd2, 'r');

        // Step 4: Wait for both workers to signal readiness at the barrier
        echo "  [3/6] Waiting for both workers to reach the synchronization barrier...\n";
        $barrierWaitStart = microtime(true);
        $w1Ready = false;
        $w2Ready = false;

        while ((microtime(true) - $barrierWaitStart) < 5.0) {
            if (!$w1Ready && file_exists($barrierDir . "/worker_1.ready")) {
                $w1Ready = true;
            }
            if (!$w2Ready && file_exists($barrierDir . "/worker_2.ready")) {
                $w2Ready = true;
            }
            if ($w1Ready && $w2Ready) {
                break;
            }
            usleep(1000); // 1ms
        }

        if (!$w1Ready || !$w2Ready) {
            echo "  [FAIL] Synchronization barrier failure: Worker 1 ready=" . ($w1Ready ? 'true' : 'false') . ", Worker 2 ready=" . ($w2Ready ? 'true' : 'false') . "\n";
            return false;
        }

        $w1Meta = json_decode(file_get_contents($barrierDir . "/worker_1.ready"), true);
        $w2Meta = json_decode(file_get_contents($barrierDir . "/worker_2.ready"), true);
        echo "  [4/6] Both workers arrived at barrier (W1 ready at {$w1Meta['ready_at']}, W2 ready at {$w2Meta['ready_at']}).\n";

        // Step 5: Release barrier simultaneously
        $releaseTime = microtime(true);
        file_put_contents($barrierDir . "/release.go", json_encode(['released_at' => $releaseTime]));
        echo "  [5/6] Barrier released at {$releaseTime}. Both workers executing {$methodName} simultaneously.\n";

        // Step 6: Collect outputs and exit codes
        $out1 = stream_get_contents($p1);
        $exit1 = pclose($p1);
        $p1 = null;

        $out2 = stream_get_contents($p2);
        $exit2 = pclose($p2);
        $p2 = null;

        echo "  [6/6] Worker 1 (Exit {$exit1}): {$out1}\n";
        echo "        Worker 2 (Exit {$exit2}): {$out2}\n";

        if ($exit1 !== 0 || $exit2 !== 0) {
            echo "  [FAIL] Non-zero exit code detected (W1: {$exit1}, W2: {$exit2})\n";
            return false;
        }

        $res1 = json_decode($out1, true);
        $res2 = json_decode($out2, true);

        if (!$res1 || !$res2) {
            echo "  [FAIL] Failed to parse JSON response from workers\n";
            return false;
        }

        $successCount  = ($res1['status'] === 'success' ? 1 : 0) + ($res2['status'] === 'success' ? 1 : 0);
        $rejectedCount = ($res1['status'] === 'rejected' ? 1 : 0) + ($res2['status'] === 'rejected' ? 1 : 0);

        // Verify rejected reason contains the expected DomainException message
        $expectedReason = ($actionType === 'update')
            ? "Cannot demote the last active Super Administrator."
            : "Cannot deactivate the last active Super Administrator.";

        $rejectionReasonVerified = false;
        if ($res1['status'] === 'rejected' && $res1['reason'] === $expectedReason) {
            $rejectionReasonVerified = true;
        } elseif ($res2['status'] === 'rejected' && $res2['reason'] === $expectedReason) {
            $rejectionReasonVerified = true;
        }

        // Verify post-execution database state under isolation
        $finalActive = (int)$pdo->query("
            SELECT COUNT(*) FROM users
            WHERE status = 'active' AND (role IN ('super_admin', 'admin') OR id IN (SELECT user_id FROM user_roles WHERE role IN ('super_admin', 'admin')))
        ")->fetchColumn();

        echo "  Results: Final Active Super Admins={$finalActive}, Successes={$successCount}, Rejections={$rejectedCount}\n";

        $passed = ($finalActive === 1)
               && ($successCount === 1)
               && ($rejectedCount === 1)
               && $rejectionReasonVerified;

        if ($passed) {
            echo "  [PASS] {$methodName} concurrency invariant verified: exactly 1 succeeded, 1 rejected with DomainException, 1 Super Admin preserved.\n";
            return true;
        } else {
            echo "  [FAIL] Invariant check failed: finalActive={$finalActive}, successCount={$successCount}, rejectedCount={$rejectedCount}, reasonOk=" . ($rejectionReasonVerified ? 'true' : 'false') . "\n";
            return false;
        }
    } finally {
        // Guaranteed cleanup in try/finally
        if ($p1 !== null) {
            @pclose($p1);
        }
        if ($p2 !== null) {
            @pclose($p2);
        }

        // A. Remove test Super Admins
        if ($sa1 !== null || $sa2 !== null) {
            $delIds = array_filter([$sa1, $sa2]);
            if (!empty($delIds)) {
                $idList = implode(',', $delIds);
                $pdo->exec("DELETE FROM users WHERE id IN ($idList)");
            }
        }

        // B. Restore stashed admins
        if (!empty($stashedAdmins)) {
            $restoreIds = implode(',', array_map('intval', $stashedAdmins));
            $pdo->exec("UPDATE users SET status = 'active' WHERE id IN ($restoreIds)");
        }

        // C. Clean up barrier directory
        if (is_dir($barrierDir)) {
            $files = glob($barrierDir . '/*');
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($barrierDir);
        }
    }
}

// Run synchronized concurrency tests for both update and toggleStatus
$okUpdate = runSynchronizedConcurrencyRound($pdo, 'update');
$okToggle = runSynchronizedConcurrencyRound($pdo, 'toggle');

// Post-cleanup hygiene verification
$leftoverTestUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'sync_sa_%@test.local'")->fetchColumn();
if ($leftoverTestUsers > 0) {
    fwrite(STDERR, "CRITICAL: Cleanup failure! {$leftoverTestUsers} test user(s) left in shopwise_test.\n");
    exit(1);
}

if ($okUpdate && $okToggle) {
    echo "\n==============================================================================\n";
    echo "  ALL SYNCHRONIZED STAFFSERVICE CONCURRENCY TESTS PASSED (ZERO DRIFT)\n";
    echo "==============================================================================\n";
    exit(0);
} else {
    echo "\n==============================================================================\n";
    echo "  SYNCHRONIZED STAFFSERVICE CONCURRENCY TESTS FAILED\n";
    echo "==============================================================================\n";
    exit(1);
}
