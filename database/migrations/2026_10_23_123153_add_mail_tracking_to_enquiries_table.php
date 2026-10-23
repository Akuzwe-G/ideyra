<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->string('mail_status', 24)->default('not_attempted');
            $table->text('mail_recipient')->nullable();
            $table->string('mail_mailer', 80)->nullable();
            $table->timestamp('mail_attempted_at')->nullable();
            $table->timestamp('mail_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropColumn(['mail_status', 'mail_recipient', 'mail_mailer', 'mail_attempted_at', 'mail_accepted_at']);
        });
    }
};
