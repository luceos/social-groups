<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates a post inside a hidden per-group gallery discussion.
 * The gallery discussion is created automatically on first upload.
 * It is hidden from the discussion list (is_gallery = true).
 * ListGroupMediaController scans SocialGroupPost records for image URLs,
 * so these posts surface in the media gallery without polluting the feed.
 */
class StoreGroupMediaPostController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(private LoggerInterface $log, private ConnectionInterface $db) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $groupId = (int) ($this->routeParam($request, 'groupId', '/sg-media-post/{groupId}') ?? 0);

            $body    = (array) ($request->getParsedBody() ?? []);
            $content = trim((string) ($body['content'] ?? ''));

            if (! $groupId || ! $content) {
                return new JsonResponse(['error' => 'groupId and content are required.'], 422);
            }

            $group = SocialGroup::findOrFail($groupId);

            $isMember = $group->activeMembership($actor->id)->exists();
            if (! $isMember && ! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'You must be a member to upload media.'], 403);
            }

            $post = $this->db->transaction(function () use ($groupId, $actor, $content) {
                // Lock the group row so two concurrent uploads can't both observe
                // a missing gallery and each INSERT one. There is no unique
                // constraint on (group_id, is_gallery) — MySQL can't express the
                // needed partial uniqueness — so the row lock is what serializes
                // the find-or-create of the hidden gallery archive discussion.
                SocialGroup::query()->whereKey($groupId)->lockForUpdate()->first();

                $discussion = SocialGroupDiscussion::firstOrCreate(
                    ['group_id' => $groupId, 'is_gallery' => true],
                    [
                        'user_id'        => $actor->id,
                        'title'          => '__gallery__',
                        'is_locked'      => false,
                        'comment_count'  => 0,
                        'last_posted_at' => \Carbon\Carbon::now(),
                    ]
                );

                $post = SocialGroupPost::create([
                    'discussion_id' => $discussion->id,
                    'group_id'      => $groupId,
                    'user_id'       => $actor->id,
                    'content'       => $content,
                ]);

                $discussion->increment('comment_count');
                $discussion->last_posted_at      = \Carbon\Carbon::now();
                $discussion->last_posted_user_id = $actor->id;
                $discussion->save();

                return $post;
            });

            return new JsonResponse(['success' => true, 'postId' => $post->id], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] StoreGroupMediaPostController: ' . $e->getMessage());
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
