<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Raw text for "text" sources (pasted by the client).
            $table->text('content')->nullable()->after('title');

            // Pages found through a sitemap point back to that sitemap.
            $table->foreignId('parent_id')->nullable()->after('bot_id')
                ->constrained('sources')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('content');
        });
    }
};
