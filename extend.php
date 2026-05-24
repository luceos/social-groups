<?php

use Ernestdefoe\SocialGroups\Api\Controller\ApproveJoinRequestController;
use Ernestdefoe\SocialGroups\Api\Controller\FeatureGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupAnalyticsController;
use Ernestdefoe\SocialGroups\Api\Controller\KickGroupMemberController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupRssFeedController;
use Ernestdefoe\SocialGroups\Api\Controller\ListGroupMediaController;
use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Api\Controller\ListGroupMembersController;
use Ernestdefoe\SocialGroups\Api\Controller\DemoteMemberController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\CreateGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\DeleteGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\ListGroupDiscussionsController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\PinGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\ShareGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\InviteUserController;
use Ernestdefoe\SocialGroups\Api\Controller\JoinGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\LeaveGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\ListJoinRequestsController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\CreateGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\DeleteGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\ListGroupPostsController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\PinGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\TogglePostReactionController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\TypingStatusController;
use Ernestdefoe\SocialGroups\Event\SocialGroupPostWasCreated;
use Ernestdefoe\SocialGroups\Listener\BroadcastGroupPost;
use Ernestdefoe\SocialGroups\Api\Controller\Post\UpdateGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\ListUserGroupsController;
use Ernestdefoe\SocialGroups\Api\Controller\SetPrimaryGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\StoreGroupMediaPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Poll\VotePollController;
use Ernestdefoe\SocialGroups\Api\Controller\PromoteMemberController;
use Ernestdefoe\SocialGroups\Api\Controller\RejectJoinRequestController;
use Ernestdefoe\SocialGroups\Api\Controller\UploadGroupImageController;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupDiscussionResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupPostResource;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupResource;
use Ernestdefoe\SocialGroups\Access\SocialGroupDiscussionPolicy;
use Ernestdefoe\SocialGroups\Access\SocialGroupPolicy;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
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
        // Discussions
        ->get('/sg-discussions/{groupId}',           'sg-discussions.list',   ListGroupDiscussionsController::class)
        ->post('/sg-discussions',                    'sg-discussions.create', CreateGroupDiscussionController::class)
        ->delete('/sg-discussions/{discussionId}',   'sg-discussions.delete', DeleteGroupDiscussionController::class)
        ->patch('/sg-discussions/{discussionId}/pin',   'sg-discussions.pin',   PinGroupDiscussionController::class)
        ->post('/sg-discussions/{discussionId}/share',  'sg-discussions.share', ShareGroupDiscussionController::class)
        // Posts
        ->get('/sg-thread-posts/{discussionId}', 'sg-posts.list',   ListGroupPostsController::class)
        ->post('/sg-posts',                      'sg-posts.create', CreateGroupPostController::class)
        ->patch('/sg-posts/{postId}',             'sg-posts.update', UpdateGroupPostController::class)
        ->patch('/sg-posts/{postId}/pin',        'sg-posts.pin',    PinGroupPostController::class)
        ->post('/sg-posts/{postId}/delete',      'sg-posts.delete', DeleteGroupPostController::class)
        ->post('/sg-posts/{postId}/react',   'sg-posts.react',   TogglePostReactionController::class)
        ->post('/sg-posts/{postId}/unreact', 'sg-posts.unreact', TogglePostReactionController::class)
        // Join requests
        ->get('/social-groups/{id}/requests',                        'sg.join-requests.list',    ListJoinRequestsController::class)
        ->post('/social-groups/{id}/requests/{requestId}/approve',   'sg.join-requests.approve', ApproveJoinRequestController::class)
        ->delete('/social-groups/{id}/requests/{requestId}',         'sg.join-requests.reject',  RejectJoinRequestController::class)
        // Member moderation
        ->get('/social-groups/{id}/members',                         'sg.members.list',          ListGroupMembersController::class)
        ->post('/social-groups/{id}/members/{userId}/promote',       'sg.members.promote',       PromoteMemberController::class)
        ->post('/social-groups/{id}/members/{userId}/demote',        'sg.members.demote',        DemoteMemberController::class)
        ->delete('/social-groups/{id}/members/{userId}',             'sg.members.kick',          KickGroupMemberController::class)
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

    (new Extend\Policy())
        ->modelPolicy(SocialGroup::class, SocialGroupPolicy::class)
        ->modelPolicy(SocialGroupDiscussion::class, SocialGroupDiscussionPolicy::class),

    (new Extend\Notification())
        ->type(SocialGroupNewPostBlueprint::class,  ['alert'])
        ->type(SocialGroupNewReplyBlueprint::class, ['alert']),

    (new Extend\Settings())
        ->default('ernestdefoe-social-groups.create_permission', 'member'),

    (new Extend\ServiceProvider())
        ->register(SocialGroupsServiceProvider::class),

];
