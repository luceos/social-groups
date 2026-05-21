<?php

namespace Ernestdefoe\SocialGroups\Listener;

use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
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

        $post = $event->post;

        // Broadcast only IDs on a group-scoped private channel. Two layers
        // of defense against the public-channel leak the previous version
        // had: (1) Pusher's protocol requires server-side auth for any
        // `private-*` subscription, so flarum/realtime's auth endpoint
        // gets a chance to deny non-members at the WebSocket layer; (2)
        // even if auth were bypassed, the payload carries no post content,
        // user identity, or rendered HTML — clients must rehydrate via
        // `/api/sg-thread-posts/{discussionId}` which is membership-gated.
        // This mirrors CLAUDE.md §29's "broadcast IDs only" pattern.
        $payload = [
            'id'           => $post->id,
            'discussionId' => $post->discussion_id,
            'groupId'      => $post->group_id,
            'parentPostId' => $post->parent_post_id,
        ];

        $channel = 'private-sg-group.' . (int) $post->group_id;

        try {
            $this->container->make('flarum-realtime.pusher')->trigger($channel, 'sg-post-created', $payload);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] Realtime broadcast failed: ' . $e->getMessage());
        }
    }
}
