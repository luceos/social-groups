<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class GroupAnalyticsController implements RequestHandlerInterface
{
    public function __construct(private LoggerInterface $log) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $params  = $request->getQueryParams();
            $groupId = $request->getAttribute('groupId') ?? ($params['groupId'] ?? null);
            if (! $groupId) {
                preg_match('#/sg-analytics/(\d+)#', $request->getUri()->getPath(), $m);
                $groupId = $m[1] ?? null;
            }
            $group   = SocialGroup::findOrFail($groupId);

            $actorMember = $group->members()->where('user_id', $actor->id)->first();
            $actorRole   = $actorMember?->role;

            $canView = $actor->isAdmin()
                || in_array($actorRole, ['creator', 'moderator'], true);

            if (! $canView) {
                return new JsonResponse(['error' => 'Only group moderators and admins can view analytics.'], 403);
            }

            // ── Member growth: last 30 days ───────────────────────────────
            $joinsByDay = $group->members()
                ->whereNull('banned_at')
                ->where('joined_at', '>=', Carbon::now()->subDays(29)->startOfDay())
                ->selectRaw('DATE(joined_at) as day, COUNT(*) as count')
                ->groupBy('day')
                ->pluck('count', 'day')
                ->all();

            $memberGrowth = [];
            for ($i = 29; $i >= 0; $i--) {
                $day            = Carbon::now()->subDays($i)->format('Y-m-d');
                $memberGrowth[] = ['date' => $day, 'count' => (int) ($joinsByDay[$day] ?? 0)];
            }

            // ── Post volume: last 8 weeks ─────────────────────────────────
            $postVolume = [];
            for ($i = 7; $i >= 0; $i--) {
                $start = Carbon::now()->subWeeks($i)->startOfWeek();
                $end   = $start->copy()->endOfWeek();
                $count = SocialGroupPost::where('group_id', $groupId)
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
                $postVolume[] = ['weekStart' => $start->format('M j'), 'count' => $count];
            }

            // ── Top 5 most-reacted posts ──────────────────────────────────
            $topPosts = SocialGroupPost::where('group_id', $groupId)
                ->withCount('reactions as total_reactions')
                ->having('total_reactions', '>', 0)
                ->orderByDesc('total_reactions')
                ->take(5)
                ->with('user')
                ->get()
                ->map(fn ($p) => [
                    'postId'         => $p->id,
                    'discussionId'   => $p->discussion_id,
                    'snippet'        => mb_substr(strip_tags($p->content), 0, 120),
                    'totalReactions' => $p->total_reactions,
                    'user'           => $p->user ? [
                        'displayName' => $p->user->display_name,
                        'avatarUrl'   => $p->user->avatar_url,
                    ] : null,
                ]);

            // ── Summary stats ─────────────────────────────────────────────
            $totalPosts     = SocialGroupPost::where('group_id', $groupId)->count();
            $totalReactions = SocialGroupPostReaction::whereHas('post', fn ($q) => $q->where('group_id', $groupId))->count();

            return new JsonResponse([
                'summary' => [
                    'totalMembers'   => (int) $group->member_count,
                    'totalPosts'     => $totalPosts,
                    'totalReactions' => $totalReactions,
                ],
                'memberGrowth' => $memberGrowth,
                'postVolume'   => $postVolume,
                'topPosts'     => $topPosts->values(),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return new JsonResponse(['error' => 'Group not found.'], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] GroupAnalyticsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => 'An unexpected error occurred.'], 500);
        }
    }
}
