<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

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

    public function lastPostedUser()
    {
        return $this->belongsTo(User::class, 'last_posted_user_id');
    }
}
