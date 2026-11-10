<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active'); // active | suspended
            $table->unsignedInteger('monthly_message_limit')->default(2000);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // NULL client_id = super admin.
            $table->foreignUuid('client_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner'); // super_admin | owner | member
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('role');
        });

        Schema::dropIfExists('clients');
    }
};
