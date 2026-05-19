<?php

namespace Ernestdefoe\SocialGroups\Api\Controller\Poll;

use Ernestdefoe\SocialGroups\Api\Concern\SerializesPoll;
use Ernestdefoe\SocialGroups\Model\SgPoll;
use Ernestdefoe\SocialGroups\Model\SgPollVote;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class VotePollController implements RequestHandlerInterface
{
    use SerializesPoll;

    public function __construct(
        private LoggerInterface $log,
        private ConnectionInterface $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor  = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $pollId = (int) $request->getAttribute('pollId');
            if (! $pollId) {
                preg_match('#/sg-polls/(\d+)#', $request->getUri()->getPath(), $m);
                $pollId = (int) ($m[1] ?? 0);
            }
            $body   = (array) ($request->getParsedBody() ?? []);
            $optionIds = array_map('intval', (array) ($body['optionIds'] ?? []));

            $poll = SgPoll::with('options')->find($pollId);
            if (! $poll) {
                return new JsonResponse(['error' => 'Poll not found.'], 404);
            }

            // Actor must be a member of the group that owns the discussion
            $discussion = SocialGroupDiscussion::find($poll->discussion_id);
            if (! $discussion) {
                return new JsonResponse(['error' => 'Discussion not found.'], 404);
            }
            $isMember = $discussion->group
                ? $discussion->group->members()
                    ->where('user_id', $actor->id)
                    ->whereNull('banned_at')
                    ->exists()
                : false;
            if (! $isMember && ! $actor->isAdmin()) {
                return new JsonResponse(['error' => 'You must be a member of this group to vote.'], 403);
            }

            // Poll closed?
            if ($poll->ends_at && $poll->ends_at->isPast()) {
                return new JsonResponse(['error' => 'This poll has ended.'], 422);
            }

            // Validate option IDs all belong to this poll
            $validOptionIds = $poll->options->pluck('id')->all();
            foreach ($optionIds as $id) {
                if (! in_array($id, $validOptionIds, true)) {
                    return new JsonResponse(['error' => 'Invalid option.'], 422);
                }
            }

            // Single-select: exactly one option
            if (! $poll->is_multi_select && count($optionIds) > 1) {
                return new JsonResponse(['error' => 'This poll only allows one choice.'], 422);
            }

            // Replace existing votes atomically. Without the transaction
            // the delete+insert pair is observable mid-flight by a
            // concurrent reader (they'd see zero votes for this actor)
            // and a failure on insert leaves the actor with no votes at
            // all even though they had votes before the request.
            $this->db->transaction(function () use ($pollId, $optionIds, $actor) {
                SgPollVote::where('poll_id', $pollId)
                    ->where('user_id', $actor->id)
                    ->delete();

                foreach ($optionIds as $optionId) {
                    SgPollVote::create([
                        'poll_id'   => $pollId,
                        'option_id' => $optionId,
                        'user_id'   => $actor->id,
                    ]);
                }
            });

            return new JsonResponse($this->serializePoll($poll, $actor->id));
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] VotePollController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
