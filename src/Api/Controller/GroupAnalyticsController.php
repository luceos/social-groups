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
            // Day-bucketing happens in PHP so the query stays portable
            // across MySQL, PostgreSQL, and SQLite — PostgreSQL has no
            // scalar DATE() function, and SQLite's behaviour with
            // datetime columns differs.
            $joinedAtList = $group->members()
                ->whereNull('banned_at')
                ->where('joined_at', '>=', Carbon::now()->subDays(29)->startOfDay())
                ->pluck('joined_at');

            $joinsByDay = [];
            foreach ($joinedAtList as $joinedAt) {
                if ($joinedAt === null) continue;
                $day = $joinedAt instanceof Carbon
                    ? $joinedAt->format('Y-m-d')
                    : Carbon::parse($joinedAt)->format('Y-m-d');
                $joinsByDay[$day] = ($joinsByDay[$day] ?? 0) + 1;
            }

            $memberGrowth = [];
            for ($i = 29; $i >= 0; $i--) {
                $day            = Carbon::now()->subDays($i)->format('Y-m-d');
                $memberGrowth[] = ['date' => $day, 'count' => (int) ($joinsByDay[$day] ?? 0)];
            }

            // ── Post volume: last 8 weeks ─────────────────────────────────
            // Pull raw timestamps and bucket by day in PHP, then by week.
            // Avoids both the N+1 (8 separate COUNTs) and the MySQL-only
            // DATE()/YEARWEEK() functions, so analytics stays portable
            // across the three databases Flarum 2.x supports.
            $earliestWeekStart = Carbon::now()->subWeeks(7)->startOfWeek();

            $postCreatedAtList = SocialGroupPost::where('group_id', $groupId)
                ->where('created_at', '>=', $earliestWeekStart)
                ->pluck('created_at');

            $postsByDay = [];
            foreach ($postCreatedAtList as $createdAt) {
                if ($createdAt === null) continue;
                $day = $createdAt instanceof Carbon
                    ? $createdAt->format('Y-m-d')
                    : Carbon::parse($createdAt)->format('Y-m-d');
                $postsByDay[$day] = ($postsByDay[$day] ?? 0) + 1;
            }

            $postVolume = [];
            for ($i = 7; $i >= 0; $i--) {
                $start    = Carbon::now()->subWeeks($i)->startOfWeek();
                $end      = $start->copy()->endOfWeek();
                $startStr = $start->format('Y-m-d');
                $endStr   = $end->format('Y-m-d');
                $count    = 0;
                foreach ($postsByDay as $day => $cnt) {
                    if ($day >= $startStr && $day <= $endStr) {
                        $count += (int) $cnt;
                    }
                }
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
