<?php

namespace App\Http\Resources\Concerns;

use App\Support\Media\MediaUrl;

trait ResolvesMediaUrls
{
    protected function mediaUrl(?string $pathOrUrl): ?string
    {
        return MediaUrl::resolve($pathOrUrl);
    }

    /** @param  array<int, string|null>|null  $paths */
    protected function mediaUrls(?array $paths): array
    {
        return MediaUrl::resolveMany($paths);
    }
}
