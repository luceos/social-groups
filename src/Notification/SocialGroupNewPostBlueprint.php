<?php

namespace Ernestdefoe\SocialGroups\Notification;

use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

class SocialGroupNewPostBlueprint implements BlueprintInterface
{
    public function __construct(
        private SocialGroupPost       $post,
        private User                  $actor,
        private SocialGroupDiscussion $discussion
    ) {}

    public function getSender(): User
    {
        return $this->actor;
    }

    public function getSubject(): SocialGroupPost
    {
        return $this->post;
    }

    public function getData(): array
    {
        return [
            'postId'          => $this->post->id,
            'discussionId'    => $this->discussion->id,
            'discussionTitle' => $this->discussion->title ?? '',
            'groupId'         => $this->discussion->group_id,
            'groupSlug'       => $this->discussion->group?->slug ?? '',
        ];
    }

    public static function getType(): string
    {
        return 'socialGroupNewPost';
    }

    public static function getSubjectModel(): string
    {
        return SocialGroupPost::class;
    }
}
