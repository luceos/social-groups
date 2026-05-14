<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int    $id
 * @property int    $discussion_id
 * @property int    $group_id
 * @property int    $user_id
 * @property string      $content
 * @property string|null $content_parsed
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class SocialGroupPost extends AbstractModel
{
    protected $table = 'social_group_posts';

    protected $guarded = [];

    public $timestamps = true;

    public function discussion()
    {
        return $this->belongsTo(SocialGroupDiscussion::class, 'discussion_id');
    }

    public function group()
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reactions()
    {
        return $this->hasMany(SocialGroupPostReaction::class, 'post_id');
    }
}
