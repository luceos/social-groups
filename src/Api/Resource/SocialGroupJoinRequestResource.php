<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupJoinRequest;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Exception\BadRequestException;

/**
 * JSON:API resource for pending membership requests against a group.
 * Replaces ListJoinRequestsController + ApproveJoinRequestController
 * + RejectJoinRequestController.
 *
 * Index requires `?filter[group]=<id>` and that the actor be the group
 * owner or an admin (additional filters such as `status=pending` live
 * in scope). Approve is an Endpoint\Endpoint::make() action that flips
 * the status to 'approved' and creates the membership row if it
 * doesn't already exist. Delete (reject) marks the status as
 * 'rejected' instead of hard-deleting, preserving the history for
 * anti-spam purposes.
 */
class SocialGroupJoinRequestResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'social-group-join-requests';
    }

    public function model(): string
    {
        return SocialGroupJoinRequest::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);
        $params = $context->request->getQueryParams();

        // scope() runs for Index AND for approve/reject action endpoints
        // which load $context->model by PK. Only Index needs the
        // ?groupId=N filter; PK-lookup paths apply ->can('approve'|'delete')
        // through the policy.
        $isIndex = $context->endpoint instanceof Endpoint\Index;
        if (! $isIndex) {
            $query->with('user');
            return;
        }

        // `?groupId=N` plain query param — not JSON:API filter[group]
        // because AbstractDatabaseResource::filters() is final + throws.
        $groupId = isset($params['groupId']) ? (int) $params['groupId'] : 0;
        if ($groupId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $group = SocialGroup::find($groupId);
        if ($group === null) {
            $query->whereRaw('1 = 0');
            return;
        }

        $actor->assertRegistered();
        // Listing requests is privileged to the group owner or admins
        // only — same rule ListJoinRequestsController used.
        if ((int) $actor->id !== (int) $group->user_id && ! $actor->isAdmin()) {
            throw new PermissionDeniedException();
        }

        $query->where('social_group_join_requests.group_id', $groupId)
              ->where('social_group_join_requests.status', 'pending')
              ->with('user');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['user']),

            Endpoint\Endpoint::make('social-group-join-requests.approve')
                ->route('POST', '/{id}/approve')
                ->authenticated()
                ->can('approve')
                ->action(fn (Context $context) => $this->doApprove($context)),

            // Reject is soft (status='rejected') instead of a hard
            // delete so that repeated attempts by the same user don't
            // create floods of pending requests — the owner already
            // decided.
            Endpoint\Endpoint::make('social-group-join-requests.reject')
                ->route('DELETE', '/{id}')
                ->authenticated()
                ->can('delete')
                ->action(fn (Context $context) => $this->doReject($context)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Integer::make('userId')
                ->property('user_id'),

            Schema\Str::make('status'),

            Schema\Str::make('displayName')
                ->nullable()
                ->get(fn (SocialGroupJoinRequest $r) => $r->user?->display_name),

            Schema\Str::make('avatarUrl')
                ->nullable()
                ->get(fn (SocialGroupJoinRequest $r) => $r->user?->avatar_url),

            Schema\DateTime::make('createdAt')
                ->property('created_at')
                ->nullable(),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    /**
     * Approves the request + creates the membership row idempotently +
     * increments the denormalised member_count. Mirrors the legacy
     * ApproveJoinRequestController.
     */
    protected function doApprove(Context $context): SocialGroupJoinRequest
    {
        /** @var SocialGroupJoinRequest $req */
        $req = $context->model;

        if ($req->status !== 'pending') {
            throw new BadRequestException('Request is not pending');
        }

        $req->status = 'approved';
        $req->save();

        $group = $req->group;
        if ($group !== null) {
            $existing = $group->members()->where('user_id', $req->user_id)->first();
            if ($existing === null) {
                $group->members()->create([
                    'user_id'   => $req->user_id,
                    'role'      => 'member',
                    'joined_at' => \Carbon\Carbon::now(),
                ]);
                $group->increment('member_count');
            }
        }

        return $req;
    }

    protected function doReject(Context $context): SocialGroupJoinRequest
    {
        /** @var SocialGroupJoinRequest $req */
        $req = $context->model;
        $req->status = 'rejected';
        $req->save();
        return $req;
    }
}
