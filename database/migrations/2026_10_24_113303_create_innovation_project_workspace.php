<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('source_idea_id')->nullable()->constrained('ideas');
            $t->foreignId('reviewer_id')->nullable()->constrained('users');
            $t->string('title', 180);
            $t->text('description');
            $t->text('problem');
            $t->text('solution');
            $t->text('beneficiaries')->nullable();
            $t->string('category', 100);
            $t->json('tags')->nullable();
            $t->string('visibility', 24)->default('private');
            $t->string('status', 32)->default('draft');
            $t->timestamps();
            $t->unique(['source_idea_id', 'institution_id']);
            $t->index(['owner_id', 'status']);
            $t->index(['institution_id', 'visibility', 'status']);
        });
        Schema::create('idea_collaborators', function (Blueprint $t): void {
            $t->foreignId('idea_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->primary(['idea_id', 'user_id']);
        });
        Schema::create('idea_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('idea_id')->constrained();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('decision', 32);
            $t->text('feedback');
            $t->timestamps();
        });
        Schema::create('project_groups', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('institution_id')->constrained();
            $t->foreignId('created_by')->constrained('users');
            $t->unsignedBigInteger('academic_class_id');
            $t->string('name', 180);
            $t->text('description')->nullable();
            $t->string('status', 24)->default('draft');
            $t->timestamps();
            $t->unique(['id', 'institution_id']);
            $t->index(['institution_id', 'status']);
            $t->foreign(['academic_class_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_classes');
        });
        Schema::create('group_members', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('institution_id');
            $t->unsignedBigInteger('membership_id');
            $t->string('role', 24)->default('member');
            $t->json('delegated_permissions')->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'membership_id']);
            $t->foreign(['group_id', 'institution_id'])->references(['id', 'institution_id'])->on('project_groups');
            $t->foreign(['membership_id', 'institution_id'])->references(['id', 'institution_id'])->on('memberships');
        });
        Schema::create('group_supervisors', function (Blueprint $t): void {
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('institution_id');
            $t->unsignedBigInteger('membership_id');
            $t->primary(['group_id', 'membership_id']);
            $t->foreign(['group_id', 'institution_id'])->references(['id', 'institution_id'])->on('project_groups');
            $t->foreign(['membership_id', 'institution_id'])->references(['id', 'institution_id'])->on('memberships');
        });
        Schema::create('projects', function (Blueprint $t): void {
            $t->id();
            $t->ulid('identifier')->unique();
            $t->foreignId('owner_id')->constrained('users');
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->foreignId('idea_id')->nullable()->unique()->constrained('ideas');
            $t->unsignedBigInteger('group_id')->nullable();
            $t->unsignedBigInteger('academic_class_id')->nullable();
            $t->string('title', 180);
            $t->text('description');
            $t->text('problem');
            $t->text('objectives');
            $t->text('outcomes');
            $t->string('category', 100);
            $t->string('status', 32)->default('draft');
            $t->date('starts_at')->nullable();
            $t->date('target_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'institution_id']);
            $t->index(['institution_id', 'status']);
            $t->index(['owner_id', 'status']);
            $t->index('completed_at');
            $t->foreign(['group_id', 'institution_id'])->references(['id', 'institution_id'])->on('project_groups');
            $t->foreign(['academic_class_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_classes');
        });
        Schema::create('project_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('institution_id')->nullable()->constrained();
            $t->unsignedBigInteger('membership_id')->nullable();
            $t->string('role', 24)->default('member');
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
            $t->foreign(['project_id', 'institution_id'])->references(['id', 'institution_id'])->on('projects');
            $t->foreign(['membership_id', 'institution_id'])->references(['id', 'institution_id'])->on('memberships');
        });
        Schema::create('project_decisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('decision', 32);
            $t->text('reason');
            $t->timestamps();
        });
        Schema::create('milestones', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->date('due_at');
            $t->string('status', 24)->default('not_started');
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'project_id']);
            $t->index(['project_id', 'sort_order']);
        });
        Schema::create('project_tasks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->unsignedBigInteger('milestone_id')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->string('priority', 16)->default('medium');
            $t->string('status', 24)->default('to_do');
            $t->date('starts_at')->nullable();
            $t->date('due_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'project_id']);
            $t->index(['project_id', 'status', 'due_at']);
            $t->foreign(['milestone_id', 'project_id'])->references(['id', 'project_id'])->on('milestones');
        });
        Schema::create('task_assignees', function (Blueprint $t): void {
            $t->unsignedBigInteger('task_id');
            $t->foreignId('project_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->primary(['task_id', 'user_id']);
            $t->foreign(['task_id', 'project_id'])->references(['id', 'project_id'])->on('project_tasks');
        });
        Schema::create('task_comments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('task_id')->constrained('project_tasks');
            $t->foreignId('author_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('deliverables', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->unsignedBigInteger('milestone_id')->nullable();
            $t->foreignId('assigned_user_id')->constrained('users');
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->date('due_at');
            $t->string('status', 24)->default('pending');
            $t->timestamps();
            $t->unique(['id', 'project_id']);
            $t->index(['project_id', 'status']);
            $t->foreign(['milestone_id', 'project_id'])->references(['id', 'project_id'])->on('milestones');
        });
        Schema::create('submissions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('deliverable_id');
            $t->foreignId('project_id')->constrained();
            $t->foreignId('submitted_by')->constrained('users');
            $t->unsignedInteger('version');
            $t->text('body');
            $t->string('file_path');
            $t->string('file_name', 180);
            $t->string('mime', 100);
            $t->unsignedBigInteger('bytes');
            $t->timestamps();
            $t->unique(['deliverable_id', 'version']);
            $t->unique(['id', 'project_id']);
            $t->foreign(['deliverable_id', 'project_id'])->references(['id', 'project_id'])->on('deliverables');
        });
        Schema::create('deliverable_reviews', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('submission_id')->unique()->constrained('submissions');
            $t->foreignId('reviewer_id')->constrained('users');
            $t->string('decision', 24);
            $t->text('feedback');
            $t->timestamps();
        });
        Schema::create('supervision_notes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('author_id')->constrained('users');
            $t->unsignedBigInteger('deliverable_id')->nullable();
            $t->string('visibility', 16)->default('feedback');
            $t->text('body');
            $t->timestamps();
            $t->foreign(['deliverable_id', 'project_id'])->references(['id', 'project_id'])->on('deliverables');
        });
        Schema::create('project_activities', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->string('action', 100);
            $t->text('metadata')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'created_at']);
        });
        Schema::create('task_reminders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('task_id')->constrained('project_tasks');
            $t->foreignId('user_id')->constrained();
            $t->date('due_at');
            $t->timestamps();
            $t->unique(['task_id', 'user_id', 'due_at']);
        });
    }

    public function down(): void
    {
        foreach (['task_reminders', 'project_activities', 'supervision_notes', 'deliverable_reviews', 'submissions', 'deliverables', 'task_comments', 'task_assignees', 'project_tasks', 'milestones', 'project_decisions', 'project_members', 'projects', 'group_supervisors', 'group_members', 'project_groups', 'idea_reviews', 'idea_collaborators', 'ideas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
