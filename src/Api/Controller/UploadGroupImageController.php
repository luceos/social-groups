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

        $id = $request->getAttribute('id');

        // Determine upload type from route name or query param
        // Route 'social-groups.upload-banner' sets imageType to 'banner' via middleware attribute
        // We check both route attribute and query param
        $routeName = $request->getAttribute('routeName', '');
        $type = str_contains($routeName, 'banner') ? 'banner' : 'image';

        // Also allow explicit override via query param
        $queryParams = $request->getQueryParams();
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
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return new JsonResponse(['error' => 'Invalid file type. Allowed: jpg, jpeg, png, gif, webp'], 422);
        }

        // Validate file size (max 5MB)
        $maxSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return new JsonResponse(['error' => 'File too large. Maximum size is 5MB'], 422);
        }

        $disk = $this->filesystem->disk('flarum-assets');

        // Delete old file if exists
        if ($type === 'banner' && $group->banner_url) {
            $this->deleteOldFile($disk, $group->banner_url);
        } elseif ($type === 'image' && $group->image_url) {
            $this->deleteOldFile($disk, $group->image_url);
        }

        $filename = 'social-groups/' . $group->id . '-' . $type . '-' . time() . '.' . $ext;

        $disk->put($filename, $file->getStream()->getContents(), 'public');
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
