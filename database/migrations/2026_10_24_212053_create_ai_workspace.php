<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $t): void {
            $t->id();
            $t->string('scope')->unique();
            $t->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $t->json('settings');
            $t->timestamps();
        });
        Schema::create('ai_conversations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('institution_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('feature', 32);
            $t->unsignedBigInteger('related_id')->nullable();
            $t->longText('messages');
            $t->longText('insights')->nullable();
            $t->timestamp('consented_at');
            $t->string('consent_version')->default('2026-10-v1');
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->index(['user_id', 'feature', 'updated_at']);
        });
        Schema::create('ai_usages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('institution_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $t->string('feature', 32);
            $t->string('provider', 80);
            $t->string('model', 120);
            $t->string('status', 32);
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->decimal('estimated_cost', 12, 6)->nullable();
            $t->timestamps();
            $t->index(['created_at', 'user_id', 'institution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_settings');
    }
};
