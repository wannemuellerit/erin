<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapSuperAdminCommand extends Command
{
    protected $signature = 'erin:bootstrap-admin
        {--email= : E-Mail-Adresse des Superadmins}
        {--name= : Anzeigename des Superadmins}
        {--password= : Starkes initiales Passwort; bevorzugt interaktiv oder per Secret-Umgebungsvariable}
        {--force : Eine bestehende Identität bewusst zum Superadmin hochstufen}';

    protected $description = 'Erstellt den ersten verifizierten Erin-Superadmin ohne Demo-Zugangsdaten.';

    public function handle(): int
    {
        $email = trim((string) ($this->option('email') ?: config('erin.bootstrap_admin.email')));
        $name = trim((string) ($this->option('name') ?: config('erin.bootstrap_admin.name', 'Erin Superadmin')));
        $password = (string) ($this->option('password') ?: config('erin.bootstrap_admin.password'));

        if ($email === '' && $this->input->isInteractive()) {
            $email = trim((string) $this->ask('E-Mail-Adresse'));
        }

        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Initiales Passwort (mindestens 16 Zeichen)');
        }

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'password' => [
                'required',
                'string',
                Password::min(16)->mixedCase()->letters()->numbers()->symbols(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && in_array(mb_strtolower($value), [
                        'password',
                        'password123!',
                        'admin',
                        'admin123!',
                    ], true)) {
                        $fail('Das initiale Passwort darf kein bekanntes Demo- oder Standardpasswort sein.');
                    }
                },
            ],
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

        $user = DB::transaction(function () use ($existing, $email, $name, $password): User {
            $user = $existing ?? new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'password' => $password,
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'locale' => 'de',
                'timezone' => 'Europe/Berlin',
                'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
            ])->save();

            return $user;
        });

        $this->info(sprintf('Superadmin %s wurde sicher bereitgestellt.', $user->email));
        $this->warn('Aktiviere nach dem ersten Login sofort die Zwei-Faktor-Authentifizierung.');

        return self::SUCCESS;
    }
}
