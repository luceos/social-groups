<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
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
 * Recurso JSON:API para discussões em grupos sociais. Convive com o
 * `ListGroupDiscussionsController` legado em /sg-discussions/{groupId}
 * durante a migração — o JS muda na fase 2 e o controller velho
 * desaparece junto.
 *
 * Index requer `?filter[group]=<id>` (sem ele responde vazio). A
 * checagem de privacidade do grupo roda em scope(); o policy gate em
 * `view` cobre fetches individuais de Show. Search via `?filter[q]`
 * faz LIKE escapado em título e content do primeiro post. is_pinned e
 * sort por is_pinned só entram quando o `SchemaCapabilities` reporta
 * que a coluna existe — extensões instaladas antes da migração que a
 * cria continuam funcionando sem crash.
 */
class SocialGroupDiscussionResource extends AbstractDatabaseResource
{
    public function __construct(protected SchemaCapabilities $capabilities)
    {
    }

    public function type(): string
    {
        return 'social-group-discussions';
    }

    public function model(): string
    {
        return SocialGroupDiscussion::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);
        $raw   = $context->request->getQueryParams()['filter'] ?? [];
        $filter = is_array($raw) ? $raw : [];

        $groupId = isset($filter['group']) ? (int) $filter['group'] : 0;

        if ($groupId <= 0) {
            // Sem filtro de grupo, recusamos retornar lista. Mantém o
            // contrato do antigo /sg-discussions/{groupId}: discussões
            // sempre são listadas dentro do escopo de UM grupo.
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('social_group_discussions.group_id', $groupId);

        $group = SocialGroup::find($groupId);
        if ($group === null) {
            $query->whereRaw('1 = 0');
            return;
        }

        if (! GroupVisibility::canSee($actor, $group)) {
            throw new PermissionDeniedException();
        }

        if ($this->capabilities->isGallery) {
            $query->where(function ($q) {
                $q->whereNull('social_group_discussions.is_gallery')
                  ->orWhere('social_group_discussions.is_gallery', false);
            });
        }

        $q = isset($filter['q']) ? trim((string) $filter['q']) : '';
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('social_group_discussions.title', 'like', $like)
                  ->orWhereExists(function ($sub) use ($like) {
                      $sub->from('social_group_posts')
                          ->whereColumn('social_group_posts.discussion_id', 'social_group_discussions.id')
                          ->where('social_group_posts.content', 'like', $like);
                  });
            });
        }

        if ($this->capabilities->isPinned) {
            $query->orderByDesc('social_group_discussions.is_pinned');
        }
        $query->orderByDesc('social_group_discussions.last_posted_at');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['firstPost', 'firstPost.user', 'user', 'lastPostedUser']),

            Endpoint\Show::make()
                ->can('view'),

            Endpoint\Create::make()
                ->authenticated()
                ->can('create'),

            Endpoint\Delete::make()
                ->can('delete'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('groupId')
                ->property('group_id')
                ->writableOnCreate()
                ->required(),

            Schema\Str::make('title')
                ->writableOnCreate()
                ->maxLength(255),

            Schema\Integer::make('commentCount')
                ->property('comment_count'),

            Schema\Boolean::make('isLocked')
                ->property('is_locked'),

            Schema\Boolean::make('isPinned')
                ->visible(fn () => $this->capabilities->isPinned)
                ->get(fn ($d) => $this->capabilities->isPinned ? (bool) $d->is_pinned : false),

            Schema\DateTime::make('lastPostedAt')
                ->property('last_posted_at'),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\Boolean::make('canDelete')
                ->get(function ($d, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    return (int) $actor->id === (int) $d->user_id
                        || $actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate');
                }),

            Schema\Boolean::make('canShare')
                ->get(fn ($d, Context $context) => $context->getActor()->exists),

            Schema\Boolean::make('canPin')
                ->get(function ($d, Context $context) {
                    if (! $this->capabilities->isPinned) {
                        return false;
                    }
                    return $context->getActor()->can('pin', $d);
                }),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('lastPostedUser')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('firstPost')
                ->type('social-group-posts')
                ->includable(),
        ];
    }

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $actor = $context->getActor();

        $groupId = (int) ($model->group_id ?? 0);
        if ($groupId <= 0) {
            throw new BadRequestException('groupId é obrigatório');
        }

        $group = SocialGroup::find($groupId);
        if ($group === null) {
            throw new BadRequestException('Grupo não encontrado');
        }

        $isMember = $group->members()->where('user_id', $actor->id)->exists();
        $isOwner  = (int) $actor->id === (int) $group->user_id;
        $isMod    = $actor->isAdmin()
                  || $actor->hasPermission('ernestdefoe-social-groups.moderate');

        if (! ($isMember || $isOwner || $isMod)) {
            throw new PermissionDeniedException();
        }

        $model->user_id       = $actor->id;
        $model->comment_count = 1;
        $model->last_posted_at = \Carbon\Carbon::now();
        $model->last_posted_user_id = $actor->id;

        return null;
    }

}
