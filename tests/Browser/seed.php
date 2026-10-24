<?php

use App\Models\AcademicClass;
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
echo json_encode(['institution' => $institution->id, 'student_member' => $member->id, 'application' => $application->uuid]);
