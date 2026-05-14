<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int    $post_id
 * @property int    $user_id
 * @property string $reaction
 */
class SocialGroupPostReaction extends AbstractModel
{
    protected $table = 'social_group_post_reactions';

    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;
}
