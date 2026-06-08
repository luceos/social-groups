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
        'is_private'  => 'boolean',
        'is_featured' => 'boolean',
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

    /**
     * Membership query scoped to one actor, excluding kicked users.
     * Kick is a soft action that sets `banned_at` without deleting the row,
     * so every write-gating check must filter it out — route them through
     * here rather than re-deriving the `whereNull('banned_at')` filter.
     */
    public function activeMembership(int $userId)
    {
        return $this->members()
            ->where('user_id', $userId)
            ->whereNull('banned_at');
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

        // A purely-numeric slug ("33333" for a group literally named "33333")
        // is indistinguishable from a primary-key id in the URL path. The
        // /api/social-groups/{id-or-slug} resolver previously short-circuited
        // numeric input to a PK lookup and returned 404 for the actual row.
        // SocialGroupResource::find() is now slug-first as a defensive read
        // path, but we ALSO refuse to mint pure-digit slugs at write time so
        // new groups never reproduce that ambiguity. A "g-" prefix keeps the
        // URL readable while guaranteeing at least one non-digit character.
        if (preg_match('/^\d+$/', $slug)) {
            $slug = 'g-' . $slug;
        }

        $base = $slug;

        // Fetch every colliding slug in ONE query (base + "base-N" variants) and
        // pick the first free suffix in PHP, instead of a SELECT per iteration
        // (an unbounded query loop on a popular base name). The base charset is
        // slug-safe (letters/digits/hyphens), so the LIKE pattern needs no extra
        // wildcard escaping.
        $taken = static::where('slug', $base)
            ->orWhere('slug', 'like', $base . '-%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $taken = array_flip($taken);
        $i = 2;
        while (isset($taken[$base . '-' . $i])) {
            $i++;
        }

        return $base . '-' . $i;
    }
}
