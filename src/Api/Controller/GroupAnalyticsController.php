<?php

namespace Ernestdefoe\SocialGroups\Api\Controller;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Api\Concern\ReadsRouteParam;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Model\SocialGroupPostReaction;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class GroupAnalyticsController implements RequestHandlerInterface
{
    use ReadsRouteParam;

    public function __construct(private LoggerInterface $log, private TranslatorInterface $translator) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor   = RequestUtil::getActor($request);
            $actor->assertRegistered();

            $groupId = $this->routeParam($request, 'groupId', '/sg-analytics/{groupId}');
            $group   = SocialGroup::findOrFail($groupId);

            $actorMember = $group->activeMembership($actor->id)->first();
            $actorRole   = $actorMember?->role;

            $canView = $actor->isAdmin()
                || in_array($actorRole, ['creator', 'moderator'], true);

            if (! $canView) {
                return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.analytics_forbidden')], 403);
            }

            // Day-bucketing is pushed into the database via a single GROUP BY
            // per metric instead of pulling every row into PHP. The day
            // expression is driver-gated (CLAUDE.md §39.2) so it stays
            // portable across the databases Flarum 2.x supports — MySQL/MariaDB
            // have DATE(), SQLite uses strftime(), PostgreSQL uses to_char().
            $driver = $group->getConnection()->getDriverName();

            // ── Member growth: last 30 days ───────────────────────────────
            $joinsByDay = $this->dailyCounts(
                $group->members()
                    ->whereNull('banned_at')
                    ->where('joined_at', '>=', Carbon::now()->subDays(29)->startOfDay()),
                'joined_at',
                $driver,
            );

            $memberGrowth = [];
            for ($i = 29; $i >= 0; $i--) {
                $day            = Carbon::now()->subDays($i)->format('Y-m-d');
                $memberGrowth[] = ['date' => $day, 'count' => (int) ($joinsByDay[$day] ?? 0)];
            }

            // ── Post volume: last 8 weeks ─────────────────────────────────
            // Grouped by day in SQL (≤56 rows), then summed into weeks in PHP.
            $earliestWeekStart = Carbon::now()->subWeeks(7)->startOfWeek();

            $postsByDay = $this->dailyCounts(
                SocialGroupPost::where('group_id', $groupId)
                    ->where('created_at', '>=', $earliestWeekStart),
                'created_at',
                $driver,
            );

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
            // Join instead of a correlated whereHas subquery (planner can hash-join
            // on the indexed FK rather than re-run a subquery per reaction row).
            $totalReactions = SocialGroupPostReaction::query()
                ->join('social_group_posts', 'social_group_post_reactions.post_id', '=', 'social_group_posts.id')
                ->where('social_group_posts.group_id', $groupId)
                ->count();

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
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.group_not_found')], 404);
        } catch (\Throwable $e) {
            $this->log->error('[social-groups] GroupAnalyticsController: ' . $e->getMessage(), ['exception' => $e]);
            return new JsonResponse(['error' => $this->translator->trans('ernestdefoe-social-groups.lib.errors.unexpected')], 500);
        }
    }

    /**
     * Counts rows grouped by calendar day, computed entirely in SQL. Returns
     * a map of `Y-m-d` → count so the caller fills missing days with zero.
     * Replaces pulling every timestamp into a PHP Collection.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation $query
     * @return array<string, int>
     */
    private function dailyCounts($query, string $column, string $driver): array
    {
        $dayExpr = $this->dayExpr($column, $driver);

        return $query
            ->selectRaw("$dayExpr as bucket_day, count(*) as bucket_count")
            ->groupByRaw($dayExpr)
            ->pluck('bucket_count', 'bucket_day')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Portable SQL expression that truncates a datetime column to `Y-m-d`.
     * Column name is a fixed internal literal — never request input — so raw
     * interpolation carries no injection risk.
     */
    private function dayExpr(string $column, string $driver): string
    {
        return match ($driver) {
            'pgsql'  => "to_char($column, 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', $column)",
            default  => "DATE($column)",
        };
    }
}
