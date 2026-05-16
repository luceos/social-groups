<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ShareGroupDiscussionController implements RequestHandlerInterface
{
    public function __construct(
        private Formatter $formatter,
        private LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $sourceId    = $request->getAttribute('discussionId');
            if (! $sourceId) {
                preg_match('#/sg-discussions/(\d+)#', $request->getUri()->getPath(), $m);
                $sourceId = $m[1] ?? null;
            }
            $body        = (array) ($request->getParsedBody() ?? []);
            $targetGroupId = (int) ($body['targetGroupId'] ?? 0);
            $content     = trim((string) ($body['content'] ?? ''));

            if (! $targetGroupId) {
                return new JsonResponse(['error' => 'targetGroupId is required.'], 422);
            }

            $source      = SocialGroupDiscussion::with('group')->findOrFail($sourceId);
            $targetGroup = SocialGroup::findOrFail($targetGroupId);

            $isMember = $targetGroup->members()
                ->where('user_id', $actor->id)
                ->whereNull('banned_at')
                ->exists();

            if (! $isMember) {
                return new JsonResponse(['error' => 'You must be a member of the target group to share there.'], 403);
            }

            if (! $content) {
                $content = 'Shared from ' . ($source->group?->name ?? 'another group');
            }

            if (mb_strlen($content) > 20000) {
                return new JsonResponse(['error' => 'Content may not exceed 20 000 characters.'], 422);
            }

            $title         = mb_substr('Shared: ' . $source->title, 0, 255);
            $now           = Carbon::now();
            $contentParsed = $this->formatter->parse($content);

            $discussion = SocialGroupDiscussion::create([
                'group_id'                  => $targetGroup->id,
                'user_id'                   => $actor->id,
                'title'                     => $title,
                'comment_count'             => 1,
                'last_posted_at'            => $now,
                'last_posted_user_id'       => $actor->id,
                'is_locked'                 => false,
                'shared_from_discussion_id' => $source->id,
            ]);

            SocialGroupPost::create([
                'discussion_id'  => $discussion->id,
                'group_id'       => $targetGroup->id,
                'user_id'        => $actor->id,
                'content'        => $content,
                'content_parsed' => $contentParsed,
            ]);

            // Build sharedFrom payload for the response
            $sourceFirstPost = SocialGroupPost::where('discussion_id', $source->id)
                ->orderBy('created_at')
                ->with('user')
                ->first();

            $sharedFrom = [
                'discussionId' => $source->id,
                'title'        => $source->title,
                'groupId'      => $source->group_id,
                'groupName'    => $source->group?->name,
                'groupSlug'    => $source->group?->slug,
                'snippet'      => $sourceFirstPost
                    ? mb_substr(strip_tags($sourceFirstPost->content), 0, 200)
                    : '',
                'user'         => $source->user ? [
                    'displayName' => $source->user?->display_name,
                    'avatarUrl'   => $source->user?->avatar_url,
                ] : null,
            ];

            return new JsonResponse([
                'id'           => $discussion->id,
                'groupId'      => $discussion->group_id,
                'title'        => $discussion->title,
                'commentCount' => 1,
                'isLocked'     => false,
                'isPinned'     => false,
                'canPin'       => false,
                'canShare'     => true,
                'canDelete'    => true,
                'lastPostedAt' => $now->toIso8601String(),
                'createdAt'    => $now->toIso8601String(),
                'sharedFrom'   => $sharedFrom,
                'firstPost'    => [
                    'id'            => null,
                    'content'       => $content,
                    'contentParsed' => $this->formatter->render($contentParsed),
                    'reactions'     => (object) [],
                    'actorReaction' => null,
                    'linkPreview'   => null,
                    'canEdit'       => true,
                    'createdAt'     => $now->toIso8601String(),
                    'user'          => [
                        'id'          => $actor->id,
                        'displayName' => $actor->display_name,
                        'avatarUrl'   => $actor->avatar_url,
                    ],
                ],
                'user' => [
                    'id'          => $actor->id,
                    'displayName' => $actor->display_name,
                    'avatarUrl'   => $actor->avatar_url,
                ],
                'lastPostedUser' => [
                    'id'          => $actor->id,
                    'displayName' => $actor->display_name,
                    'avatarUrl'   => $actor->avatar_url,
                ],
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Discussion or group not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] ShareGroupDiscussionController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
