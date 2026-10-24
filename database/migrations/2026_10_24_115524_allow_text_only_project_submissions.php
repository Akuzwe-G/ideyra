<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->text('body')->nullable()->change();
            $table->string('file_path')->nullable()->change();
            $table->string('file_name', 180)->nullable()->change();
            $table->string('mime', 100)->nullable()->change();
            $table->unsignedBigInteger('bytes')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Text-only submission history must remain valid on rollback.
    }
};
