<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Model\SgPollVote;
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
 * Recurso JSON:API para discussões em grupos sociais. Substituiu o
 * antigo `ListGroupDiscussionsController` (removido em Phase 2b do
 * audit #4); o JS chama via `listDiscussions()` em utils/api.js, que
 * projeta a resposta JSON:API no shape legado para que o restante do
 * pipeline de feed não precise mudar.
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

        /**
         * Eager-loads para evitar N+1 quando os campos computados
         * (`sharedFrom`, `poll`, `firstPost`) forem renderizados. Cada
         * `with()` aqui economiza N consultas no escopo da página
         * (20 discussões → 1 consulta por relação em vez de 20).
         *
         * `firstPost.reactions` é o que alimenta o campo `reactions`
         * do SocialGroupPostResource — sem essa pré-carga, cada post
         * incluído como firstPost emite 1 query extra de reactions.
         */
        $query->with([
            'user',
            'lastPostedUser',
            'firstPost.user',
        ]);

        if ($this->capabilities->reactions) {
            $query->with('firstPost.reactions');
        }

        if ($this->capabilities->sharedFrom) {
            $query->with([
                'sharedFromDiscussion.group',
                'sharedFromDiscussion.user',
                'sharedFromDiscussion.firstPost.user',
            ]);
        }

        if ($this->capabilities->polls) {
            $query->with('poll.options');
        }
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
                    if ((int) $actor->id === (int) $d->user_id) {
                        return true;
                    }
                    if ($actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return true;
                    }
                    $group = $d->group;
                    if ($group === null) {
                        return false;
                    }
                    return $group->members()
                        ->where('user_id', $actor->id)
                        ->whereIn('role', ['creator', 'moderator'])
                        ->exists();
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

            Schema\Arr::make('sharedFrom')
                ->visible(fn () => $this->capabilities->sharedFrom)
                ->get(function (SocialGroupDiscussion $d) {
                    if (! $this->capabilities->sharedFrom) {
                        return null;
                    }
                    return $this->buildSharedFrom($d);
                }),

            Schema\Arr::make('poll')
                ->visible(fn () => $this->capabilities->polls)
                ->get(function (SocialGroupDiscussion $d, Context $context) {
                    if (! $this->capabilities->polls) {
                        return null;
                    }
                    return $this->buildPoll($d, $context);
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

    /**
     * Constrói a estrutura denormalizada de "compartilhado de" no
     * formato esperado pelo frontend (SGFeed-sharedCard). Lê das
     * relações pré-carregadas em scope() — `sharedFromDiscussion`,
     * `sharedFromDiscussion.group`, `sharedFromDiscussion.firstPost.user`.
     * Se a coluna `shared_from_discussion_id` ainda não foi migrada,
     * o campo já é hidden via `visible()` — esta função não roda.
     */
    protected function buildSharedFrom(SocialGroupDiscussion $d): ?array
    {
        $orig = $d->sharedFromDiscussion;
        if ($orig === null) {
            return null;
        }
        $fp = $orig->firstPost;
        return [
            'discussionId' => (int) $orig->id,
            'title'        => $orig->title,
            'groupId'      => (int) $orig->group_id,
            'groupName'    => $orig->group?->name,
            'groupSlug'    => $orig->group?->slug,
            'snippet'      => $fp ? mb_substr(strip_tags($fp->content ?? ''), 0, 200) : '',
            'user'         => $orig->user ? [
                'displayName' => $orig->user->display_name,
                'avatarUrl'   => $orig->user->avatar_url,
            ] : null,
        ];
    }

    /**
     * Constrói o payload de poll no formato que o frontend espera. As
     * contagens de voto por opção e o voto do actor exigem queries
     * adicionais — fazemos um único batch query por discussão com
     * poll. Para um feed com N discussões e M com poll, isso é 2*M
     * queries em vez de N+M (sem batching seria 2*M ou pior; com
     * batching de scope haveria 1 query global, mas adiar essa
     * otimização não compensa enquanto o número médio de polls por
     * página for baixo).
     */
    protected function buildPoll(SocialGroupDiscussion $d, Context $context): ?array
    {
        $poll = $d->poll;
        if ($poll === null) {
            return null;
        }
        $actor   = $context->getActor();
        $options = $poll->options->sortBy('sort_order');

        $optionIds   = $options->pluck('id')->all();
        $voteCounts  = SgPollVote::whereIn('option_id', $optionIds)
            ->selectRaw('option_id, COUNT(*) as cnt')
            ->groupBy('option_id')
            ->pluck('cnt', 'option_id')
            ->all();

        $actorVotes = $actor->exists
            ? SgPollVote::where('poll_id', $poll->id)
                ->where('user_id', $actor->id)
                ->pluck('option_id')
                ->all()
            : [];

        return [
            'id'                  => (int) $poll->id,
            'question'            => $poll->question,
            'isMultiSelect'       => (bool) $poll->is_multi_select,
            'endsAt'              => $poll->ends_at?->toIso8601String(),
            'totalVotes'          => (int) array_sum($voteCounts),
            'actorVotedOptionIds' => array_map('intval', $actorVotes),
            'options'             => $options->map(fn ($o) => [
                'id'        => (int) $o->id,
                'text'      => $o->text,
                'voteCount' => (int) ($voteCounts[$o->id] ?? 0),
            ])->values()->all(),
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
