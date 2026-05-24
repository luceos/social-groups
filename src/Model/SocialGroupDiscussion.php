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
     * Relação para o "primeiro post" da discussão — o mais antigo por
     * `created_at`. Usa `oneOfMany` do Laravel para que o SocialGroupDiscussionResource
     * possa fazer eager loading via `include=firstPost` sem incorrer em
     * N+1, eliminando a batch query manual feita pelo antigo
     * ListGroupDiscussionsController.
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
}
