<?php

namespace App\Console\Commands;

use App\Models\AdminBootstrapInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'erin:bootstrap-admin
        {--email= : E-Mail-Adresse des Superadmins}
        {--name= : Anzeigename des Superadmins}
        {--expires=30 : Gültigkeit der einmaligen Einladung in Minuten}
        {--force : Eine bestehende Identität bewusst zum Superadmin hochstufen}';

    protected $description = 'Erzeugt eine kurzlebige, einmalig verwendbare Einladung für den ersten Erin-Superadmin.';

    public function handle(AuditLogger $audit): int
    {
        $email = trim((string) ($this->option('email') ?: config('erin.bootstrap_admin.email')));
        $name = trim((string) ($this->option('name') ?: config('erin.bootstrap_admin.name', 'Erin Superadmin')));
        $expires = (int) $this->option('expires');
        $allowRoleChange = (bool) $this->option('force');

        if ($email === '' && $this->input->isInteractive()) {
            $email = trim((string) $this->ask('E-Mail-Adresse'));
        }

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'expires' => $expires,
        ], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'expires' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && ! $existing->isSuperAdmin() && ! $this->option('force')) {
            $this->error('Für diese E-Mail existiert bereits eine andere Rolle. Nutze --force nur nach bewusster Prüfung.');

            return self::FAILURE;
        }

        if ($existing !== null && $existing->isSuperAdmin() && ! $this->option('force')) {
            $this->error('Für diese E-Mail existiert bereits ein Superadmin. Nutze --force nur für eine bewusst geprüfte Wiederherstellung.');

            return self::FAILURE;
        }

        $plainToken = Str::random(64);
        $invitation = DB::transaction(function () use (
            $audit,
            $email,
            $expires,
            $name,
            $plainToken,
            $allowRoleChange,
        ): AdminBootstrapInvitation {
            AdminBootstrapInvitation::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $invitation = AdminBootstrapInvitation::query()->create([
                'email' => $email,
                'name' => $name,
                'token_hash' => hash('sha256', $plainToken),
                'allow_role_change' => $allowRoleChange,
                'expires_at' => now()->addMinutes($expires),
            ]);

            $audit->record(
                'admin.bootstrap.invitation_created',
                $invitation,
                after: [
                    'email' => $email,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'allow_role_change' => $invitation->allow_role_change,
                ],
            );

            return $invitation;
        }, 3);

        $this->info(sprintf(
            'Einmalige Superadmin-Einladung für %s wurde erstellt und läuft %s ab.',
            $email,
            $invitation->expires_at->toIso8601String(),
        ));
        $this->warn('Der folgende Link wird nur einmal ausgegeben. Behandle ihn wie ein Passwort:');
        $this->line(route('admin-bootstrap.show', ['token' => $plainToken]));

        return self::SUCCESS;
    }
}
