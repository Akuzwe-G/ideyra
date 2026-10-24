<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolios', function (Blueprint $t): void {
            $t->boolean('contact_form_enabled')->default(false);
        });
        Schema::create('portfolio_messages', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('portfolio_id')->constrained()->cascadeOnDelete();
            $t->char('submission_key', 64)->unique();
            $t->text('sender_name');
            $t->text('sender_email');
            $t->longText('message');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['portfolio_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_messages');
        Schema::table('portfolios', fn (Blueprint $t) => $t->dropColumn('contact_form_enabled'));
    }
};
