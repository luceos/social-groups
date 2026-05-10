<?php

namespace Ernestdefoe\SocialGroups\Api\Resource;

use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;

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
                ->authorize(fn ($actor, $model) => $actor->id === $model->user_id || $actor->isAdmin()),

            Endpoint\Delete::make()
                ->authenticated()
                ->authorize(fn ($actor, $model) => $actor->id === $model->user_id || $actor->isAdmin()),
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
                ->get(fn ($group) => (bool) $group->is_private),

            Schema\Integer::make('memberCount')
                ->get(fn ($group) => (int) $group->member_count),

            Schema\DateTime::make('createdAt'),

            Schema\Boolean::make('canEdit')
                ->get(function ($group, $request) {
                    $actor = RequestUtil::getActor($request);
                    return $actor->id === $group->user_id || $actor->isAdmin();
                }),

            Schema\Boolean::make('isMember')
                ->get(function ($group, $request) {
                    $actor = RequestUtil::getActor($request);
                    if (! $actor->exists) {
                        return false;
                    }
                    return $group->members()->where('user_id', $actor->id)->exists();
                }),

            Schema\Boolean::make('isCreator')
                ->get(function ($group, $request) {
                    $actor = RequestUtil::getActor($request);
                    return $actor->id === $group->user_id;
                }),

            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
        ];
    }

    public function creating(object $model, Context $context): void
    {
        $actor = $context->getActor();
        $model->user_id = $actor->id;
        $model->slug = SocialGroup::createSlug($context->body()->attribute('name'));
        $model->member_count = 1;
    }

    public function created(object $model, Context $context): void
    {
        // Creator automatically joins as 'creator' role
        $model->members()->create([
            'user_id' => $context->getActor()->id,
            'role'    => 'creator',
            'joined_at' => now(),
        ]);
    }

    public function mutateDataBeforeValidation(Context $context, array $data): array
    {
        if ($context->updating() && isset($data['attributes']['name'])) {
            // Regenerate slug only if name changes and slug wasn't manually set
            if (! isset($data['attributes']['slug'])) {
                $model = $context->model;
                if ($model && $model->name !== $data['attributes']['name']) {
                    $data['attributes']['slug'] = SocialGroup::createSlug($data['attributes']['name']);
                }
            }
        }

        return $data;
    }
}
