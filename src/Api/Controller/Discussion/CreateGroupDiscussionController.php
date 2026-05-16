<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Discussion;

use Ernestdefoe\SocialGroups\Api\Concern\SanitizesLinkPreview;
use Ernestdefoe\SocialGroups\Api\Concern\SerializesPoll;
use Ernestdefoe\SocialGroups\Model\SgPoll;
use Ernestdefoe\SocialGroups\Model\SgPollOption;
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

class CreateGroupDiscussionController implements RequestHandlerInterface
{
    use SanitizesLinkPreview;
    use SerializesPoll;

    public function __construct(
        private Formatter $formatter,
        private LoggerInterface $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $body    = (array) ($request->getParsedBody() ?? []);
            $groupId = (int) ($body['groupId'] ?? 0);
            $content = trim((string) ($body['content'] ?? ''));
            $title   = trim((string) ($body['title'] ?? ''));
            $linkPreview = isset($body['linkPreview']) && is_array($body['linkPreview'])
                ? $this->sanitizeLinkPreview($body['linkPreview'])
                : null;

            $pollData = null;
            if (isset($body['poll']) && is_array($body['poll'])) {
                $pq = trim((string) ($body['poll']['question'] ?? ''));
                $po = array_values(array_filter(array_map(
                    fn ($t) => mb_substr(trim((string) $t), 0, 255),
                    (array) ($body['poll']['options'] ?? [])
                ), fn ($t) => $t !== ''));

                if ($pq !== '' && count($po) >= 2 && count($po) <= 6) {
                    $pollData = [
                        'question'        => mb_substr($pq, 0, 500),
                        'options'         => $po,
                        'is_multi_select' => ! empty($body['poll']['isMultiSelect']),
                        'ends_at'         => null,
                    ];
                }
            }

            if (! $groupId || (! $content && ! $pollData)) {
                return new JsonResponse(['error' => 'groupId and either content or a poll are required.'], 422);
            }

            if (! $title) {
                if ($content) {
                    $title = mb_substr(preg_replace('/\s+/', ' ', $content), 0, 80);
                    if (mb_strlen($content) > 80) $title .= '…';
                } else {
                    $title = mb_substr($pollData['question'], 0, 80);
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
            $contentParsed = $content ? $this->formatter->parse($content) : null;

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
                'link_preview'   => $linkPreview,
            ]);

            $poll = null;
            if ($pollData) {
                $poll = SgPoll::create([
                    'discussion_id'   => $discussion->id,
                    'question'        => $pollData['question'],
                    'is_multi_select' => $pollData['is_multi_select'],
                    'ends_at'         => $pollData['ends_at'],
                ]);
                foreach ($pollData['options'] as $i => $optionText) {
                    SgPollOption::create([
                        'poll_id'    => $poll->id,
                        'text'       => $optionText,
                        'sort_order' => $i,
                    ]);
                }
                $poll->load('options');
            }

            $actorId         = $actor->id;
            $renderedContent = $contentParsed ? $this->formatter->render($contentParsed) : '';

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
                    'reactions'     => (object) [],
                    'actorReaction' => null,
                    'linkPreview'   => $firstPost->link_preview,
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
                'poll' => $this->serializePoll($poll, $actorId),
            ], 201);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] CreateGroupDiscussionController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }

}
