<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int    $post_id
 * @property int    $user_id
 * @property \Carbon\Carbon $created_at
 */
class SocialGroupPostLike extends AbstractModel
{
    protected $table = 'social_group_post_likes';

    public $timestamps = false;

    protected $guarded = [];

    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;
}
