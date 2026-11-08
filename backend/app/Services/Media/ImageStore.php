<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Resizes uploads with GD (works on shared cPanel hosting) and stores a full image
 * and a thumbnail as WebP on the public disk, under the tenant's folder.
 */
class ImageStore
{
    public const FULL_MAX = 1600;

    public const THUMB_MAX = 400;

    /** @return array{path: string, thumb_path: string} */
    public function store(UploadedFile $file, int $tenantId, string $folder): array
    {
        $manager = new ImageManager(new Driver);
        $base = sprintf('tenants/%d/%s/%s', $tenantId, trim($folder, '/'), Str::uuid());

        $full = $manager->read($file->getRealPath())->scaleDown(self::FULL_MAX, self::FULL_MAX)->toWebp(82);
        $thumb = $manager->read($file->getRealPath())->scaleDown(self::THUMB_MAX, self::THUMB_MAX)->toWebp(75);

        Storage::disk('public')->put($base.'.webp', (string) $full);
        Storage::disk('public')->put($base.'_thumb.webp', (string) $thumb);

        return ['path' => $base.'.webp', 'thumb_path' => $base.'_thumb.webp'];
    }

    public function delete(?string ...$paths): void
    {
        Storage::disk('public')->delete(array_filter($paths));
    }
}
