<?php

namespace App\Services;

use RuntimeException;

/**
 * Secure Product Image Upload & Processing Service
 */
class ImageUploadService
{
    private string $baseUploadDir;
    private int $maxBytes;
    private int $maxDimension;
    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    private array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(?string $baseUploadDir = null, int $maxBytes = 5242880, int $maxDimension = 6000) // 5MB, 6000px limit
    {
        $this->baseUploadDir = $baseUploadDir ?? dirname(__DIR__, 3) . '/public/uploads/products';
        $this->maxBytes = $maxBytes;
        $this->maxDimension = $maxDimension;
    }

    /**
     * Get or create directory for current year and month: public/uploads/products/<yyyy>/<mm>/
     */
    private function getTargetDirectory(): array
    {
        $year = date('Y');
        $month = date('m');
        $relativeDir = "uploads/products/{$year}/{$month}";
        $absoluteDir = rtrim($this->baseUploadDir, '/') . "/{$year}/{$month}";

        if (!is_dir($absoluteDir)) {
            if (!@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
                throw new RuntimeException("Upload directory '{$absoluteDir}' could not be created. Please verify server directory permissions (0755).");
            }
            @chmod($absoluteDir, 0755);
        }

        if (!is_writable($absoluteDir)) {
            throw new RuntimeException("Upload directory '{$absoluteDir}' is not writable by the web server. Please ensure directory permissions are set to 0755 and owned by the web server user.");
        }

        return [$relativeDir, $absoluteDir];
    }

