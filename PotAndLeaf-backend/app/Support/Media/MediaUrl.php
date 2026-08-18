<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;

/** Resolve stored media paths/URLs to browser-loadable absolute URLs. */
class MediaUrl
{
    public static function resolve(?string $pathOrUrl): ?string
    {
        if (blank($pathOrUrl)) {
            return null;
        }

        $pathOrUrl = trim($pathOrUrl);

        if (str_starts_with($pathOrUrl, 'data:') || str_starts_with($pathOrUrl, 'blob:')) {
            return $pathOrUrl;
        }

        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return self::normalizeAbsoluteUrl($pathOrUrl);
        }

        if (str_starts_with($pathOrUrl, '/storage/')) {
            return url($pathOrUrl);
        }

        if (str_starts_with($pathOrUrl, 'storage/')) {
            return url('/'.$pathOrUrl);
        }

        if (str_starts_with($pathOrUrl, 'uploads/')) {
            return url(Storage::disk('public')->url($pathOrUrl));
        }

        if (str_starts_with($pathOrUrl, '/')) {
            return url($pathOrUrl);
        }

        return url(Storage::disk('public')->url('uploads/'.ltrim($pathOrUrl, '/')));
    }

    /** @param  array<int, string|null>|null  $paths */
    public static function resolveMany(?array $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($path) => self::resolve(is_string($path) ? $path : null),
            $paths,
        )));
    }

    private static function normalizeAbsoluteUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if ($path && str_contains($path, '/storage/')) {
            return url($path.(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : ''));
        }

        return $url;
    }
}
