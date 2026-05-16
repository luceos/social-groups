<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Api\Concern\SanitizesLinkPreview;
use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewPostBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewReplyBlueprint;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class CreateGroupPostController implements RequestHandlerInterface
{
    use SanitizesLinkPreview;

    public function __construct(
        private Formatter           $formatter,
        private NotificationSyncer  $notifications,
        private Dispatcher          $events,
        private LoggerInterface     $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body         = (array) ($request->getParsedBody() ?? []);
        $discussionId = (int) ($body['discussionId'] ?? 0);
        $content      = trim((string) ($body['content'] ?? ''));
        $parentPostId = isset($body['parentPostId']) ? (int) $body['parentPostId'] : null;

        if (! $discussionId || ! $content) {
            return new JsonResponse(['error' => 'discussionId and content are required.'], 422);
        }

        if (mb_strlen($content) > 20000) {
            return new JsonResponse(['error' => 'Post content may not exceed 20 000 characters.'], 422);
        }

        $discussion = SocialGroupDiscussion::with('group')->findOrFail($discussionId);

        if ($discussion->is_locked) {
            return new JsonResponse(['error' => 'This discussion is locked.'], 403);
        }

        $group    = $discussion->group;
        $isMember = $group->members()->where('user_id', $actor->id)->exists();
        if (! $isMember) {
            return new JsonResponse(['error' => 'You must be a member of this group to reply.'], 403);
        }

        // Validate and flatten parent to 1 level of nesting
        $resolvedParent = null;
        if ($parentPostId) {
            $resolvedParent = SocialGroupPost::where('id', $parentPostId)
                ->where('discussion_id', $discussion->id)
                ->first();
            if (! $resolvedParent) {
                return new JsonResponse(['error' => 'Parent post not found.'], 422);
            }
            // Flatten: if parent is itself a reply, attach to its parent instead
            $parentPostId = $resolvedParent->parent_post_id ?? $resolvedParent->id;
        }

        $linkPreview = isset($body['linkPreview']) && is_array($body['linkPreview'])
            ? $this->sanitizeLinkPreview($body['linkPreview'])
            : null;

        $contentParsed = $this->formatter->parse($content);

        $post = SocialGroupPost::create([
            'discussion_id'  => $discussion->id,
            'group_id'       => $discussion->group_id,
            'user_id'        => $actor->id,
            'content'        => $content,
            'content_parsed' => $contentParsed,
            'parent_post_id' => $parentPostId,
            'link_preview'   => $linkPreview,
        ]);

        $discussion->increment('comment_count');
        $discussion->last_posted_at      = \Carbon\Carbon::now();
        $discussion->last_posted_user_id = $actor->id;
        $discussion->save();

        // ── Realtime broadcast ───────────────────────────────────────────────
        try {
            $this->events->dispatch(new SocialGroupPostWasCreated($post, $actor, $discussion));
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Realtime event dispatch failed: ' . $e->getMessage());
        }

        // ── Notifications ────────────────────────────────────────────────────
        try {
            if (! $parentPostId) {
                // New top-level comment — notify discussion creator + prior commenters
                $recipients = $this->discussionParticipants($discussion, $actor->id);
                if ($recipients) {
                    $this->notifications->sync(
                        new SocialGroupNewPostBlueprint($post, $actor, $discussion),
                        $recipients
                    );
                }
            } else {
                // Nested reply — notify the parent post's author
                $parentPost = SocialGroupPost::find($parentPostId);
                if ($parentPost && $parentPost->user_id && $parentPost->user_id !== $actor->id) {
                    $recipient = User::find($parentPost->user_id);
                    if ($recipient) {
                        $this->notifications->sync(
                            new SocialGroupNewReplyBlueprint($post, $actor, $parentPost, $discussion),
                            [$recipient]
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Notification failed: ' . $e->getMessage(), ['exception' => $e]);
        }

        return new JsonResponse([
            'id'             => $post->id,
            'discussionId'   => $post->discussion_id,
            'content'        => $post->content,
            'contentParsed'  => $this->formatter->render($post->content_parsed),
            'createdAt'      => $post->created_at->toIso8601String(),
            'updatedAt'      => $post->updated_at->toIso8601String(),
            'reactions'      => (object) [],
            'actorReaction'  => null,
            'parentPostId'   => $post->parent_post_id,
            'linkPreview'    => $post->link_preview,
            'canEdit'        => $actor->id === $post->user_id,
            'canDelete'      => $actor->id === $post->user_id,
            'user'           => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
                'slug'        => $actor->username,
            ],
        ], 201);
    }

    private function discussionParticipants(SocialGroupDiscussion $discussion, int $actorId): array
    {
        $ids = SocialGroupPost::where('discussion_id', $discussion->id)
            ->where('user_id', '!=', $actorId)
            ->pluck('user_id')
            ->push($discussion->user_id)
            ->unique()
            ->filter(fn ($id) => $id && $id !== $actorId)
            ->values()
            ->all();

        return $ids ? User::whereIn('id', $ids)->get()->all() : [];
    }
}
