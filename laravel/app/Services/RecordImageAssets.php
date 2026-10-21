<?php

namespace App\Services;

use App\Models\RecordImage;
use Illuminate\Support\Facades\Storage;

class RecordImageAssets
{
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    private const MAX_THUMBNAIL_SIDE = 640;

    /** Keep image viewing independent of Eloquent/client primary-key casts. */
    public function assetKey(RecordImage $image): string
    {
        return hash('sha256', json_encode([
            (string) $image->record_id,
            (string) $image->getRawOriginal($image->getKeyName()),
            (string) $image->file_path,
        ], JSON_THROW_ON_ERROR));
    }

    /** Normalize shared legacy/print image paths without requiring Laravel to boot. */
    public static function normalizeStoredPath(string $storedPath): ?string
    {
        $normalized = preg_replace('/[?#].*$/', '', trim($storedPath)) ?? '';
        $normalized = str_replace('\\', '/', rawurldecode($normalized));
        if (preg_match('~(?:^|/)(uploads|storage)/(.+)$~', $normalized, $matches)) {
            $normalized = $matches[1].'/'.$matches[2];
        }
        $relative = ltrim($normalized, '/');
        if ($relative === '' || str_contains($relative, "\0") || in_array('..', explode('/', $relative), true)) {
            return null;
        }

        return $relative;
    }

    /** Resolve legacy and Laravel images locally without fetching external URLs. */
    public function resolve(string $storedPath): ?string
    {
        $relative = self::normalizeStoredPath($storedPath);
        if ($relative === null) {
            return null;
        }

        $candidates = str_starts_with($relative, 'storage/')
            ? [Storage::disk('public')->path(substr($relative, 8))]
            : [dirname(base_path()).'/'.$relative, public_path($relative)];
        $allowedRoots = array_filter(array_map('realpath', [Storage::disk('public')->path(''), dirname(base_path()), public_path()]));
        foreach ($candidates as $candidate) {
            $realPath = realpath($candidate);
            if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
                continue;
            }
            foreach ($allowedRoots as $allowedRoot) {
                if (str_starts_with($realPath, $allowedRoot.DIRECTORY_SEPARATOR)) {
                    return $realPath;
                }
            }
        }

        return null;
    }

    /** Embed authenticated record thumbnails so deployment rewrites cannot break them. */
    public function previewSrc(string $storedPath): ?string
    {
        $path = $this->resolve($storedPath);
        if ($path === null || filesize($path) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $dimensions = @getimagesize($path);
        $mime = $dimensions['mime'] ?? '';
        if (! $dimensions || ! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        if (max($dimensions[0], $dimensions[1]) > self::MAX_THUMBNAIL_SIDE
            && $dimensions[0] * $dimensions[1] <= 16_000_000
            && function_exists('imagecreatefromstring')) {
            $thumbnailBytes = $this->thumbnail($bytes, $dimensions[0], $dimensions[1]);
            if ($thumbnailBytes !== null) {
                $bytes = $thumbnailBytes;
                $mime = 'image/jpeg';
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function thumbnail(string $bytes, int $width, int $height): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        $scale = self::MAX_THUMBNAIL_SIDE / max($width, $height);
        $thumbnail = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        if ($thumbnail === false) {
            return null;
        }
        imagefill($thumbnail, 0, 0, imagecolorallocate($thumbnail, 255, 255, 255));
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, imagesx($thumbnail), imagesy($thumbnail), $width, $height);
        ob_start();
        try {
            if (! imagejpeg($thumbnail, null, 88)) {
                return null;
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            unset($thumbnail, $source);
        }
    }
}
