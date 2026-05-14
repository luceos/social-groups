<?php

use Ernestdefoe\SocialGroups\Api\Controller\ApproveJoinRequestController;
use Ernestdefoe\SocialGroups\Api\Controller\FeatureGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\GroupRssFeedController;
use Ernestdefoe\SocialGroups\Api\Controller\FetchLinkPreviewController;
use Ernestdefoe\SocialGroups\Api\Controller\ListGroupMembersController;
use Ernestdefoe\SocialGroups\Api\Controller\DemoteMemberController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\CreateGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\DeleteGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\ListGroupDiscussionsController;
use Ernestdefoe\SocialGroups\Api\Controller\Discussion\PinGroupDiscussionController;
use Ernestdefoe\SocialGroups\Api\Controller\InviteUserController;
use Ernestdefoe\SocialGroups\Api\Controller\JoinGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\LeaveGroupController;
use Ernestdefoe\SocialGroups\Api\Controller\ListJoinRequestsController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\CreateGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\DeleteGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\ListGroupPostsController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\TogglePostReactionController;
use Ernestdefoe\SocialGroups\Api\Controller\Post\UpdateGroupPostController;
use Ernestdefoe\SocialGroups\Api\Controller\PromoteMemberController;
use Ernestdefoe\SocialGroups\Api\Controller\RejectJoinRequestController;
use Ernestdefoe\SocialGroups\Api\Controller\UploadGroupImageController;
use Ernestdefoe\SocialGroups\Api\Resource\SocialGroupResource;
use Ernestdefoe\SocialGroups\Api\Serializer\SocialGroupPostSerializer;
use Ernestdefoe\SocialGroups\Access\SocialGroupPolicy;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewPostBlueprint;
use Ernestdefoe\SocialGroups\Notification\SocialGroupNewReplyBlueprint;
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
        ->patch('/social-groups/{id}/feature', 'social-groups.feature',       FeatureGroupController::class)
        ->post('/social-groups/{id}/join',    'social-groups.join',          JoinGroupController::class)
        ->delete('/social-groups/{id}/join',  'social-groups.leave',         LeaveGroupController::class)
        ->post('/social-groups/{id}/image',   'social-groups.upload-image',  UploadGroupImageController::class)
        ->post('/social-groups/{id}/banner',  'social-groups.upload-banner', UploadGroupImageController::class)
        ->post('/social-groups/{id}/invite',  'social-groups.invite',        InviteUserController::class)
        // Link preview proxy
        ->get('/sg-link-preview', 'sg-link-preview', FetchLinkPreviewController::class)
        // Discussions
        ->get('/sg-discussions/{groupId}',           'sg-discussions.list',   ListGroupDiscussionsController::class)
        ->post('/sg-discussions',                    'sg-discussions.create', CreateGroupDiscussionController::class)
        ->delete('/sg-discussions/{discussionId}',   'sg-discussions.delete', DeleteGroupDiscussionController::class)
        ->patch('/sg-discussions/{discussionId}/pin', 'sg-discussions.pin',   PinGroupDiscussionController::class)
        // Posts
        ->get('/sg-posts/{discussionId}',     'sg-posts.list',   ListGroupPostsController::class)
        ->post('/sg-posts',                   'sg-posts.create', CreateGroupPostController::class)
        ->patch('/sg-posts/{postId}',         'sg-posts.update', UpdateGroupPostController::class)
        ->delete('/sg-posts/{postId}',        'sg-posts.delete', DeleteGroupPostController::class)
        ->post('/sg-posts/{postId}/react',    'sg-posts.react',   TogglePostReactionController::class)
        ->delete('/sg-posts/{postId}/react', 'sg-posts.unreact', TogglePostReactionController::class)
        // Join requests
        ->get('/social-groups/{id}/requests',                        'sg.join-requests.list',    ListJoinRequestsController::class)
        ->post('/social-groups/{id}/requests/{requestId}/approve',   'sg.join-requests.approve', ApproveJoinRequestController::class)
        ->delete('/social-groups/{id}/requests/{requestId}',         'sg.join-requests.reject',  RejectJoinRequestController::class)
        // Member moderation
        ->get('/social-groups/{id}/members',                         'sg.members.list',          ListGroupMembersController::class)
        ->post('/social-groups/{id}/members/{userId}/promote',       'sg.members.promote',       PromoteMemberController::class)
        ->post('/social-groups/{id}/members/{userId}/demote',        'sg.members.demote',        DemoteMemberController::class),

    (new Extend\ApiResource(SocialGroupResource::class)),

    (new Extend\Policy())
        ->modelPolicy(SocialGroup::class, SocialGroupPolicy::class),

    (new Extend\Notification())
        ->type(SocialGroupNewPostBlueprint::class,  SocialGroupPostSerializer::class, ['alert'])
        ->type(SocialGroupNewReplyBlueprint::class, SocialGroupPostSerializer::class, ['alert']),

    (new Extend\Settings())
        ->default('ernestdefoe-social-groups.create_permission', 'member'),

];
