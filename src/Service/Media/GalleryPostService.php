<?php

namespace Ernestdefoe\SocialGroups\Service\Media;

use Carbon\Carbon;
use Ernestdefoe\SocialGroups\Model\SocialGroup;
use Ernestdefoe\SocialGroups\Model\SocialGroupDiscussion;
use Ernestdefoe\SocialGroups\Model\SocialGroupPost;
use Flarum\User\User;

class GalleryPostService
{
    /**
     * Create a media post inside the group's hidden gallery discussion,
     * creating that gallery on first use. The find-or-create and the insert
     * run in one transaction with the group row locked so two concurrent
     * uploads can't each spawn a duplicate gallery — MySQL can't express the
     * partial uniqueness (is_gallery = 1) a unique index would need. The
     * connection is taken from the model layer rather than an injected
     * ConnectionInterface, per the extension's data-access conventions.
     */
    public function createPost(SocialGroup $group, User $actor, string $content): SocialGroupPost
    {
        return SocialGroupDiscussion::query()->getConnection()->transaction(function () use ($group, $actor, $content) {
            SocialGroup::query()->whereKey($group->id)->lockForUpdate()->first();

            $discussion = SocialGroupDiscussion::firstOrCreate(
                ['group_id' => $group->id, 'is_gallery' => true],
                [
                    'user_id'        => $actor->id,
                    'title'          => '__gallery__',
                    'is_locked'      => false,
                    'comment_count'  => 0,
                    'last_posted_at' => Carbon::now(),
                ]
            );

            $post = SocialGroupPost::create([
                'discussion_id' => $discussion->id,
                'group_id'      => $group->id,
                'user_id'       => $actor->id,
                'content'       => $content,
            ]);

            $discussion->increment('comment_count');
            $discussion->last_posted_at      = Carbon::now();
            $discussion->last_posted_user_id = $actor->id;
            $discussion->save();

            return $post;
        });
    }
}
