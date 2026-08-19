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

        $pathOrUrl = self::repairCorrupted(trim($pathOrUrl));

        if (str_starts_with($pathOrUrl, 'data:') || str_starts_with($pathOrUrl, 'blob:')) {
            return $pathOrUrl;
        }

        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return self::normalizeAbsoluteUrl($pathOrUrl);
        }

        if (str_starts_with($pathOrUrl, '/storage/')) {
            return self::ensureAbsolute($pathOrUrl);
        }

        if (str_starts_with($pathOrUrl, 'storage/')) {
            return self::ensureAbsolute('/'.$pathOrUrl);
        }

        if (str_starts_with($pathOrUrl, 'uploads/')) {
            return self::ensureAbsolute(Storage::disk('public')->url($pathOrUrl));
        }

        if (str_starts_with($pathOrUrl, '/')) {
            return self::ensureAbsolute($pathOrUrl);
        }

        return self::ensureAbsolute(Storage::disk('public')->url('uploads/'.ltrim($pathOrUrl, '/')));
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

    /** Repair legacy double-wrapped URLs (e.g. http:https://host/storage/...). */
    private static function repairCorrupted(string $value): string
    {
        if (preg_match('#(https?://[^\s]+)#i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    /** Public disk URL is already absolute when filesystems.public.url includes APP_URL. */
    private static function ensureAbsolute(string $pathOrUrl): string
    {
        $pathOrUrl = self::repairCorrupted(trim($pathOrUrl));

        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return self::normalizeAbsoluteUrl($pathOrUrl);
        }

        return url($pathOrUrl);
    }

    private static function normalizeAbsoluteUrl(string $url): string
    {
        $url = self::repairCorrupted($url);
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if ($path && str_contains($path, '/storage/')) {
            $query = parse_url($url, PHP_URL_QUERY);

            return self::ensureAbsolute($path.($query ? '?'.$query : ''));
        }

        return $url;
    }
}
