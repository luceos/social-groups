<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $color
 * @property string|null $image_url
 * @property string|null $banner_url
 * @property bool $is_private
 * @property int $member_count
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class SocialGroup extends AbstractModel
{
    protected $table = 'social_groups';

    public $timestamps = true;

    protected $casts = [
        'is_private' => 'boolean',
    ];

    public function joinRequests()
    {
        return $this->hasMany(SocialGroupJoinRequest::class, 'group_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members()
    {
        return $this->hasMany(SocialGroupMember::class, 'group_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'social_group_members', 'group_id', 'user_id')
            ->withPivot('role', 'joined_at');
    }

    public static function createSlug(string $name): string
    {
        // Transliterate Unicode (Cyrillic, Arabic, CJK, etc.) → Latin → ASCII
        if (function_exists('transliterator_transliterate')) {
            $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
            $ascii = $ascii !== false ? $ascii : $name;
        } else {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $ascii));
        $slug = trim($slug, '-');

        // If the name was entirely non-Latin (e.g. emoji, unsupported script)
        // the slug may be empty after stripping — fall back to a short hash.
        if ($slug === '') {
            $slug = 'group-' . substr(md5($name . uniqid('', true)), 0, 8);
        }

        $base = $slug;
        $i    = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
