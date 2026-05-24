<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Access\GroupVisibility;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * Recurso JSON:API para SocialGroupPost.
 *
 * Originalmente este recurso existia apenas para satisfazer o
 * NotificationResource (polimorfismo de subject — sem ele, o
 * `?include=subject` em /api/notifications quebrava com TypeError —
 * ver vendor/flarum/json-api-server/src/Endpoint/Concerns/IncludesData.php:84).
 *
 * Agora também é o backing store para o include `firstPost` do
 * SocialGroupDiscussionResource e para a listagem de thread posts
 * (filter[discussion]=N). Endpoints CRUD (create/update/delete/pin/
 * react) seguem em controllers clássicos — são ações específicas que
 * não cabem no contrato puro CRUD do JSON:API.
 *
 * `reactions` e `actorReaction` são computados a partir da relação
 * `reactions()` que precisa ser pre-carregada via `with()` no scope()
 * do recurso que estiver listando — caso contrário, cada post emite
 * uma query extra (N+1) para resolver. O include `?include=firstPost`
 * já dispara isso através do `eagerLoad` configurado no
 * SocialGroupDiscussionResource.
 */
class SocialGroupPostResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Formatter $formatter,
        protected SchemaCapabilities $capabilities,
    ) {
    }

    public function type(): string
    {
        return 'social-group-posts';
    }

    public function model(): string
    {
        return SocialGroupPost::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = RequestUtil::getActor($context->request);

        // Suporte ao Index endpoint: `?filter[discussion]=N` lista os
        // posts de UMA discussão (substituto do antigo /sg-thread-posts).
        // Sem o filtro, o endpoint Index responde vazio — não fazemos
        // listagem global de posts cross-discussion.
        $rawFilter = $context->request->getQueryParams()['filter'] ?? [];
        $filter    = is_array($rawFilter) ? $rawFilter : [];
        $discId    = isset($filter['discussion']) ? (int) $filter['discussion'] : 0;

        if ($discId > 0) {
            $discussion = SocialGroupDiscussion::with('group')->find($discId);
            if ($discussion === null || $discussion->group === null) {
                $query->whereRaw('1 = 0');
                return;
            }
            if (! GroupVisibility::canSee($actor, $discussion->group)) {
                throw new PermissionDeniedException();
            }
            $query->where('social_group_posts.discussion_id', $discId);

            // Pinned no topo, depois cronológico — espelha o controller
            // legado e bate com o índice composto (discussion_id, is_pinned).
            if ($this->capabilities->isPinned) {
                $query->orderByDesc('social_group_posts.is_pinned');
            }
            $query->orderBy('social_group_posts.created_at');

            $query->with('user');
            if ($this->capabilities->reactions) {
                $query->with('reactions');
            }
            return;
        }

        if ($actor->isAdmin()
            || $actor->hasPermission('ernestdefoe-social-groups.moderate')
        ) {
            return;
        }

        /**
         * Sem filtro de discussão (ex.: include de notificação por
         * polimorfismo), restringe a posts cujo grupo o actor pode ver.
         * Espelha GroupVisibility::canSee mas como subquery batch.
         */
        $actorId = $actor->exists ? (int) $actor->id : null;

        $query->whereExists(function ($sub) use ($actorId) {
            $sub->from('social_groups')
                ->whereColumn('social_groups.id', 'social_group_posts.group_id')
                ->where(function ($q) use ($actorId) {
                    $q->where('social_groups.is_private', false);
                    if ($actorId !== null) {
                        $q->orWhere('social_groups.user_id', $actorId)
                          ->orWhereExists(function ($mem) use ($actorId) {
                              $mem->from('social_group_members')
                                  ->whereColumn('social_group_members.group_id', 'social_groups.id')
                                  ->where('social_group_members.user_id', $actorId);
                          });
                    }
                });
        });
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate()
                ->defaultInclude(['user', 'discussion']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('discussionId')
                ->property('discussion_id'),

            Schema\Integer::make('groupId')
                ->property('group_id'),

            Schema\Str::make('content'),

            Schema\Str::make('contentParsed')
                ->get(function (SocialGroupPost $post) {
                    return $this->renderContent($post);
                }),

            Schema\Arr::make('linkPreview')
                ->visible(fn () => $this->capabilities->linkPreview)
                ->get(function (SocialGroupPost $post) {
                    if (! $this->capabilities->linkPreview) {
                        return null;
                    }
                    return $post->link_preview;
                }),

            Schema\Arr::make('reactions')
                ->visible(fn () => $this->capabilities->reactions)
                ->get(function (SocialGroupPost $post) {
                    if (! $this->capabilities->reactions) {
                        return (object) [];
                    }
                    return $this->aggregateReactions($post);
                }),

            Schema\Str::make('actorReaction')
                ->nullable()
                ->visible(fn () => $this->capabilities->reactions)
                ->get(function (SocialGroupPost $post, Context $context) {
                    if (! $this->capabilities->reactions) {
                        return null;
                    }
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return null;
                    }
                    foreach ($post->reactions as $r) {
                        if ((int) $r->user_id === (int) $actor->id) {
                            return $r->reaction;
                        }
                    }
                    return null;
                }),

            Schema\Boolean::make('isPinned')
                ->get(fn (SocialGroupPost $post) => (bool) $post->is_pinned),

            Schema\Integer::make('parentPostId')
                ->property('parent_post_id')
                ->nullable(),

            Schema\Boolean::make('canEdit')
                ->get(function (SocialGroupPost $post, Context $context) {
                    $actor = $context->getActor();
                    return $actor->exists && (int) $actor->id === (int) $post->user_id;
                }),

            Schema\Boolean::make('canDelete')
                ->get(function (SocialGroupPost $post, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    if ((int) $actor->id === (int) $post->user_id) {
                        return true;
                    }
                    if ($actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return true;
                    }
                    return $this->isInGroupModerator($actor, $post->group_id);
                }),

            Schema\Boolean::make('canPin')
                ->get(function (SocialGroupPost $post, Context $context) {
                    if (! $this->capabilities->isPinned) {
                        return false;
                    }
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    if ($actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return true;
                    }
                    return $this->isInGroupModerator($actor, $post->group_id);
                }),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),

            Schema\Relationship\ToOne::make('discussion')
                ->type('social-group-discussions')
                ->includable(),
        ];
    }

    /**
     * "Membro do grupo com papel creator/moderator?" — usado pelos
     * gates canDelete e canPin. Esta checagem replicava 4 vezes no
     * código antigo; centralizando aqui evita o drift que o helper
     * GroupVisibility::canSee resolveu para visibility.
     */
    protected function isInGroupModerator($actor, int $groupId): bool
    {
        if (! $actor->exists || $groupId <= 0) {
            return false;
        }
        return \Ernestdefoe\SocialGroups\Model\SocialGroup::query()
            ->where('id', $groupId)
            ->whereExists(function ($sub) use ($actor) {
                $sub->from('social_group_members')
                    ->whereColumn('social_group_members.group_id', 'social_groups.id')
                    ->where('user_id', $actor->id)
                    ->whereIn('role', ['creator', 'moderator']);
            })
            ->exists();
    }

    /**
     * Renderiza o conteúdo via formatter. Em caso de falha (parseado
     * inconsistente, source malformed após migração), cai para escape
     * + nl2br como no antigo controller — nunca explode a renderização
     * do feed inteiro por causa de UM post problemático.
     */
    protected function renderContent(SocialGroupPost $post): string
    {
        if ($post->content_parsed !== null) {
            try {
                return $this->formatter->render($post->content_parsed);
            } catch (\Throwable) {
                // fall through to escape fallback
            }
        }
        return nl2br(htmlspecialchars($post->content ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Agrupa as reactions pré-carregadas (via with('reactions') no
     * scope do listador) em `{reaction: count}`. Cast para object para
     * que o JSON:API sirva como `{}` em vez de `[]` quando não houver
     * reactions — o frontend espera object.
     */
    protected function aggregateReactions(SocialGroupPost $post): object
    {
        $counts = [];
        foreach ($post->reactions as $r) {
            $key = $r->reaction;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        return (object) $counts;
    }
}
