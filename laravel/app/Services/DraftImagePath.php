<?php

namespace App\Services;

class DraftImagePath
{
    /** @param array<int, array<string, mixed>> $images */
    public static function resolve(string $path, array $images): ?string
    {
        $root = realpath(dirname(__DIR__, 2).'/storage/app/private/teaching-drafts');
        $candidate = realpath($path);
        $isMember = array_filter($images, static fn ($image): bool => is_array($image) && ($image['file_path'] ?? null) === $path);
        if (! $root || ! $candidate || ! $isMember || ! str_starts_with($candidate, $root.DIRECTORY_SEPARATOR) || ! is_file($candidate)) {
            return null;
        }

        return str_replace('\\', '/', $candidate);
    }
}
