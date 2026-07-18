<?php

use App\Jobs\ProcessCandidateImport;
use App\Models\ActivityEntry;
use App\Models\CandidateImport;
use App\Models\Company;
use App\Models\LoginHistory;
use App\Models\Plan;
use App\Models\User;
use App\Services\Documents\ClamAvScanner;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('isolates large imports on the low queue and deduplicates each import', function () {
    $job = new ProcessCandidateImport(1234);

    expect($job->queue)->toBe('low')
        ->and($job->uniqueId())->toBe('1234')
        ->and($job->uniqueFor)->toBe(3600);
})->group('ops');

it('exposes queue backpressure through a failing machine-readable health command', function () {
    config([
        'queue.connections.database.connection' => config('database.default'),
        'queue.connections.database.table' => 'jobs',
    ]);
    Queue::connection('database')->pushOn('default', new ProcessCandidateImport(999999));

    $exitCode = Artisan::call('erin:ops:queue-health', [
        '--connection' => 'database',
        '--queues' => 'default',
        '--max-pending' => 0,
        '--max-failed' => 0,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"status": "backpressure"')
        ->toContain('"pending": 1');

    $exitCode = Artisan::call('erin:ops:queue-health', [
        '--connection' => 'database',
        '--queues' => 'default',
        '--max-pending' => 1,
        '--max-failed' => 0,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('"status": "healthy"');
})->group('ops');

it('redacts queue exception messages from machine-readable health output', function () {
    $this->mock(QueueFactory::class)
        ->shouldReceive('connection')
        ->once()
        ->andThrow(new RuntimeException('redis://user:must-not-appear@internal'));

    $exitCode = Artisan::call('erin:ops:queue-health', ['--json' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"status": "unavailable"')
        ->toContain('"error_code": "queue_probe_failed"')
        ->not->toContain('must-not-appear');
})->group('ops');

it('runs database, private-storage and ClamAV readiness probes without leaking secrets', function () {
    Storage::fake('private');
    $this->mock(ClamAvScanner::class)
        ->shouldReceive('scan')
        ->once()
        ->andReturn('clean');
    config([
        'app.env' => 'testing',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
    ]);

    $exitCode = Artisan::call('erin:ops:readiness', [
        '--probe' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"status": "ready_with_warnings"')
        ->toContain('"id": "probe.database"')
        ->toContain('"id": "probe.private_storage"')
        ->toContain('"id": "probe.clamav"')
        ->not->toContain((string) config('app.key'));

    expect(Storage::disk('private')->allFiles())->toBe([]);
})->group('ops');

it('redacts active-probe exception messages from readiness output', function () {
    Storage::fake('private');
    $this->mock(ClamAvScanner::class)
        ->shouldReceive('scan')
        ->once()
        ->andThrow(new RuntimeException('clamav-token=must-not-appear'));
    config([
        'app.env' => 'testing',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
    ]);

    $exitCode = Artisan::call('erin:ops:readiness', [
        '--probe' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"id": "probe.clamav"')
        ->toContain('nur die technische Fehlerklasse wurde protokolliert')
        ->not->toContain('must-not-appear');
})->group('ops');

it('blocks strict release readiness when security, DPO, legal, backup and pilot evidence is missing', function () {
    config([
        'app.env' => 'production',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.debug' => false,
        'app.demo_mode' => false,
        'app.url' => 'https://erin.example',
        'session.secure' => true,
        'session.http_only' => true,
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'filesystems.disks.private.driver' => 's3',
        'filesystems.disks.private.visibility' => 'private',
        'filesystems.disks.private.throw' => true,
        'operations.launch_evidence' => [],
    ]);

    $exitCode = Artisan::call('erin:ops:readiness', [
        '--strict' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"status": "not_ready"')
        ->toContain('"id": "security.review"')
        ->toContain('"id": "dpo.approval"')
        ->toContain('"id": "legal.approval"')
        ->toContain('"id": "backup.restore_drill"')
        ->toContain('"id": "pilot.approval"')
        ->toContain('"id": "evidence.release"');
})->group('ops');

it('rejects incomplete Stripe launch prices and unsafe Zammad URLs in production readiness', function () {
    Plan::factory()->create([
        'is_active' => true,
        'is_enterprise' => false,
        'stripe_product_id' => 'prod_readiness',
        'stripe_price_id' => null,
    ]);
    config([
        'app.env' => 'production',
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.debug' => false,
        'app.demo_mode' => false,
        'app.url' => 'https://erin.example',
        'session.secure' => true,
        'session.http_only' => true,
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'filesystems.disks.private.driver' => 's3',
        'filesystems.disks.private.visibility' => 'private',
        'filesystems.disks.private.throw' => true,
        'cashier.key' => 'pk_test_readiness',
        'cashier.secret' => 'sk_test_readiness',
        'cashier.webhook.secret' => 'whsec_readiness',
        'services.zammad.enabled' => true,
        'services.zammad.url' => 'http://127.0.0.1:8080',
        'services.zammad.webhook_callback_url' => 'http://laravel:8000/integrations/zammad/webhook',
        'services.zammad.allow_local_http' => true,
        'services.zammad.local_http_hosts' => ['zammad', 'laravel'],
        'services.zammad.token' => 'must-not-appear',
        'services.zammad.group' => 'Users',
        'services.zammad.webhook_secret' => str_repeat('x', 32),
    ]);

    $exitCode = Artisan::call('erin:ops:readiness', [
        '--strict' => true,
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('"id": "stripe.configured"')
        ->toContain('Product-/Price-IDs')
        ->toContain('"id": "zammad.configured"')
        ->toContain('sicher freigegebene Endpunkte')
        ->not->toContain('must-not-appear');
})->group('ops');

it('keeps retention disabled by default and deletes only expired approved targets', function () {
    Storage::fake('private');
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $oldImportPath = 'candidate-imports/old.csv';
    $freshImportPath = 'candidate-imports/fresh.csv';
    Storage::disk('private')->put($oldImportPath, 'old');
    Storage::disk('private')->put($freshImportPath, 'fresh');

    $oldImport = CandidateImport::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
        'original_filename' => 'old.csv',
        'disk' => 'private',
        'storage_path' => $oldImportPath,
        'status' => 'completed',
        'completed_at' => now()->subDays(31),
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);
    $freshImport = CandidateImport::query()->create([
        'company_id' => $company->getKey(),
        'created_by' => $user->getKey(),
        'original_filename' => 'fresh.csv',
        'disk' => 'private',
        'storage_path' => $freshImportPath,
        'status' => 'completed',
        'completed_at' => now()->subDays(2),
    ]);
    LoginHistory::query()->create([
        'user_id' => $user->getKey(),
        'email' => $user->email,
        'successful' => true,
        'created_at' => now()->subDays(31),
    ]);
    LoginHistory::query()->create([
        'user_id' => $user->getKey(),
        'email' => $user->email,
        'successful' => true,
        'created_at' => now()->subDays(2),
    ]);
    ActivityEntry::query()->create([
        'company_id' => $company->getKey(),
        'event' => 'ops.old',
        'visibility' => 'company',
        'occurred_at' => now()->subDays(31),
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'ops',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->getKey(),
        'data' => '{}',
        'read_at' => now()->subDays(31),
        'created_at' => now()->subDays(31),
        'updated_at' => now()->subDays(31),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'ops',
        'failed_at' => now()->subDays(31),
    ]);

    $exitCode = Artisan::call('erin:ops:prune', ['--json' => true]);
    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('"affected": 0');
    expect(CandidateImport::query()->count())->toBe(2);

    config([
        'operations.retention.login_history_days' => 30,
        'operations.retention.read_notification_days' => 30,
        'operations.retention.activity_days' => 30,
        'operations.retention.candidate_import_days' => 30,
        'operations.retention.failed_job_days' => 30,
    ]);
    $exitCode = Artisan::call('erin:ops:prune', [
        '--execute' => true,
        '--json' => true,
    ]);
    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('"mode": "execute"');

    expect(CandidateImport::query()->find($oldImport->getKey()))->toBeNull()
        ->and(CandidateImport::query()->find($freshImport->getKey()))->not->toBeNull()
        ->and(Storage::disk('private')->exists($oldImportPath))->toBeFalse()
        ->and(Storage::disk('private')->exists($freshImportPath))->toBeTrue()
        ->and(LoginHistory::query()->count())->toBe(1)
        ->and(ActivityEntry::query()->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->group('ops');
