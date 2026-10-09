<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a Page is made of: its Blocks, as the editor's Builder writes them,
     * what it says to search engines, and — for a System Page only — the job
     * it does. The role is unique, so there is never a second Home.
     */
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('blocks')->nullable()->after('status');
            $table->string('system_role')->nullable()->unique()->after('blocks');
            $table->string('seo_title')->nullable()->after('system_role');
            $table->text('seo_description')->nullable()->after('seo_title');
            $table->string('seo_image')->nullable()->after('seo_description');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropUnique(['system_role']);
            $table->dropColumn(['blocks', 'system_role', 'seo_title', 'seo_description', 'seo_image']);
        });
    }
};
