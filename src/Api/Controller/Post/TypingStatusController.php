<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Post;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
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
    public function __construct(private LoggerInterface $log) {}

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

        $isMember = $discussion->group
            ->members()
            ->where('user_id', $actor->id)
            ->exists();

        if (! $isMember) {
            return new EmptyResponse(403);
        }

        // Broadcast via Realtime if available.
        if (app()->bound('flarum-realtime.pusher')) {
            try {
                app('flarum-realtime.pusher')->trigger('public', 'sg-typing', [
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
