<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Container\Container;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /api/sg-typing
 *
 * Body: { discussionId: int, isTyping: bool }
 *
 * Broadcasts a typing-status event to all connected WebSocket clients
 * watching the given discussion.  No-op if flarum/realtime is not
 * installed.  Returns 204 No Content on success.
 */
class TypingStatusController implements RequestHandlerInterface
{
    public function __construct(
        private LoggerInterface $log,
        private Container $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // Only registered users can appear in typing indicators.
        if ($actor->isGuest()) {
            return new EmptyResponse(403);
        }

        $body         = (array) ($request->getParsedBody() ?? []);
        $discussionId = (int) ($body['discussionId'] ?? 0);
        $isTyping     = (bool) ($body['isTyping'] ?? false);

        if (! $discussionId) {
            return new EmptyResponse(422);
        }

        // Verify the actor is a member of the group owning this discussion.
        $discussion = SocialGroupDiscussion::with('group')->find($discussionId);
        if (! $discussion) {
            return new EmptyResponse(404);
        }

        $isMember = (bool) $discussion->group
            ?->activeMembership($actor->id)
            ->exists();

        if (! $isMember) {
            return new EmptyResponse(403);
        }

        // Broadcast via Realtime on a group-scoped private channel.  The
        // previous 'public' channel let any logged-in client — including
        // non-members of the group — receive every typing event, which
        // disclosed who was active in which private discussion. Pusher's
        // protocol requires server-side auth for `private-*` subscriptions,
        // so flarum/realtime's auth endpoint enforces the same membership
        // gate at the WebSocket layer that this controller enforces at the
        // HTTP layer above. Container is injected rather than reached for
        // via app() so the dependency is discoverable and the listener
        // stays testable.
        if ($this->container->bound('flarum-realtime.pusher')) {
            $channel = 'private-sg-group.' . (int) $discussion->group_id;
            try {
                $this->container->make('flarum-realtime.pusher')->trigger($channel, 'sg-typing', [
                    'discussionId' => $discussionId,
                    'userId'       => $actor->id,
                    'displayName'  => $actor->display_name,
                    'avatarUrl'    => $actor->avatar_url,
                    'isTyping'     => $isTyping,
                ]);
            } catch (\Throwable $e) {
                $this->log->error('[social-groups] Typing broadcast failed: ' . $e->getMessage());
            }
        }

        return new EmptyResponse(204);
    }
}
