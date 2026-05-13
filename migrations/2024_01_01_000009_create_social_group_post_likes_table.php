<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        $schema = $this->getSchemaBuilder();

        if (! $schema->hasTable('social_group_post_likes')) {
            $schema->create('social_group_post_likes', function (Blueprint $table) {
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('created_at')->useCurrent();

                $table->primary(['post_id', 'user_id']);
                $table->index('post_id');
            });
        }
    }

    public function down(): void
    {
        $this->getSchemaBuilder()->dropIfExists('social_group_post_likes');
    }
};