    /**
     * Process multiple uploaded files (from <input type="file" name="images[]" multiple>)
     * Rejects invalid files individually without failing the whole batch.
     *
     * @param array $files Normalized array of file arrays, or $_FILES['images'] structure
     * @return array [ 'success' => array, 'errors' => array ]
     */
    public function uploadMultiple(array $files, int $maxWidth = 1200, int $thumbWidth = 400): array
    {
        $normalized = $this->normalizeFilesArray($files);
        $results = [
            'success' => [],
            'errors'  => [],
        ];

        foreach ($normalized as $index => $file) {
            $origName = $file['name'] ?? "File #{$index}";
            try {
                $uploaded = $this->upload($file, $maxWidth, $thumbWidth);
                $uploaded['original_name'] = $origName;
                $results['success'][] = $uploaded;
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'file'    => $origName,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Convert PHP's $_FILES multi-file array into clean array of single file arrays
     */
    public function normalizeFilesArray(array $files): array
    {
        $normalized = [];
        if (!isset($files['name'])) {
            return $files;
        }

        if (is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $normalized[] = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
            }
        } else {
            if ($files['error'] !== UPLOAD_ERR_NO_FILE) {
                $normalized[] = $files;
            }
        }

        return $normalized;
    }

    /**
     * Process an uploaded file and save optimized WebP image and thumbnail
     *
     * @param array $file Single $_FILES array entry
     * @param int $maxWidth Max width for standard display (default 1200)
     * @param int $thumbWidth Width for thumbnail (default 400)
     * @return array [ 'filename' => string, 'path' => string, 'thumb_path' => string ]
     */
    public function upload(array $file, int $maxWidth = 1200, int $thumbWidth = 400): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException("Invalid file upload parameters.");
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException("No file was uploaded.");
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException("Uploaded file exceeds allowable size limit.");
            default:
                throw new RuntimeException("An error occurred during file upload (code {$file['error']}).");
        }

        if ($file['size'] > $this->maxBytes) {
            $limitMb = round($this->maxBytes / 1048576, 1);
            throw new RuntimeException("File exceeds maximum allowed size of {$limitMb}MB.");
        }

        // Validate extension against strict allowlist
        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions, true)) {
            throw new RuntimeException("Invalid file extension (.{$ext}). Only JPG, PNG, and WebP images are permitted.");
        }

        if (empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            throw new RuntimeException("Uploaded temporary file is not accessible.");
        }

        // Validate genuine MIME type via finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $this->allowedMimes, true)) {
            throw new RuntimeException("Invalid file type detected ({$mime}). Only genuine JPG, PNG, or WebP images are permitted.");
        }

        // Verify image dimensions with getimagesize
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new RuntimeException("The uploaded file is not a valid, decodable image.");
        }

        [$origWidth, $origHeight, $imageType] = $imageInfo;

        if ($origWidth <= 0 || $origHeight <= 0) {
            throw new RuntimeException("Invalid image dimensions ({$origWidth}x{$origHeight}).");
        }

        if ($origWidth > $this->maxDimension || $origHeight > $this->maxDimension) {
            throw new RuntimeException("Image dimensions ({$origWidth}x{$origHeight}) exceed maximum allowed limit of {$this->maxDimension}x{$this->maxDimension}px.");
        }

        // Load source image into GD
        $sourceImage = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
            default        => false,
        };

        if (!$sourceImage) {
            throw new RuntimeException("Failed to decode image data using GD library.");
        }

        [$relativeDir, $absoluteDir] = $this->getTargetDirectory();

        $canWebp = function_exists('imagewebp');
        $ext = $canWebp ? 'webp' : 'jpg';

        // Generate unique unguessable filename
        $uniqueId = bin2hex(random_bytes(16));
        $mainFilename = "prod_{$uniqueId}.{$ext}";
        $thumbFilename = "prod_{$uniqueId}_thumb.{$ext}";

        $mainDest = rtrim($absoluteDir, '/') . '/' . $mainFilename;
        $thumbDest = rtrim($absoluteDir, '/') . '/' . $thumbFilename;

        // Re-encode and save main image (strips all EXIF, comments, and embedded scripts)
        $this->resizeAndSaveImage($sourceImage, $origWidth, $origHeight, $maxWidth, $mainDest, 85, $canWebp);

        // Re-encode and save thumbnail
        $this->resizeAndSaveImage($sourceImage, $origWidth, $origHeight, $thumbWidth, $thumbDest, 80, $canWebp);

        imagedestroy($sourceImage);

        // Safety verification: confirm newly written files contain no PHP tags
        $this->assertNoExecutablePayload($mainDest);
        $this->assertNoExecutablePayload($thumbDest);

        return [
            'filename'   => $mainFilename,
            'path'       => $relativeDir . '/' . $mainFilename,
            'thumb_path' => $relativeDir . '/' . $thumbFilename,
        ];
    }

    /**
     * Resize and save image as WebP or JPEG
     */
    private function resizeAndSaveImage($source, int $origWidth, int $origHeight, int $targetWidth, string $destPath, int $quality, bool $canWebp): void
    {
        if ($origWidth > $targetWidth) {
            $targetHeight = (int)round(($origHeight / $origWidth) * $targetWidth);
        } else {
            $targetWidth = $origWidth;
            $targetHeight = $origHeight;
        }

        if ($targetWidth <= 0) $targetWidth = 1;
        if ($targetHeight <= 0) $targetHeight = 1;

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canWebp) {
            // Retain alpha transparency for WebP
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);
            imagewebp($canvas, $destPath, $quality);
        } else {
            // Fill background with white for JPEG
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);
            imagejpeg($canvas, $destPath, $quality);
        }

        imagedestroy($canvas);

        if (!file_exists($destPath) || filesize($destPath) === 0) {
            throw new RuntimeException("Failed to write image file to disk: {$destPath}");
        }
        @chmod($destPath, 0644);
    }

    /**
     * Ensure the saved file contains absolutely no PHP script tags
     */
    private function assertNoExecutablePayload(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Generated image file was not found on disk.");
        }

        $content = file_get_contents($filePath);
        if (stripos($content, '<?php') !== false || stripos($content, '<?=') !== false || stripos($content, '<script') !== false) {
            @unlink($filePath);
            throw new RuntimeException("Security violation: Malicious payload detected in processed image output.");
        }
    }
}
