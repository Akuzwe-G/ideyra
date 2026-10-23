<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 24)->default('active')->index();
            $table->string('phone', 40)->nullable();
        });
        Schema::create('join_applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('reference', 40)->unique();
            $table->string('type', 24);
            $table->string('country', 100)->index();
            $table->longText('payload');
            $table->json('search_tokens');
            $table->json('institution_tokens');
            $table->string('submission_hash', 64)->unique();
            $table->string('duplicate_hash', 64)->unique();
            $table->string('status', 40)->default('submitted')->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->longText('review_notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('mail_status', 24)->default('not_attempted');
            $table->timestamp('mail_attempted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('identifier')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type', 80);
            $table->string('country', 100);
            $table->string('region', 150);
            $table->string('city', 150);
            $table->text('address')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_email');
            $table->string('contact_phone', 40)->nullable();
            $table->string('logo_path')->nullable();
            $table->unsignedInteger('student_population')->default(0);
            $table->string('status', 32)->default('draft')->index();
            $table->json('locked_fields')->nullable();
            $table->foreignId('application_id')->nullable()->unique()->constrained('join_applications');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('group');
            $table->string('scope', 24);
            $table->boolean('implemented')->default(false);
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('scope', 24);
            $table->json('permissions');
            $table->timestamps();
        });
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained();
            $table->foreignId('role_id')->constrained();
            $table->primary(['user_id', 'role_id']);
        });
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('role_id')->constrained();
            $table->string('status', 24)->default('pending');
            $table->string('identifier', 100)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['institution_id', 'user_id']);
            $table->unique(['institution_id', 'identifier']);
            $table->unique(['id', 'institution_id']);
        });
        Schema::create('membership_permissions', function (Blueprint $table): void {
            $table->foreignId('membership_id')->constrained();
            $table->foreignId('permission_id')->constrained();
            $table->boolean('allowed');
            $table->primary(['membership_id', 'permission_id']);
        });
        Schema::create('identity_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('membership_id')->constrained();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 24);
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->string('mail_status', 24)->default('not_attempted');
            $table->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->string('name');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
            $table->unique(['id', 'institution_id']);
        });
        Schema::create('programmes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->string('name');
            $table->string('code', 40);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->unique(['id', 'institution_id']);
        });
        Schema::create('intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
            $table->unique(['id', 'institution_id']);
            $table->foreign(['academic_year_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_years');
        });
        Schema::create('academic_classes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained();
            $table->unsignedBigInteger('programme_id');
            $table->unsignedBigInteger('intake_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('name');
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['institution_id', 'name']);
            $table->unique(['id', 'institution_id']);
            $table->foreign(['programme_id', 'institution_id'])->references(['id', 'institution_id'])->on('programmes');
            $table->foreign(['intake_id', 'institution_id'])->references(['id', 'institution_id'])->on('intakes');
            $table->foreign(['academic_year_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_years');
        });
        Schema::create('class_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('institution_id');
            $table->unsignedBigInteger('academic_class_id');
            $table->unsignedBigInteger('membership_id');
            $table->string('assignment', 32);
            $table->timestamps();
            $table->unique(['academic_class_id', 'membership_id']);
            $table->foreign(['academic_class_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_classes');
            $table->foreign(['membership_id', 'institution_id'])->references(['id', 'institution_id'])->on('memberships');
        });
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->foreignId('institution_id')->nullable()->constrained();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->longText('metadata');
            $table->timestamp('created_at');
        });
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value');
        });
    }

    public function down(): void
    {
        foreach (['platform_settings', 'audit_entries', 'class_assignments', 'academic_classes', 'intakes', 'programmes', 'academic_years', 'identity_invitations', 'membership_permissions', 'memberships', 'user_roles', 'roles', 'permissions', 'institutions', 'join_applications'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['status', 'phone']));
    }
};
