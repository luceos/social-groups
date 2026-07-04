<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class UploadGroupImageController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    protected const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        protected FilesystemFactory $filesystem,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $queryParams = $request->getQueryParams();
        $id = $this->routeParam($request, 'id', '/social-groups/{id}');

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
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.no_file')], 422);
        }

        $ext = strtolower(pathinfo($file->getClientFilename(), PATHINFO_EXTENSION));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.invalid_file_type')], 422);
        }

        $maxSize = (int) $this->settings->get('ernestdefoe-social-groups.max_image_bytes', self::DEFAULT_MAX_BYTES);
        if ($maxSize <= 0) {
            $maxSize = self::DEFAULT_MAX_BYTES;
        }
        $size = $file->getSize();
        if ($size === null || $size <= 0 || $size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024), 1);
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.file_too_large', ['{max}' => $maxMb])], 422);
        }

        // Read the stream once so we can both sniff and upload without rewinding
        $streamContents = $file->getStream()->getContents();

        // Sniff actual MIME type in-memory — the client-supplied extension is
        // untrusted. Using `finfo::buffer()` avoids a temp-file dance and the
        // tiny TOCTOU window between write and unlink that the previous
        // tempnam() path opened in shared `/tmp`.
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($streamContents);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (! in_array($mimeType, $allowedMimes, true)) {
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.invalid_file_content')], 422);
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

        // Persist the relative disk key, not the full URL. The public URL is
        // rebuilt on serialization (SocialGroupResource) via $disk->url(), so a
        // CDN / base-URL change never strands old rows, and deletion becomes a
        // direct $disk->delete($key) instead of fragile URL-to-path parsing.
        if ($type === 'banner') {
            $group->banner_url = $filename;
        } else {
            $group->image_url = $filename;
        }
        $group->save();

        return new JsonResponse(['url' => $disk->url($filename)]);
    }

    protected function deleteOldFile($disk, string $stored): void
    {
        try {
            $key = $this->resolveDiskKey($disk, $stored);
            if ($key !== '' && $disk->exists($key)) {
                $disk->delete($key);
            }
        } catch (\Throwable $e) {
            // Old-file deletion is best-effort — never block a re-upload on it.
        }
    }

    /**
     * Resolve a stored reference to the disk key it was written under. New rows
     * store the relative key directly; legacy rows stored the full URL, whose
     * key is recovered by stripping the disk's own base URL — not parse_url(),
     * whose path can carry an 'assets/' prefix that never matches the key and
     * silently orphaned the old file on every re-upload.
     */
    protected function resolveDiskKey($disk, string $stored): string
    {
        if (! preg_match('#^https?://#i', $stored)) {
            return ltrim($stored, '/');
        }

        $probe  = $disk->url('__sgkey__');
        $marker = strrpos($probe, '__sgkey__');
        if ($marker !== false) {
            $base = substr($probe, 0, $marker);
            if (str_starts_with($stored, $base)) {
                return ltrim(substr($stored, strlen($base)), '/');
            }
        }

        $path = parse_url($stored, PHP_URL_PATH);
        return is_string($path) ? ltrim($path, '/') : '';
    }
}
