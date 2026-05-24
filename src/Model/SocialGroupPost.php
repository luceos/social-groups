<?php

namespace Ernestdefoe\SocialGroups\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Casts\Attribute;

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

    /**
     * Whitelist explícito de mass assignment. Bloqueia que um caller futuro
     * passando `$request->getParsedBody()` direto a `create()/fill()` consiga
     * sobrescrever `is_pinned`, `group_id`, `parent_post_id` etc. com valores
     * vindos do cliente.
     */
    protected $fillable = [
        'discussion_id',
        'group_id',
        'user_id',
        'content',
        'content_parsed',
        'parent_post_id',
        'link_preview',
        'is_pinned',
    ];

    public $timestamps = true;

    /**
     * `link_preview` fica fora do cast `array` porque o cast do Laravel
     * lança `JsonException` em JSON malformado, derrubando a query de feed
     * inteira. Decodificamos defensivamente no accessor.
     */
    protected $casts = [
        'is_pinned' => 'boolean',
    ];

    protected function linkPreview(): Attribute
    {
        return Attribute::make(
            get: static function ($value): ?array {
                if ($value === null || $value === '') return null;
                if (is_array($value)) return $value;
                try {
                    $decoded = json_decode($value, true, 512, \JSON_THROW_ON_ERROR);
                    return is_array($decoded) ? $decoded : null;
                } catch (\JsonException) {
                    return null;
                }
            },
            set: static fn ($value): ?string => $value !== null ? json_encode($value) : null,
        );
    }

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
