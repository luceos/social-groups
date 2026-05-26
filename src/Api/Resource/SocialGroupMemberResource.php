<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
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
 * Recurso JSON:API para SocialGroupMember (linha de membership de um
 * grupo). Substituiu ListGroupMembersController + Promote/Demote/Kick
 * controllers — todos viraram Index/Delete/Endpoint actions aqui.
 *
 * Index requer `?filter[group]=<id>` (sem ele responde vazio). A
 * checagem de privacidade do grupo roda em scope(); membros banidos
 * (banned_at != null) são excluídos por padrão.
 *
 * Promote/demote são `Endpoint\Endpoint::make()` actions e exigem ser
 * o creator do próprio grupo (`->can('promote'|'demote')` consulta
 * SocialGroupMemberPolicy).
 */
class SocialGroupMemberResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'social-group-members';
    }

    public function model(): string
    {
        return SocialGroupMember::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor  = RequestUtil::getActor($context->request);
        $params = $context->request->getQueryParams();

        // scope() runs for Index AND for the action endpoints
        // (kick/promote/demote) which load $context->model by PK. The
        // `?groupId=N` requirement only makes sense for Index — on the
        // PK-lookup path we still want to load the row so the action's
        // ->can('delete'|'promote'|'demote') policy gate can evaluate.
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
        if (! GroupVisibility::canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        // Eager-load both `user` and `group`. The latter feeds the
        // `canModerate` and `canRemove` field getters, which each read
        // `$m->group` once per row; without this `with`, a page of N
        // members emits N+N extra group queries (all returning the same
        // row, since every member of an Index page belongs to the same
        // group). 41 queries → 3.
        $query->where('social_group_members.group_id', $groupId)
              ->whereNull('social_group_members.banned_at')
              ->with(['user', 'group']);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['user']),

            // "Kick" é soft-delete (set banned_at) em vez de remoção
            // física da row — preserva o histórico de membership e
            // permite que outras features ("este usuário foi banido,
            // não re-admita") consultem o motivo. Endpoint\Delete
            // hard-deletaria, então usamos um Endpoint customizado
            // com verb DELETE mas semântica soft.
            Endpoint\Endpoint::make('social-group-members.kick')
                ->route('DELETE', '/{id}')
                ->authenticated()
                ->can('delete')
                ->action(fn (Context $context) => $this->doKick($context)),

            Endpoint\Endpoint::make('social-group-members.promote')
                ->route('POST', '/{id}/promote')
                ->authenticated()
                ->can('promote')
                ->action(fn (Context $context) => $this->doSetRole($context, 'moderator')),

            Endpoint\Endpoint::make('social-group-members.demote')
                ->route('POST', '/{id}/demote')
                ->authenticated()
                ->can('demote')
                ->action(fn (Context $context) => $this->doSetRole($context, 'member')),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Integer::make('userId')
                ->property('user_id'),

            Schema\Str::make('role'),

            Schema\Str::make('displayName')
                ->get(fn (SocialGroupMember $m) => $m->user?->display_name ?? ''),

            Schema\Str::make('avatarUrl')
                ->nullable()
                ->get(fn (SocialGroupMember $m) => $m->user?->avatar_url),

            Schema\Str::make('slug')
                ->nullable()
                ->get(fn (SocialGroupMember $m) => $m->user?->username),

            Schema\DateTime::make('joinedAt')
                ->property('joined_at')
                ->nullable(),

            Schema\Boolean::make('canModerate')
                ->get(function (SocialGroupMember $m, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    $group = $m->group;
                    return $group !== null && (int) $actor->id === (int) $group->user_id;
                }),

            Schema\Boolean::make('canRemove')
                ->get(function (SocialGroupMember $m, Context $context) {
                    $actor = $context->getActor();
                    return $actor->can('delete', $m);
                }),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    protected function doSetRole(Context $context, string $role): SocialGroupMember
    {
        /** @var SocialGroupMember $target */
        $target = $context->model;
        if ($target->banned_at !== null) {
            throw new BadRequestException('User is not an active member of this group');
        }
        if ($target->role === 'creator') {
            throw new BadRequestException(
                $role === 'moderator'
                    ? 'Cannot promote another creator'
                    : 'Cannot demote the group creator'
            );
        }
        $target->role = $role;
        $target->save();
        return $target;
    }

    /**
     * Soft-kick: set `banned_at` em vez de DELETE físico, espelhando
     * o KickGroupMemberController legado. Decrementa o
     * `member_count` denormalizado do grupo.
     */
    protected function doKick(Context $context): SocialGroupMember
    {
        /** @var SocialGroupMember $target */
        $target = $context->model;

        // Idempotente: já banido, no-op (mas devolve 200, não 404).
        if ($target->banned_at !== null) {
            return $target;
        }
        $target->banned_at = \Carbon\Carbon::now();
        $target->save();

        if ($target->group) {
            $target->group->decrement('member_count');
        }

        return $target;
    }
}
