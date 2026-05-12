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
        // BGN/PCGN Cyrillic pre-processing: strtr() runs before ICU Any-Latin
        // so that ICU never sees raw Cyrillic and cannot produce wrong output
        // (e.g. ICU maps Я → AA; BGN/PCGN correctly maps Я → Ya).
        static $cyrillicMap = [
            'А' => 'A',  'Б' => 'B',  'В' => 'V',  'Г' => 'G',  'Д' => 'D',
            'Е' => 'Ye', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z',  'И' => 'I',
            'Й' => 'Y',  'К' => 'K',  'Л' => 'L',  'М' => 'M',  'Н' => 'N',
            'О' => 'O',  'П' => 'P',  'Р' => 'R',  'С' => 'S',  'Т' => 'T',
            'У' => 'U',  'Ф' => 'F',  'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y',  'Ь' => '',
            'Э' => 'E',  'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
            'е' => 'ye', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z',  'и' => 'i',
            'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
            'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
            'у' => 'u',  'ф' => 'f',  'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y',  'ь' => '',
            'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
        ];
        $name = strtr($name, $cyrillicMap);

        // Transliterate remaining Unicode (Arabic, CJK, etc.) → Latin → ASCII
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
