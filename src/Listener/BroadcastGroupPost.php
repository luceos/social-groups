<?php

namespace Ernestdefoe\SocialGroups\Listener;

use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Flarum\Formatter\Formatter;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Broadcasts a new group post to all connected WebSocket clients
 * via flarum/realtime. If the realtime extension is not installed
 * this listener is a no-op.
 */
class BroadcastGroupPost
{
    public function __construct(
        private Formatter $formatter,
        private LoggerInterface $log,
        private Container $container,
    ) {}

    public function handle(SocialGroupPostWasCreated $event): void
    {
        // Graceful no-op when flarum/realtime is not installed.
        // Container is injected so the binding lookup is discoverable
        // and the listener stays testable — app() would hide the
        // dependency and break under tests that don't boot the facade.
        if (! $this->container->bound('flarum-realtime.pusher')) {
            return;
        }

        $post       = $event->post;
        $actor      = $event->actor;
        $discussion = $event->discussion;

        // Render the stored parsed content to HTML exactly as the
        // CreateGroupPostController does in its JSON response.
        $contentParsed = null;
        try {
            if ($post->content_parsed !== null) {
                $contentParsed = $this->formatter->render($post->content_parsed);
            }
        } catch (\Throwable) {
            $contentParsed = e($post->content);
        }

        $payload = [
            'id'            => $post->id,
            'discussionId'  => $post->discussion_id,
            'groupId'       => $post->group_id,
            'content'       => $post->content,
            'contentParsed' => $contentParsed,
            'createdAt'     => $post->created_at->toIso8601String(),
            'updatedAt'     => $post->updated_at->toIso8601String(),
            'reactions'     => (object) [],
            'actorReaction' => null,
            'parentPostId'  => $post->parent_post_id,
            'linkPreview'   => $post->link_preview,
            // Other members cannot edit/delete a post they didn't write.
            // The receiver's own canEdit/canDelete is determined client-side
            // by comparing post.user.id to app.session.user.id().
            'canEdit'       => false,
            'canDelete'     => false,
            'user'          => [
                'id'          => $actor->id,
                'displayName' => $actor->display_name,
                'avatarUrl'   => $actor->avatar_url,
                'slug'        => $actor->username,
            ],
        ];

        try {
            $this->container->make('flarum-realtime.pusher')->trigger('public', 'sg-post-created', $payload);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Realtime broadcast failed: ' . $e->getMessage());
        }
    }
}
