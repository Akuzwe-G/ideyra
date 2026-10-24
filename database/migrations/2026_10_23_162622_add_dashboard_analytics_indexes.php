<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['join_applications', 'institutions', 'users', 'audit_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->index('created_at', $name.'_analytics_created'));
        }
        Schema::table('memberships', fn (Blueprint $table) => $table->index(['institution_id', 'created_at'], 'memberships_analytics_cohort'));
        Schema::table('audit_entries', fn (Blueprint $table) => $table->index(['institution_id', 'created_at'], 'audit_analytics_context'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['join_applications', 'institutions', 'users', 'audit_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropIndex($name.'_analytics_created'));
        }
        Schema::table('memberships', fn (Blueprint $table) => $table->dropIndex('memberships_analytics_cohort'));
        Schema::table('audit_entries', fn (Blueprint $table) => $table->dropIndex('audit_analytics_context'));
    }
};
