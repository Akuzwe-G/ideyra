<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_settings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->unique()->constrained();
            $t->boolean('allow_network')->default(false);
            $t->boolean('allow_public')->default(false);
            $t->boolean('require_collaboration_approval')->default(true);
            $t->timestamps();
        });
        Schema::create('network_publications', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('project_id')->unique()->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('submitted_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('status', 24)->default('draft');
            $t->string('visibility', 16)->default('private');
            $t->string('title', 180);
            $t->string('summary', 600);
            $t->string('category', 100);
            $t->string('project_status', 32);
            $t->json('draft');
            $t->longText('approved_snapshot')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedInteger('approved_version')->nullable();
            $t->boolean('collaboration_open')->default(false);
            $t->boolean('institution_attribution')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'visibility', 'published_at'], 'network_publication_discovery');
            $t->index(['institution_id', 'status'], 'network_publication_institution');
        });
        Schema::create('publication_media', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('network_publication_id')->constrained()->cascadeOnDelete();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->string('path');
            $t->string('alt', 180);
            $t->string('mime', 60);
            $t->unsignedInteger('bytes');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('approved')->default(false);
            $t->timestamps();
        });
        Schema::create('publication_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('network_publication_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->unsignedInteger('version');
            $t->timestamp('consented_at')->nullable();
            $t->timestamps();
            $t->unique(['network_publication_id', 'user_id'], 'publication_person_consent');
        });
        Schema::create('publication_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('network_publication_id')->constrained()->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('status', 24);
            $t->text('reason');
            $t->unsignedInteger('version');
            $t->longText('details')->nullable();
            $t->timestamps();
        });
        Schema::create('collaboration_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('network_publication_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('representative_id')->constrained('users');
            $t->foreignId('supporting_project_id')->nullable()->constrained('projects');
            $t->string('type', 24);
            $t->longText('motivation');
            $t->longText('skills');
            $t->longText('contribution');
            $t->longText('supporting_snapshot')->nullable();
            $t->string('status', 24)->default('draft');
            $t->string('active_key', 64)->nullable()->unique();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('representative_accepted_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['institution_id', 'status']);
            $t->index(['network_publication_id', 'status'], 'collab_request_target');
        });
        Schema::create('collaboration_approvals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collaboration_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('status', 16)->default('pending');
            $t->text('reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['collaboration_request_id', 'institution_id'], 'collab_institution_approval');
        });
        Schema::create('collaboration_workspaces', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('collaboration_request_id')->unique()->constrained();
            $t->foreignId('network_publication_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->string('title', 180);
            $t->longText('purpose');
            $t->json('institution_ids');
            $t->date('starts_at');
            $t->date('ends_at')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->index(['institution_id', 'status']);
        });
        Schema::create('collaboration_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collaboration_workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('invited_by')->constrained('users');
            $t->string('role', 16)->default('member');
            $t->string('status', 16)->default('invited');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['collaboration_workspace_id', 'user_id'], 'collab_workspace_member');
            $t->index(['user_id', 'status']);
        });
        Schema::create('collaboration_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collaboration_workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('parent_id')->nullable()->constrained('collaboration_entries');
            $t->foreignId('assigned_to')->nullable()->constrained('users');
            $t->string('kind', 16);
            $t->string('title', 180);
            $t->longText('body');
            $t->string('status', 16)->default('open');
            $t->date('due_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->index(['collaboration_workspace_id', 'kind'], 'collab_entry_kind');
        });
        Schema::create('collaboration_files', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('collaboration_workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('collaboration_entry_id')->nullable()->constrained();
            $t->string('path');
            $t->string('name', 180);
            $t->string('mime', 80);
            $t->unsignedInteger('bytes');
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('opportunities', function (Blueprint $t): void {
            $t->string('network_type', 16)->nullable()->index();
        });
        Schema::table('opportunity_applications', function (Blueprint $t): void {
            $t->foreignId('collaboration_workspace_id')->nullable()->constrained();
        });
        Schema::create('innovation_challenges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->unique()->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('kind', 40);
            $t->string('visibility', 16)->default('institution');
            $t->string('status', 24)->default('draft');
            $t->text('rules');
            $t->boolean('allow_cross_institution')->default(false);
            $t->timestamp('event_at')->nullable();
            $t->longText('results_snapshot')->nullable();
            $t->string('results_state', 16)->default('preliminary');
            $t->timestamp('results_published_at')->nullable();
            $t->timestamps();
            $t->index(['institution_id', 'status']);
        });
        Schema::create('challenge_criteria', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('innovation_challenge_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->decimal('weight', 6, 2);
            $t->timestamps();
        });
        Schema::create('challenge_judges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('innovation_challenge_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('assigned_by')->constrained('users');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['innovation_challenge_id', 'user_id'], 'challenge_assigned_judge');
        });
        Schema::create('challenge_submissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('innovation_challenge_id')->constrained();
            $t->foreignId('opportunity_application_id')->unique()->constrained();
            $t->foreignId('project_id')->constrained();
            $t->longText('snapshot');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('submitted_at');
            $t->string('status', 16)->default('submitted');
            $t->timestamps();
        });
        Schema::create('challenge_submission_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('challenge_submission_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->longText('snapshot');
            $t->foreignId('submitted_by')->constrained('users');
            $t->timestamps();
            $t->unique(['challenge_submission_id', 'version'], 'challenge_submission_version');
        });
        Schema::create('challenge_evaluations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('challenge_submission_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->longText('scores');
            $t->longText('feedback');
            $t->decimal('total', 8, 4);
            $t->string('status', 16)->default('draft');
            $t->timestamp('finalised_at')->nullable();
            $t->timestamps();
            $t->unique(['challenge_submission_id', 'user_id'], 'challenge_judge_evaluation');
        });
        Schema::create('challenge_conflicts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('challenge_submission_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->longText('reason');
            $t->timestamps();
            $t->unique(['challenge_submission_id', 'user_id'], 'challenge_judge_conflict');
        });
        Schema::create('innovation_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->unique()->constrained();
            $t->foreignId('innovation_challenge_id')->nullable()->unique()->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('title', 180);
            $t->text('description');
            $t->string('kind', 40);
            $t->string('organiser', 180);
            $t->string('venue', 250)->nullable();
            $t->string('online_url', 2048)->nullable();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('timezone', 80);
            $t->unsignedInteger('capacity')->nullable();
            $t->text('registration_rules');
            $t->json('agenda');
            $t->string('visibility', 16)->default('institution');
            $t->string('status', 16)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->index(['institution_id', 'starts_at']);
        });
        Schema::create('event_registrations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('innovation_event_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('opportunity_application_id')->nullable()->constrained();
            $t->string('status', 16)->default('registered');
            $t->timestamp('registered_at');
            $t->timestamp('attended_at')->nullable();
            $t->foreignId('attendance_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['innovation_event_id', 'user_id'], 'event_unique_registration');
        });
        Schema::create('exhibition_projects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('innovation_event_id')->constrained();
            $t->foreignId('network_publication_id')->constrained();
            $t->foreignId('representative_id')->constrained('users');
            $t->string('category', 100);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamp('presentation_at')->nullable();
            $t->string('demo_url', 2048)->nullable();
            $t->timestamps();
            $t->unique(['innovation_event_id', 'network_publication_id'], 'event_featured_project');
        });
        Schema::create('content_reports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->string('target_type', 32);
            $t->unsignedBigInteger('target_id');
            $t->longText('reason');
            $t->string('status', 16)->default('open');
            $t->foreignId('decided_by')->nullable()->constrained('users');
            $t->longText('decision_reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['institution_id', 'status']);
        });
        Schema::create('network_blocks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamp('expires_at');
            $t->text('reason');
            $t->timestamps();
            $t->index(['user_id', 'institution_id']);
        });
        Schema::create('network_recognitions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('confirmed_by')->constrained('users');
            $t->string('source_type', 24);
            $t->unsignedBigInteger('source_id');
            $t->string('title', 180);
            $t->longText('evidence');
            $t->foreignId('professional_entry_id')->nullable()->constrained();
            $t->foreignId('achievement_verification_id')->nullable()->constrained();
            $t->string('status', 16)->default('confirmed');
            $t->timestamp('confirmed_at');
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'user_id'], 'network_source_recognition');
        });
        Schema::create('network_actions', function (Blueprint $t): void {
            $t->id();
            $t->string('subject_type', 40);
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('action', 60);
            $t->longText('details')->nullable();
            $t->timestamps();
            $t->index(['subject_type', 'subject_id']);
        });
        Schema::create('network_notices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('event_key', 64);
            $t->timestamps();
            $t->unique(['user_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_applications', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('collaboration_workspace_id');
        });
        Schema::table('opportunities', function (Blueprint $t): void {
            $t->dropColumn('network_type');
        });
        foreach (['network_notices', 'network_actions', 'network_recognitions', 'network_blocks', 'content_reports', 'exhibition_projects', 'event_registrations', 'innovation_events', 'challenge_conflicts', 'challenge_evaluations', 'challenge_submission_versions', 'challenge_submissions', 'challenge_judges', 'challenge_criteria', 'innovation_challenges', 'collaboration_files', 'collaboration_entries', 'collaboration_members', 'collaboration_workspaces', 'collaboration_approvals', 'collaboration_requests', 'publication_decisions', 'publication_consents', 'publication_media', 'network_publications', 'network_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
