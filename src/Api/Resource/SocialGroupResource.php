<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

class SocialGroupResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'social-groups';
    }

    public function model(): string
    {
        return SocialGroup::class;
    }

    public function find(string $id, BaseContext $context): ?object
    {
        // Allow slug-based lookup: if the "id" is non-numeric treat it as a slug
        if (! is_numeric($id)) {
            return SocialGroup::where('slug', $id)->first();
        }

        return parent::find($id, $context);
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        $params = $context->request->getQueryParams();

        // Search is handled client-side in GroupsPage.js.
        // filter[*] params on AbstractDatabaseResource trigger Flarum 2's
        // searcher system (AbstractSearcher) which throws if not implemented.
        $query->orderByDesc('member_count');
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->paginate(),

            Endpoint\Show::make(),

            Endpoint\Create::make()
                ->authenticated()
                ->can('ernestdefoe-social-groups.create'),

            Endpoint\Update::make()
                ->authenticated()
                ->can('edit'),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(100),

            Schema\Str::make('slug')
                ->nullable()
                ->get(fn ($group) => $group->slug),

            Schema\Str::make('description')
                ->nullable()
                ->writable()
                ->maxLength(2000),

            Schema\Str::make('color')
                ->nullable()
                ->writable(),

            Schema\Str::make('imageUrl')
                ->nullable()
                ->get(fn ($group) => $group->image_url),

            Schema\Str::make('bannerUrl')
                ->nullable()
                ->get(fn ($group) => $group->banner_url),

            Schema\Boolean::make('isPrivate')
                ->writable()
                ->default(false)
                ->get(fn ($group) => (bool) $group->is_private)
                ->set(fn ($model, $value) => $model->is_private = (bool) $value),

            Schema\Integer::make('memberCount')
                ->get(fn ($group) => (int) $group->member_count),

            Schema\DateTime::make('createdAt'),

            Schema\Boolean::make('canEdit')
                ->get(function ($group, Context $context) {
                    $actor = $context->getActor();
                    return $actor->id === $group->user_id
                        || $actor->isAdmin()
                        || $actor->hasPermission('ernestdefoe-social-groups.moderate');
                }),

            Schema\Boolean::make('isMember')
                ->get(function ($group, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    return $group->members()->where('user_id', $actor->id)->exists();
                }),

            Schema\Boolean::make('isCreator')
                ->get(function ($group, Context $context) {
                    $actor = $context->getActor();
                    return $actor->id === $group->user_id;
                }),

            Schema\Str::make('membershipType')
                ->get(fn ($g) => $g->membership_type ?? 'open')
                ->set(fn ($model, $value) => $model->membership_type = $value)
                ->writable()
                ->nullable(),

            Schema\Boolean::make('isPending')
                ->get(function ($g, Context $context) {
                    $actor = $context->getActor();
                    if (! $actor->exists) {
                        return false;
                    }
                    return $g->joinRequests()->where('user_id', $actor->id)->where('status', 'pending')->exists();
                }),

            Schema\Integer::make('pendingRequestCount')
                ->get(function ($g, Context $context) {
                    $actor = $context->getActor();
                    if ($actor->id !== $g->user_id
                        && ! $actor->isAdmin()
                        && ! $actor->hasPermission('ernestdefoe-social-groups.moderate')
                    ) {
                        return 0;
                    }
                    return $g->joinRequests()->where('status', 'pending')->count();
                }),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        $actor = $context->getActor();
        $model->user_id = $actor->id;
        // creating() runs before field setters, so read name from the raw body
        $body = $context->request->getParsedBody();
        $name = $body['data']['attributes']['name'] ?? '';
        $model->slug = SocialGroup::createSlug($name);
        $model->member_count = 1;
        return null;
    }

    public function created(object $model, BaseContext $context): ?object
    {
        /** @var Context $context */
        // Creator automatically joins as 'creator' role
        $model->members()->create([
            'user_id' => $context->getActor()->id,
            'role'    => 'creator',
            'joined_at' => \Carbon\Carbon::now(),
        ]);
        return null;
    }

    public function updating(object $model, BaseContext $context): ?object
    {
        // updating() also runs before field setters, so read name from request body
        $body = $context->request->getParsedBody();
        $newName = $body['data']['attributes']['name'] ?? null;
        if ($newName !== null && $newName !== $model->name) {
            $model->slug = SocialGroup::createSlug($newName);
        }
        return null;
    }

}
