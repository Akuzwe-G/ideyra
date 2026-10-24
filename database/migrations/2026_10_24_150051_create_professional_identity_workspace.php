<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained();
            $t->string('display_name', 150);
            $t->string('headline', 200)->nullable();
            $t->text('biography')->nullable();
            $t->string('location', 150)->nullable();
            $t->string('professional_email')->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('photo_path')->nullable();
            $t->json('links')->nullable();
            $t->timestamps();
        });
        Schema::create('professional_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $t->string('type', 24);
            $t->string('title', 180);
            $t->string('category', 80)->nullable();
            $t->json('content');
            $t->string('evidence_path')->nullable();
            $t->string('evidence_name')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['professional_profile_id', 'type', 'sort_order'], 'career_entry_order');
        });
        Schema::create('project_showcases', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained();
            $t->text('reflection')->nullable();
            $t->string('technologies', 500)->nullable();
            $t->json('links')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['professional_profile_id', 'project_id'], 'unique_career_showcase');
        });
        Schema::create('achievement_verifications', function (Blueprint $t): void {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('professional_entry_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('project_showcase_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('category', 40);
            $t->string('subject_key', 100);
            $t->string('title', 180);
            $t->string('status', 24)->default('requested');
            $t->longText('evidence');
            $t->longText('approved_content')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->boolean('public_allowed')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'institution_id', 'subject_key', 'category'], 'unique_career_verification');
            $t->index(['institution_id', 'status', 'created_at'], 'career_verification_queue');
        });
        Schema::create('verification_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('achievement_verification_id')->constrained();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('status', 24);
            $t->longText('reason');
            $t->longText('evidence_reviewed');
            $t->timestamps();
        });
        Schema::create('cv_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('title', 180);
            $t->string('template', 24)->default('classic');
            $t->json('sections');
            $t->longText('content');
            $t->timestamps();
            $t->index(['user_id', 'updated_at']);
        });
        Schema::create('cv_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cv_document_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->longText('payload');
            $t->timestamps();
            $t->unique(['cv_document_id', 'version']);
        });
        Schema::create('portfolios', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('professional_profile_id')->unique()->constrained();
            $t->string('slug', 60)->unique();
            $t->string('theme', 24)->default('minimal');
            $t->json('sections');
            $t->json('contact_fields')->nullable();
            $t->json('entry_ids')->nullable();
            $t->json('showcase_ids')->nullable();
            $t->boolean('search_visible')->default(false);
            $t->boolean('published')->default(false);
            $t->longText('payload')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('portfolio_media', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_showcase_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('alt', 250);
            $t->string('mime', 50);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('public_approved')->default(false);
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::create('career_publication_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('portfolio_id')->constrained();
            $t->boolean('published');
            $t->timestamp('created_at')->index();
        });
        Schema::table('opportunity_applications', function (Blueprint $t): void {
            $t->longText('career_selection')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_applications', fn (Blueprint $t) => $t->dropColumn('career_selection'));
        foreach (['career_publication_events', 'portfolio_media', 'portfolios', 'cv_snapshots', 'cv_documents', 'verification_decisions', 'achievement_verifications', 'project_showcases', 'professional_entries', 'professional_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
