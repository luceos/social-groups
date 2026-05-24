<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Schema\SchemaCapabilities;
use Flarum\Api\Context;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Formatter\Formatter;
use Flarum\Http\RequestUtil;
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

        if ($actor->isAdmin()
            || $actor->hasPermission('ernestdefoe-social-groups.moderate')
        ) {
            return;
        }

        /**
         * Restringe leituras a posts em grupos que o actor pode ver —
         * grupos públicos, grupos criados pelo actor, ou grupos dos
         * quais é membro. Espelha a regra de GroupVisibility::canSee
         * mas em SQL puro para que o subquery possa filtrar batch.
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
        return [];
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

            Schema\DateTime::make('createdAt')
                ->property('created_at'),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
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
