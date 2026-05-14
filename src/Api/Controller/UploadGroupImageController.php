<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UploadGroupImageController implements RequestHandlerInterface
{
    public function __construct(
        protected FilesystemFactory $filesystem
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $queryParams = $request->getQueryParams();
        $id = $queryParams['id'] ?? null;
        if (! $id) {
            preg_match('#/social-groups/(\d+)#', $request->getUri()->getPath(), $m);
            $id = $m[1] ?? null;
        }

        // Determine upload type from URL path (route params are in query params in Flarum 2)
        // or from an explicit 'type' query param override
        $type = str_ends_with($request->getUri()->getPath(), '/banner') ? 'banner' : 'image';
        if (isset($queryParams['type']) && in_array($queryParams['type'], ['image', 'banner'])) {
            $type = $queryParams['type'];
        }

        $group = SocialGroup::findOrFail($id);

        if ($actor->id !== $group->user_id && ! $actor->isAdmin()) {
            throw new PermissionDeniedException();
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (! $file || $file->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['error' => 'No valid file uploaded'], 422);
        }

        $ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return new JsonResponse(['error' => 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp'], 422);
        }

        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return new JsonResponse(['error' => 'File too large. Maximum size is 5MB'], 422);
        }

        // Read the stream once so we can both sniff and upload without rewinding
        $streamContents = $file->getStream()->getContents();

        // Sniff actual MIME type — the client-supplied extension is untrusted
        $tmpPath = tempnam(sys_get_temp_dir(), 'sg_upload_');
        try {
            file_put_contents($tmpPath, $streamContents);
            $finfo    = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpPath);
        } finally {
            @unlink($tmpPath);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (! in_array($mimeType, $allowedMimes, true)) {
            return new JsonResponse(['error' => 'Invalid file content. Only image files are allowed.'], 422);
        }

        $disk = $this->filesystem->disk('flarum-assets');

        // Delete old file if exists
        if ($type === 'banner' && $group->banner_url) {
            $this->deleteOldFile($disk, $group->banner_url);
        } elseif ($type === 'image' && $group->image_url) {
            $this->deleteOldFile($disk, $group->image_url);
        }

        $filename = 'social-groups/' . $group->id . '-' . $type . '-' . time() . '.' . $ext;

        $disk->put($filename, $streamContents, 'public');
        $url = $disk->url($filename);

        if ($type === 'banner') {
            $group->banner_url = $url;
        } else {
            $group->image_url = $url;
        }
        $group->save();

        return new JsonResponse(['url' => $url]);
    }

    protected function deleteOldFile($disk, string $url): void
    {
        try {
            // Extract path from URL
            $parsed = parse_url($url);
            if ($parsed && isset($parsed['path'])) {
                $path = ltrim($parsed['path'], '/');
                // Remove any base path prefix like 'assets/'
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        } catch (\Exception $e) {
            // Silently fail — old file deletion is best-effort
        }
    }
}
