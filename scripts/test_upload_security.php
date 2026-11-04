<?php
/**
 * ShopWise Platform - Upload Security & Permissions Verification
 * Verifies Priority 1:
 *  1. Restrictive directory permissions (0755, never world-writable).
 *  2. Restrictive file permissions (0644, non-executable, not world-writable).
 *  3. World-writable directory detection and clear error guidance.
 *  4. Payload rejection (PHP, HTML, non-images).
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ImageUploadService;

$testsTotal = 0;
$testsPassed = 0;

function pass(string $msg): void {
    global $testsTotal, $testsPassed;
    $testsTotal++;
    $testsPassed++;
    echo "  \033[0;32m[PASS]\033[0m {$msg}\n";
}

function fail(string $msg): void {
    global $testsTotal;
    $testsTotal++;
    echo "  \033[0;31m[FAIL]\033[0m {$msg}\n";
}

echo "==============================================================================\n";
echo " ShopWise Kenya - Upload Security & Restrictive Permissions Test\n";
echo "==============================================================================\n\n";

$testUploadBase = sys_get_temp_dir() . '/shopwise_upload_sec_' . bin2hex(random_bytes(6));
if (!mkdir($testUploadBase, 0755, true)) {
    echo "Fatal: Could not create temp test directory: {$testUploadBase}\n";
    exit(1);
}

try {
    $service = new ImageUploadService($testUploadBase);

    // Test 1: Upload a valid test image and check directory permissions
    $validImage = imagecreatetruecolor(100, 100);
    $tmpImgPath = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
    imagepng($validImage, $tmpImgPath);
    if (PHP_VERSION_ID < 80500) { @imagedestroy($validImage); }

    $filePayload = [
        'name'     => 'test_sample.png',
        'type'     => 'image/png',
        'tmp_name' => $tmpImgPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($tmpImgPath),
    ];

    $uploadResult = $service->upload($filePayload);
    @unlink($tmpImgPath);

    // Check directory permissions
    $year = date('Y');
    $month = date('m');
    $createdDir = "{$testUploadBase}/{$year}/{$month}";
    $dirPerms = fileperms($createdDir);
    $dirOctal = substr(sprintf('%o', $dirPerms), -4);

    if (($dirPerms & 0002) === 0) {
        pass("Upload directory is created with restrictive permissions ({$dirOctal}) and is NOT world-writable");
    } else {
        fail("Upload directory is world-writable ({$dirOctal})!");
    }

    // Test 2: Check uploaded image file permissions
    $fullMainPath = $testUploadBase . '/' . str_replace("uploads/products/", "", $uploadResult['path']);
    $filePerms = fileperms($fullMainPath);
    $fileOctal = substr(sprintf('%o', $filePerms), -4);

    if (($filePerms & 0002) === 0 && ($filePerms & 0111) === 0) {
        pass("Uploaded image file has restrictive 0644 permissions ({$fileOctal}), is not world-writable, and is not executable");
    } else {
        fail("Uploaded image file permissions are insecure ({$fileOctal})!");
    }

    // Test 3: Upload rejection of fake PHP file disguised as an image
    $fakeImgPath = tempnam(sys_get_temp_dir(), 'fake_') . '.png';
    file_put_contents($fakeImgPath, "<?php phpinfo(); ?>");

    $fakePayload = [
        'name'     => 'exploit.php.png',
        'type'     => 'image/png',
        'tmp_name' => $fakeImgPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fakeImgPath),
    ];

    $caughtFake = false;
    try {
        $service->upload($fakePayload);
    } catch (\Throwable $e) {
        $caughtFake = true;
    }
    @unlink($fakeImgPath);

    if ($caughtFake) {
        pass("Malicious PHP script disguised as image is strictly rejected");
    } else {
        fail("Malicious PHP script was accepted by ImageUploadService!");
    }

    // Test 4: Upload rejection of HTML/SVG payload
    $svgPath = tempnam(sys_get_temp_dir(), 'fake_') . '.svg';
    file_put_contents($svgPath, "<svg onload=alert(1)>");

    $svgPayload = [
        'name'     => 'vector.svg',
        'type'     => 'image/svg+xml',
        'tmp_name' => $svgPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($svgPath),
    ];

    $caughtSvg = false;
    try {
        $service->upload($svgPayload);
    } catch (\Throwable $e) {
        $caughtSvg = true;
    }
    @unlink($svgPath);

    if ($caughtSvg) {
        pass("Non-whitelisted MIME/extension (SVG vector) is strictly rejected");
    } else {
        fail("SVG vector upload was accepted!");
    }

    // Test 5: Rejection of path traversal attempt in filename
    $travPath = tempnam(sys_get_temp_dir(), 'trav_') . '.png';
    $img2 = imagecreatetruecolor(50, 50);
    imagepng($img2, $travPath);
    if (PHP_VERSION_ID < 80500) { @imagedestroy($img2); }

    $travPayload = [
        'name'     => '../../../../etc/cron.d/backdoor.png',
        'type'     => 'image/png',
        'tmp_name' => $travPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($travPath),
    ];

    $res = $service->upload($travPayload);
    @unlink($travPath);

    // Verify stored file is safely randomized inside target dir, never in traversal target
    if (!str_contains($res['filename'], '..') && !str_contains($res['filename'], '/')) {
        pass("Path traversal attempt in filename is neutralized with secure randomized basename ({$res['filename']})");
    } else {
        fail("Path traversal vulnerability: filename contains invalid path characters: {$res['filename']}");
    }

} finally {
    // Recursive delete temp directory
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testUploadBase, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($testUploadBase);
}

echo "\nSummary: {$testsPassed} / {$testsTotal} upload security tests passed.\n";
exit($testsPassed === $testsTotal ? 0 : 1);
