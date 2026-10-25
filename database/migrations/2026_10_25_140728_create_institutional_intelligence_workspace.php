<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_settings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('require_kpi_approval')->default(true);
            $t->timestamps();
        });
        Schema::create('institution_kpis', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('created_by')->constrained('users');
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->string('category', 80);
            $t->string('metric', 80);
            $t->string('source', 16);
            $t->string('unit', 60);
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('target', 16, 4);
            $t->decimal('target_upper', 16, 4)->nullable();
            $t->string('direction', 16);
            $t->string('lifecycle', 16)->default('draft');
            $t->json('filters');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['institution_id', 'lifecycle', 'period_to']);
        });
        Schema::create('kpi_changes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_kpi_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->string('status', 20)->default('pending');
            $t->json('previous');
            $t->json('proposed');
            $t->text('reason');
            $t->text('decision_reason')->nullable();
            $t->unsignedInteger('base_version');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['institution_kpi_id', 'status']);
        });
        Schema::create('kpi_observations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_kpi_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reported_by')->constrained('users');
            $t->decimal('value', 16, 4);
            $t->date('observed_on');
            $t->longText('evidence');
            $t->string('evidence_path')->nullable();
            $t->timestamps();
            $t->index(['institution_kpi_id', 'observed_on']);
        });
        Schema::create('report_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->string('report_type', 32);
            $t->string('frequency', 16);
            $t->string('format', 8);
            $t->json('filters');
            $t->json('recipient_ids');
            $t->timestamp('next_run_at')->index();
            $t->boolean('enabled')->default(true);
            $t->string('last_status', 40)->nullable();
            $t->timestamps();
        });
        Schema::create('report_runs', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('scheduled_for')->nullable();
            $t->string('report_type', 32);
            $t->string('format', 8);
            $t->string('status', 20)->default('pending');
            $t->json('filters');
            $t->json('access_scope');
            $t->json('recipient_ids');
            $t->longText('snapshot')->nullable();
            $t->string('definition_version', 40);
            $t->string('path')->nullable();
            $t->string('file_hash', 64)->nullable();
            $t->string('snapshot_hash', 64)->nullable();
            $t->string('failure_code', 40)->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->unique(['report_schedule_id', 'scheduled_for']);
            $t->index(['institution_id', 'user_id', 'created_at']);
        });
        Schema::create('institution_opportunity_visits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('opportunity_id')->constrained();
            $t->date('visited_on');
            $t->timestamp('created_at');
            $t->unique(['institution_id', 'user_id', 'opportunity_id', 'visited_on'], 'reporting_unique_daily_visit');
        });
        Schema::table('projects', fn (Blueprint $t) => $t->index(['institution_id', 'created_at', 'status'], 'reporting_projects_cohort'));
        Schema::table('ideas', fn (Blueprint $t) => $t->index(['institution_id', 'created_at'], 'reporting_ideas_cohort'));
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropIndex('reporting_projects_cohort'));
        Schema::table('ideas', fn (Blueprint $t) => $t->dropIndex('reporting_ideas_cohort'));
        foreach (['institution_opportunity_visits', 'report_runs', 'report_schedules', 'kpi_observations', 'kpi_changes', 'institution_kpis', 'reporting_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
