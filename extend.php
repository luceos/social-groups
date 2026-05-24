<?php

use Ernestdefoe\SocialGroups\Api\Controller\ApproveJoinRequestController;
use Ernestdefoe\SocialGroups\Api\Controller\FeatureGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupAnalyticsController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupRssFeedController;
use Ernestdefoe\SocialGroups\Api\Controller\ListGroupMediaController;
use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Api\Controller\InviteUserController;
use Ernestdefoe\SocialGroups\Api\Controller\JoinGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\LeaveGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\ListJoinRequestsController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\TypingStatusController;
use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Ernestdefoe\SocialGroups\Listener\BroadcastGroupPost;
use Ernestdefoe\SocialGroups\Api\Controller\ListUserGroupsController;
use Ernestdefoe\SocialGroups\Api\Controller\SetPrimaryGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\StoreGroupMediaPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Poll\VotePollController;
use Ernestdefoe\SocialGroups\Api\Controller\RejectJoinRequestController;
use Ernestdefoe\SocialGroups\Api\Controller\UploadGroupImageController;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupDiscussionResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupMemberResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupPostResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupResource;
use Ernestdefoe\SocialGroups\Access\SocialGroupDiscussionPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupMemberPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupPostPolicy;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupMember;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewPostBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewReplyBlueprint;
use Ernestdefoe\SocialGroups\SocialGroupsServiceProvider;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less')
        ->route('/groups', 'ernestdefoe-social-groups.index')
        ->route('/groups/{slug}', 'ernestdefoe-social-groups.show')
        ->route('/groups/{slug}/d/{discussionId}', 'ernestdefoe-social-groups.discussion')
        ->content(function (Document $document, ServerRequestInterface $request) {
            $actor = RequestUtil::getActor($request);
            $document->payload['canCreateSocialGroup'] = $actor->can('ernestdefoe-social-groups.create');
        }),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/less/admin.less'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Routes('forum'))
        ->get('/groups/{slug}/feed.rss', 'ernestdefoe-social-groups.rss', GroupRssFeedController::class),

    (new Extend\Routes('api'))
        // Groups
        ->patch('/social-groups/{id}/feature',    'social-groups.feature',    FeatureGroupController::class)
        ->get('/sg-analytics/{groupId}',         'social-groups.analytics',  GroupAnalyticsController::class)
        ->post('/social-groups/{id}/join',    'social-groups.join',  JoinGroupController::class)
        ->post('/social-groups/{id}/leave',   'social-groups.leave', LeaveGroupController::class)
        ->post('/social-groups/{id}/image',   'social-groups.upload-image',  UploadGroupImageController::class)
        ->post('/social-groups/{id}/banner',  'social-groups.upload-banner', UploadGroupImageController::class)
        ->post('/social-groups/{id}/invite',  'social-groups.invite',        InviteUserController::class)
        // Link preview proxy
        ->get('/sg-link-preview', 'sg-link-preview', FetchLinkPreviewController::class)
        ->post('/sg-primary-group',     'sg.primary-group.set', SetPrimaryGroupController::class)
        ->get('/sg-media/{groupId}',   'sg-media.list',   ListGroupMediaController::class)
        ->post('/sg-media-post/{groupId}', 'sg-media-post.store', StoreGroupMediaPostController::class)
        // Discussions and Posts are fully served by their Resources:
        //   /api/social-group-discussions (Index/Show/Create/Delete + pin/share actions)
        //   /api/social-group-posts       (Index/Show/Create/Update/Delete + pin/react/unreact actions)
        // Join requests
        ->get('/social-groups/{id}/requests',                        'sg.join-requests.list',    ListJoinRequestsController::class)
        ->post('/social-groups/{id}/requests/{requestId}/approve',   'sg.join-requests.approve', ApproveJoinRequestController::class)
        ->delete('/social-groups/{id}/requests/{requestId}',         'sg.join-requests.reject',  RejectJoinRequestController::class)
        // Member moderation: served by SocialGroupMemberResource at /api/social-group-members
        //   GET    ?filter[group]=N     list
        //   DELETE /{id}                kick (soft-delete via banned_at)
        //   POST   /{id}/promote        promote
        //   POST   /{id}/demote         demote
        // User group badges
        ->get('/sg-user-groups/{userId}',   'sg.user-groups',   ListUserGroupsController::class)
        // Polls
        ->post('/sg-polls/{pollId}/vote',   'sg.polls.vote',    VotePollController::class)
        // Realtime — typing indicator (no-op if flarum/realtime is not installed)
        ->post('/sg-typing',                'sg.typing',        TypingStatusController::class),

    (new Extend\Event())
        ->listen(SocialGroupPostWasCreated::class, BroadcastGroupPost::class),

    (new Extend\ApiResource(SocialGroupResource::class)),
    (new Extend\ApiResource(SocialGroupPostResource::class)),
    (new Extend\ApiResource(SocialGroupDiscussionResource::class)),
    (new Extend\ApiResource(SocialGroupMemberResource::class)),

    (new Extend\Policy())
        ->modelPolicy(SocialGroup::class, SocialGroupPolicy::class)
        ->modelPolicy(SocialGroupDiscussion::class, SocialGroupDiscussionPolicy::class)
        ->modelPolicy(SocialGroupPost::class, SocialGroupPostPolicy::class)
        ->modelPolicy(SocialGroupMember::class, SocialGroupMemberPolicy::class),

    (new Extend\Notification())
        ->type(SocialGroupNewPostBlueprint::class,  ['alert'])
        ->type(SocialGroupNewReplyBlueprint::class, ['alert']),

    (new Extend\Settings())
        ->default('ernestdefoe-social-groups.create_permission', 'member'),

    (new Extend\ServiceProvider())
        ->register(SocialGroupsServiceProvider::class),

];
