<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::ensureVectorExtensionExists();

        Schema::create('bots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('public_key')->unique(); // goes into the widget script
            $table->string('goal')->default('booking'); // support | booking | lead_capture
            $table->text('system_prompt')->nullable();
            $table->text('greeting')->nullable();
            $table->string('primary_color')->default('#2563eb');
            $table->string('position')->default('right');
            $table->jsonb('allowed_domains')->default('[]');
            $table->string('model')->nullable(); // NULL = default from config
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // url | sitemap | qa | text
            $table->text('url')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->default('pending'); // pending | processing | ready | failed
            $table->text('error')->nullable();
            $table->string('content_hash')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'status']);
        });

        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->vector('embedding', 1536);
            $table->unsignedInteger('token_count')->nullable();
            $table->jsonb('metadata')->default('{}');

            $table->index('bot_id');
            $table->vectorIndex('embedding');
        });

        Schema::create('qa_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id');
            $table->text('page_url')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user | assistant | tool
            $table->text('content');
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('calcom');
            $table->string('cal_username');
            $table->string('event_type_slug');
            $table->text('api_key')->nullable(); // encrypted by the model cast
            $table->string('timezone')->default('UTC');
            $table->timestamps();

            $table->unique('bot_id');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_booking_uid')->nullable();
            $table->timestamp('starts_at');
            $table->string('status')->default('confirmed'); // confirmed | cancelled | rescheduled
            $table->jsonb('raw')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('client_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 4)->default(0);

            $table->unique(['client_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach ([
            'usage_daily', 'bookings', 'calendar_connections', 'leads', 'messages',
            'conversations', 'qa_pairs', 'chunks', 'sources', 'bots',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
