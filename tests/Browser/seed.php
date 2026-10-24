<?php

use App\Models\AcademicClass;
use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Models\Institution;
use App\Models\JoinApplication;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.sqlite.database');
if (config('database.default') !== 'sqlite' || ! str_starts_with(basename($database), 'browser-') || ! str_starts_with(realpath($database), realpath(storage_path('app')))) {
    throw new RuntimeException('Browser fixtures are restricted to isolated browser SQLite files.');
}
$password = getenv('IDEYRA_BROWSER_PASSWORD');
if (! $password || strlen($password) < 20) {
    throw new RuntimeException('Random browser fixture password required.');
}
(new RolePermissionSeeder)->run();
$system = User::factory()->create(['name' => 'Browser System Administrator', 'email' => 'system@browser.example.test', 'password' => $password]);
$system->roles()->attach(Role::where('name', 'System Administrator')->sole());
$institution = Institution::factory()->create(['name' => 'Browser Institution', 'created_by' => $system->id]);
$admin = User::factory()->create(['name' => 'Browser Institution Administrator', 'email' => 'admin@browser.example.test', 'password' => $password]);
Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $admin->id, 'role_id' => Role::where('name', 'Institution Administrator')->value('id'), 'created_by' => $system->id]);
$student = User::factory()->create(['name' => 'Browser Student', 'email' => 'student@browser.example.test', 'password' => $password]);
$member = Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $student->id, 'created_by' => $system->id]);
$analyticsClass = AcademicClass::factory()->create(['institution_id' => $institution->id, 'name' => 'Analytics cohort']);
DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'academic_class_id' => $analyticsClass->id, 'membership_id' => $member->id, 'assignment' => 'student']);
$application = JoinApplication::factory()->create();
$groupParticipant = User::factory()->create(['name' => 'Browser Group Participant', 'email' => 'participant@browser.example.test', 'password' => $password]);
$groupMembership = Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $groupParticipant->id, 'created_by' => $system->id, 'status' => 'suspended']);
DB::table('class_assignments')->insert(['institution_id' => $institution->id, 'academic_class_id' => $analyticsClass->id, 'membership_id' => $groupMembership->id, 'assignment' => 'student']);
$reviewer = User::factory()->create(['name' => 'Browser Support Reviewer', 'email' => 'reviewer@browser.example.test', 'password' => $password]);
Membership::factory()->create(['institution_id' => $institution->id, 'user_id' => $reviewer->id, 'role_id' => Role::where('name', 'Institution Administrator')->value('id'), 'created_by' => $system->id]);
$aiFixture = AiConversation::factory()->create([
    'user_id' => $student->id,
    'messages' => [
        ['role' => 'user', 'text' => 'Browser fixture question', 'mode' => 'explore', 'fields' => []],
        ['role' => 'assistant', 'content' => ['reflection' => 'Test fixture: reflect on your learning goal.', 'questions' => ['What would you like to learn?'], 'assumptions' => [], 'risks' => [], 'alternatives' => [], 'next_action' => 'Write one learning goal.', 'original' => '', 'revision' => '', 'explanation' => '']],
    ],
]);
AiUsage::factory()->create(['user_id' => $student->id, 'ai_conversation_id' => $aiFixture->id]);
echo json_encode(['ai_conversation' => $aiFixture->id, 'institution' => $institution->id, 'student_member' => $member->id, 'application' => $application->uuid, 'second_student_member' => $groupMembership->id, 'class' => $analyticsClass->id]);
