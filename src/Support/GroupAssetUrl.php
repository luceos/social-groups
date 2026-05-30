<?php

namespace Ernestdefoe\SocialGroups\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Resolves a stored group image/banner reference to a public URL. New rows
 * persist the relative disk key (rebuilt here via $disk->url()); pre-existing
 * rows may hold a full URL, which is returned unchanged. Shared by every
 * read path so the relative-vs-absolute handling never drifts.
 */
class GroupAssetUrl
{
    public function __construct(protected FilesystemFactory $filesystem) {}

    public function resolve(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }
        return $this->filesystem->disk('flarum-assets')->url(ltrim($stored, '/'));
    }
}
