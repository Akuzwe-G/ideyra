<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_categories', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('slug', 120)->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('opportunities', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('category_id')->constrained('opportunity_categories');
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approved_by')->nullable()->constrained('users');
            $t->string('title', 180);
            $t->string('summary', 600);
            $t->longText('description');
            $t->string('organiser', 180);
            $t->string('source_attribution', 250);
            $t->string('source_url', 2048)->nullable();
            $t->string('application_method', 16)->default('internal');
            $t->string('external_url', 2048)->nullable();
            $t->text('instructions');
            $t->text('eligibility_text');
            $t->json('eligibility')->nullable();
            $t->string('country', 100)->nullable();
            $t->string('delivery_mode', 16);
            $t->string('venue', 250)->nullable();
            $t->timestamp('opens_at')->nullable();
            $t->timestamp('deadline_at')->nullable();
            $t->date('event_starts_at')->nullable();
            $t->date('event_ends_at')->nullable();
            $t->unsignedInteger('places')->nullable();
            $t->text('award_information')->nullable();
            $t->string('cover_path')->nullable();
            $t->string('visibility', 24);
            $t->string('status', 24)->default('draft');
            $t->boolean('allow_groups')->default(false);
            $t->boolean('notify_publication')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->index(['institution_id', 'status', 'deadline_at']);
            $t->index(['status', 'published_at']);
        });
        Schema::create('opportunity_audiences', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->constrained();
            $t->string('kind', 24);
            $t->unsignedBigInteger('reference_id');
            $t->unique(['opportunity_id', 'kind', 'reference_id'], 'opportunity_audience_unique');
        });
        Schema::create('opportunity_managers', function (Blueprint $t): void {
            $t->foreignId('opportunity_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->primary(['opportunity_id', 'user_id']);
        });
        Schema::create('opportunity_questions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->constrained();
            $t->string('label', 180);
            $t->string('type', 20);
            $t->boolean('required')->default(false);
            $t->json('options')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->unique(['id', 'opportunity_id']);
        });
        Schema::create('opportunity_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('decision', 24);
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('opportunity_applications', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('opportunity_id')->constrained();
            $t->foreignId('applicant_id')->constrained('users');
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('group_id')->nullable()->constrained('project_groups');
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('subject_key', 80);
            $t->string('status', 32)->default('draft');
            $t->text('motivation');
            $t->text('skills')->nullable();
            $t->text('project_summary')->nullable();
            $t->json('participant_ids')->nullable();
            $t->json('participant_snapshot')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->boolean('external_self_reported')->default(false);
            $t->timestamps();
            $t->unique(['opportunity_id', 'subject_key'], 'opportunity_subject_unique');
            $t->unique(['id', 'opportunity_id']);
            $t->index(['institution_id', 'status']);
            $t->index(['applicant_id', 'status']);
        });
        Schema::create('opportunity_responses', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('application_id');
            $t->unsignedBigInteger('opportunity_id');
            $t->unsignedBigInteger('question_id');
            $t->text('answer')->nullable();
            $t->foreign(['application_id', 'opportunity_id'])->references(['id', 'opportunity_id'])->on('opportunity_applications');
            $t->foreign(['question_id', 'opportunity_id'])->references(['id', 'opportunity_id'])->on('opportunity_questions');
            $t->unique(['application_id', 'question_id']);
        });
        Schema::create('opportunity_consents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('opportunity_applications');
            $t->foreignId('user_id')->constrained();
            $t->timestamp('consented_at')->nullable();
            $t->timestamps();
            $t->unique(['application_id', 'user_id']);
        });
        Schema::create('application_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('opportunity_applications');
            $t->unsignedInteger('version');
            $t->longText('payload');
            $t->timestamps();
            $t->unique(['application_id', 'version']);
        });
        Schema::create('application_participants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('opportunity_applications');
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('programme_id')->nullable()->constrained();
            $t->unique(['application_id', 'user_id']);
            $t->index(['institution_id', 'programme_id']);
        });
        Schema::create('application_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('opportunity_applications');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->foreignId('question_id')->nullable()->constrained('opportunity_questions');
            $t->string('path');
            $t->string('name', 180);
            $t->string('mime', 100);
            $t->unsignedBigInteger('bytes');
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
        });
        Schema::create('application_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('application_id')->constrained('opportunity_applications');
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('decision', 32);
            $t->text('feedback');
            $t->text('internal_note')->nullable();
            $t->timestamps();
        });
        Schema::create('opportunity_bookmarks', function (Blueprint $t): void {
            $t->foreignId('opportunity_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->timestamps();
            $t->primary(['opportunity_id', 'user_id']);
        });
        Schema::create('opportunity_notices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->string('event_key', 100);
            $t->timestamps();
            $t->unique(['opportunity_id', 'user_id', 'event_key'], 'opportunity_notice_unique');
        });
        Schema::create('sponsorship_requests', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('requested_by')->constrained('users');
            $t->string('support_type', 40);
            $t->longText('description');
            $t->text('justification');
            $t->decimal('estimated_value', 14, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->text('intended_use');
            $t->date('needed_from')->nullable();
            $t->date('needed_until')->nullable();
            $t->string('status', 32)->default('draft');
            $t->timestamps();
            $t->index(['project_id', 'status']);
        });
        Schema::create('sponsorship_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_request_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('decision', 32);
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('sponsor_contacts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_request_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->text('name');
            $t->text('email')->nullable();
            $t->text('phone')->nullable();
            $t->string('type', 24);
            $t->timestamp('verified_at')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->text('verification_evidence')->nullable();
            $t->timestamps();
            $t->unique(['id', 'sponsorship_request_id']);
        });
        Schema::create('sponsorship_commitments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('sponsorship_request_id');
            $t->unsignedBigInteger('sponsor_contact_id');
            $t->foreignId('created_by')->constrained('users');
            $t->text('offered_support');
            $t->decimal('pledged_value', 14, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('status', 24)->default('proposed');
            $t->foreignId('verified_by')->nullable()->constrained('users');
            $t->timestamp('fulfilled_at')->nullable();
            $t->text('fulfilment_evidence')->nullable();
            $t->timestamps();
            $t->foreign('sponsorship_request_id')->references('id')->on('sponsorship_requests');
            $t->foreign(['sponsor_contact_id', 'sponsorship_request_id'], 'commitment_contact_request_fk')->references(['id', 'sponsorship_request_id'])->on('sponsor_contacts');
        });
        Schema::create('sponsorship_commitment_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sponsorship_commitment_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('status', 24);
            $t->text('reason');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['sponsorship_commitment_events', 'sponsorship_commitments', 'sponsor_contacts', 'sponsorship_decisions', 'sponsorship_requests', 'opportunity_notices', 'opportunity_bookmarks', 'application_reviews', 'application_documents', 'application_participants', 'application_snapshots', 'opportunity_consents', 'opportunity_responses', 'opportunity_applications', 'opportunity_decisions', 'opportunity_questions', 'opportunity_managers', 'opportunity_audiences', 'opportunities', 'opportunity_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
