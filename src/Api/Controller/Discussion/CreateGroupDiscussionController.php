<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateGroupDiscussionController implements RequestHandlerInterface
{
    public function __construct(private Formatter $formatter) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $body    = (array) ($request->getParsedBody() ?? []);
            $groupId = (int) ($body['groupId'] ?? 0);
            $content = trim((string) ($body['content'] ?? ''));
            // Title is optional — auto-generated from content if omitted
            $title   = trim((string) ($body['title'] ?? ''));

            if (! $groupId || ! $content) {
                return new JsonResponse(['error' => 'groupId and content are required.'], 422);
            }

            if (! $title) {
                $title = mb_substr(preg_replace('/\s+/', ' ', $content), 0, 80);
                if (mb_strlen($content) > 80) {
                    $title .= '…';
                }
            }

            if (mb_strlen($title) > 255) {
                return new JsonResponse(['error' => 'Title may not exceed 255 characters.'], 422);
            }

            if (mb_strlen($content) > 20000) {
                return new JsonResponse(['error' => 'Post content may not exceed 20 000 characters.'], 422);
            }

            $group = SocialGroup::findOrFail($groupId);

            $isMember = $group->members()->where('user_id', $actor->id)->exists();
            if (! $isMember) {
                return new JsonResponse(['error' => 'You must be a member of this group to post.'], 403);
            }

            $now           = \Carbon\Carbon::now();
            $contentParsed = $this->formatter->parse($content);

            $discussion = SocialGroupDiscussion::create([
                'group_id'             => $group->id,
                'user_id'              => $actor->id,
                'title'                => $title,
                'comment_count'        => 1,
                'last_posted_at'       => $now,
                'last_posted_user_id'  => $actor->id,
                'is_locked'            => false,
            ]);

            $firstPost = SocialGroupPost::create([
                'discussion_id'  => $discussion->id,
                'group_id'       => $group->id,
                'user_id'        => $actor->id,
                'content'        => $content,
                'content_parsed' => $contentParsed,
            ]);

            $renderedContent = $this->formatter->render($contentParsed);

            return new JsonResponse([
                'id'           => $discussion->id,
                'groupId'      => $discussion->group_id,
                'title'        => $discussion->title,
                'commentCount' => $discussion->comment_count,
                'isLocked'     => false,
                'lastPostedAt' => $now->toIso8601String(),
                'createdAt'    => ($discussion->created_at ?? $now)->toIso8601String(),
                'canDelete'    => true,
                'firstPost'    => [
                    'id'            => $firstPost->id,
                    'content'       => $firstPost->content,
                    'contentParsed' => $renderedContent,
                    'likeCount'     => 0,
                    'isLiked'       => false,
                    'canEdit'       => true,
                    'createdAt'     => ($firstPost->created_at ?? $now)->toIso8601String(),
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
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            resolve('log')->error('[social-groups] CreateGroupDiscussionController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
