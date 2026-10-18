<?php

namespace App\Services;

use RuntimeException;

/**
 * Secure Product Image Upload & Processing Service
 */
class ImageUploadService
{
    private string $uploadDir;
    private int $maxBytes;
    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    private array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(?string $uploadDir = null, int $maxBytes = 5242880) // 5MB
    {
        $this->uploadDir = $uploadDir ?? dirname(__DIR__, 2) . '/public/uploads/products';
        $this->maxBytes = $maxBytes;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Process an uploaded file and save optimized WebP image and thumbnail
     *
     * @param array $file $_FILES['image'] array
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
                throw new RuntimeException("Unknown error occurred during file upload.");
        }

        if ($file['size'] > $this->maxBytes) {
            throw new RuntimeException("File exceeds maximum allowed size of " . ($this->maxBytes / 1048576) . "MB.");
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions, true)) {
            throw new RuntimeException("Invalid file extension. Only JPG, PNG, and WebP images are permitted.");
        }

        // Validate MIME type with finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $this->allowedMimes, true)) {
            throw new RuntimeException("Invalid file type detected ({$mime}). Only genuine image files are allowed.");
        }

        // Verify image dimensions with getimagesize
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new RuntimeException("The uploaded file is not a valid readable image.");
        }

        [$origWidth, $origHeight, $imageType] = $imageInfo;

        // Load source image into GD
        $sourceImage = match ($imageType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
            IMAGETYPE_PNG  => imagecreatefrompng($file['tmp_name']),
            IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
            default        => false,
        };

        if (!$sourceImage) {
            throw new RuntimeException("Failed to process image with GD library.");
        }

        // Generate unique unguessable filename
        $uniqueId = bin2hex(random_bytes(16));
        $mainFilename = "prod_{$uniqueId}.webp";
        $thumbFilename = "prod_{$uniqueId}_thumb.webp";

        $mainDest = rtrim($this->uploadDir, '/') . '/' . $mainFilename;
        $thumbDest = rtrim($this->uploadDir, '/') . '/' . $thumbFilename;

        // Resize and save main WebP image
        $this->resizeAndSaveWebp($sourceImage, $origWidth, $origHeight, $maxWidth, $mainDest, 85);

        // Resize and save thumbnail WebP
        $this->resizeAndSaveWebp($sourceImage, $origWidth, $origHeight, $thumbWidth, $thumbDest, 80);

        imagedestroy($sourceImage);

        return [
            'filename'   => $mainFilename,
            'path'       => 'uploads/products/' . $mainFilename,
            'thumb_path' => 'uploads/products/' . $thumbFilename,
        ];
    }

    /**
     * Resize and save image as WebP
     */
    private function resizeAndSaveWebp($source, int $origWidth, int $origHeight, int $targetWidth, string $destPath, int $quality): void
    {
        if ($origWidth > $targetWidth) {
            $targetHeight = (int)round(($origHeight / $origWidth) * $targetWidth);
        } else {
            $targetWidth = $origWidth;
            $targetHeight = $origHeight;
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // Retain alpha transparency for PNG/WebP
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);

        imagewebp($canvas, $destPath, $quality);
        imagedestroy($canvas);
    }
}
