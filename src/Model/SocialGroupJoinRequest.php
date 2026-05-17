<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int $id
 * @property int $group_id
 * @property int $user_id
 * @property string $status  pending | approved | rejected
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class SocialGroupJoinRequest extends AbstractModel
{
    protected $table = 'social_group_join_requests';

    protected $guarded = [];

    /**
     * Without $timestamps=true (Flarum's AbstractModel defaults vary by row,
     * and Laravel's auto-cast for created_at/updated_at only kicks in when
     * timestamps are explicitly on for the model) Eloquent returns
     * created_at as a raw string from the DB. ListJoinRequestsController
     * then 500'd when it called ->toIso8601String() on a string.
     */
    public $timestamps = true;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(SocialGroup::class, 'group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
