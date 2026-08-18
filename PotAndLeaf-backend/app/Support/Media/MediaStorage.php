<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;

/** Deletes a previously uploaded public-disk file when its URL/path is replaced. */
class MediaStorage
{
    public static function deleteIfOwned(?string $urlOrPath): void
    {
        if (blank($urlOrPath)) {
            return;
        }

        $path = $urlOrPath;
        if (str_contains($urlOrPath, '/storage/')) {
            $path = ltrim(parse_url($urlOrPath, PHP_URL_PATH) ?? '', '/');
            $path = preg_replace('#^storage/#', '', $path) ?? $path;
        }

        if (! str_starts_with($path, 'uploads/')) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public static function replace(?string $old, ?string $new): ?string
    {
        if ($old && $old !== $new) {
            self::deleteIfOwned($old);
        }

        return $new;
    }
}
