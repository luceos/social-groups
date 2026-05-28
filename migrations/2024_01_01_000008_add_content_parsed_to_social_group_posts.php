<?php

use Flarum\Database\Migration;

return Migration::addColumns('social_group_posts', [
    'content_parsed' => ['mediumText', 'nullable' => true, 'after' => 'content'],
]);
