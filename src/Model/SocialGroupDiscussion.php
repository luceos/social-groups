<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Ernestdefoe\SocialGroups\Model\SgPoll;

/**
 * @property int         $id
 * @property int         $group_id
 * @property int         $user_id
 * @property string      $title
 * @property int         $comment_count
 * @property int|null    $last_posted_user_id
 * @property \Carbon\Carbon|null $last_posted_at
 * @property bool        $is_locked
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class SocialGroupDiscussion extends AbstractModel
{
    protected $table = 'social_group_discussions';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = [
        'is_locked'      => 'boolean',
        'is_pinned'      => 'boolean',
        'is_gallery'     => 'boolean',
        'last_posted_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function posts()
    {
        return $this->hasMany(SocialGroupPost::class, 'discussion_id');
    }

    /**
     * Relation for the discussion's "first post" — the oldest by
     * `created_at`. Uses Laravel's `oneOfMany` so that
     * SocialGroupDiscussionResource can eager-load via
     * `include=firstPost` without incurring N+1.
     */
    public function firstPost()
    {
        return $this->hasOne(SocialGroupPost::class, 'discussion_id')
            ->oldestOfMany('created_at');
    }

    public function lastPostedUser()
    {
        return $this->belongsTo(User::class, 'last_posted_user_id');
    }

    public function sharedFromDiscussion()
    {
        return $this->belongsTo(self::class, 'shared_from_discussion_id');
    }

    /**
     * Poll associated with the discussion (1:1). Present when
     * `sg_polls` is installed; SchemaCapabilities filters the call
     * sites.
     */
    public function poll()
    {
        return $this->hasOne(SgPoll::class, 'discussion_id');
    }
}
