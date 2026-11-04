<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Polymorphic relationship to tokenable model (usually User)
            $table->morphs('tokenable');

            // Human-readable token name (e.g., "Mobile App", "CLI Tool")
            $table->string('name');

            // Hashed token value - never store plain text tokens
            $table->string('token', 64)->unique();

            // Optional: Token abilities (permissions/scopes)
            $table->text('abilities')->nullable();

            // Track last usage for security auditing
            $table->timestamp('last_used_at')->nullable();

            // Optional: Token expiration
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('personal_access_tokens');
    }
};
