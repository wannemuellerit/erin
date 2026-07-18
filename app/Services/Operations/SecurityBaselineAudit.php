<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Route;
use Laravel\Telescope\Http\Middleware\Authorize as AuthorizeTelescope;

final class SecurityBaselineAudit
{
    /**
     * @return list<array{
     *     id: string,
     *     status: 'pass'|'fail',
     *     message: string
     * }>
     */
    public function checks(): array
    {
        $privateDisk = config('filesystems.disks.private');
        $privateDisk = is_array($privateDisk) ? $privateDisk : [];
        $allowedOrigins = config('reverb.apps.apps.0.allowed_origins', []);
        $allowedOrigins = is_array($allowedOrigins) ? $allowedOrigins : [];
        $rateLimiting = config('reverb.apps.apps.0.rate_limiting', []);
        $rateLimiting = is_array($rateLimiting) ? $rateLimiting : [];
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $buildSha = config('operations.build.sha');
        $imageTag = config('operations.build.image_tag');
        $internalSubnet = config('operations.network.internal_subnet');
        $trustedProxies = config('operations.network.trusted_proxies', []);
        $trustedProxies = is_array($trustedProxies) ? $trustedProxies : [];
        $minioAppUser = config('operations.storage.minio_app_user');

        return [
            $this->check(
                'runtime.production',
                config('app.env') === 'production',
                'Der Audit läuft mit der Produktionskonfiguration.',
                'Der Security-Audit muss mit APP_ENV=production laufen.',
            ),
            $this->check(
                'runtime.secrets',
                is_string(config('app.key')) && strlen((string) config('app.key')) >= 32,
                'Ein ausreichend langer Anwendungsschlüssel ist geladen.',
                'APP_KEY fehlt oder ist zu kurz.',
            ),
            $this->check(
                'runtime.exposure',
                config('app.debug') === false
                    && config('app.demo_mode') === false
                    && str_starts_with((string) config('app.url'), 'https://'),
                'Debug und Demo-Modus sind aus; die öffentliche URL verwendet HTTPS.',
                'Debug/Demo-Modus oder eine unsichere APP_URL blockiert die Freigabe.',
            ),
            $this->check(
                'release.immutable_image',
                $this->validCommitSha($buildSha)
                    && is_string($imageTag)
                    && hash_equals((string) $buildSha, $imageTag),
                'Image-Tag und unveränderlich eingebauter Build-SHA stimmen überein.',
                'ERIN_APP_TAG muss exakt dem 40-stelligen, im Image eingebauten Build-SHA entsprechen.',
            ),
            $this->check(
                'session.hardening',
                config('session.secure') === true
                    && config('session.http_only') === true
                    && config('session.encrypt') === true
                    && in_array(config('session.same_site'), ['lax', 'strict'], true),
                'Sessions sind Secure, HttpOnly, verschlüsselt und SameSite-geschützt.',
                'Session-Cookies oder serverseitige Sessiondaten sind nicht vollständig gehärtet.',
            ),
            $this->check(
                'runtime.redis',
                config('queue.default') === 'redis'
                    && config('cache.default') === 'redis'
                    && config('session.driver') === 'redis',
                'Queue, Cache und Sessions verwenden Redis.',
                'Queue, Cache und Sessions müssen in Produktion Redis verwenden.',
            ),
            $this->check(
                'proxy.trust_boundary',
                is_string($internalSubnet)
                    && $this->validCidr($internalSubnet, requirePrefix: true)
                    && in_array($internalSubnet, $trustedProxies, true)
                    && collect($trustedProxies)->every(
                        fn (mixed $proxy): bool => is_string($proxy)
                            && $this->validCidr($proxy),
                    )
                    && $this->nginxOverwritesForwardedHeaders(),
                'Laravel vertraut nur expliziten Proxy-Netzen; Nginx ersetzt eingehende Forwarded-Header.',
                'TRUSTED_PROXIES/ERIN_INTERNAL_SUBNET oder die Nginx-Forwarded-Header-Grenze ist unsicher.',
            ),
            $this->check(
                'storage.private',
                ($privateDisk['driver'] ?? null) === 's3'
                    && ($privateDisk['visibility'] ?? null) === 'private'
                    && ($privateDisk['throw'] ?? null) === true
                    && is_string($minioAppUser)
                    && $minioAppUser !== ''
                    && ($privateDisk['key'] ?? null) === $minioAppUser
                    && $this->minioPolicyIsBucketScoped(),
                'Sensible Dateien nutzen fail-closed Storage mit bucket-begrenztem MinIO-App-Nutzer.',
                'Privater Storage oder bucket-begrenzte MinIO-App-Zugangsdaten fehlen.',
            ),
            $this->check(
                'staff.two_factor',
                $this->routeHasMiddleware(
                    'admin.dashboard',
                    ['auth', 'verified', 'staff.2fa'],
                ),
                'Plattformrollen werden vor dem Adminbereich durch 2FA geschützt.',
                'Dem Adminbereich fehlt Authentifizierung, Verifikation oder die 2FA-Sperre.',
            ),
            $this->check(
                'downloads.signed',
                $this->allRoutesHaveMiddleware(
                    [
                        'documents.download',
                        'messages.attachments.download',
                        'support.attachments.download',
                        'interviews.ics',
                    ],
                    'signed',
                ),
                'Sensible Download- und Kalenderendpunkte verlangen signierte URLs.',
                'Mindestens ein sensibler Download-Endpunkt ist nicht signiert.',
            ),
            $this->check(
                'auth.rate_limits',
                config('fortify.limiters.login') === 'login'
                    && config('fortify.limiters.two-factor') === 'two-factor'
                    && config('fortify.limiters.passkeys') === 'passkeys',
                'Login, 2FA und Passkeys verwenden getrennte Rate-Limiter.',
                'Mindestens ein Authentifizierungs-Rate-Limiter fehlt.',
            ),
            $this->check(
                'telescope.access',
                $this->telescopeIsGuarded(),
                'Telescope verlangt Authentifizierung, Staff-2FA und Plattformautorisierung.',
                'Telescope ist nicht vollständig auf autorisierte Plattformrollen beschränkt.',
            ),
            $this->check(
                'openai.data_controls',
                config('services.openai.store') === false
                    && (
                        config('services.openai.document_ai_enabled') === false
                        || config('services.openai.eu_data_controls') === true
                    ),
                'OpenAI-Persistenz ist aus; Dokument-KI bleibt ohne EU-Datenkontrollen gesperrt.',
                'OpenAI-Persistenz oder Dokument-KI verletzt das konfigurierte Datenschutz-Gate.',
            ),
            $this->check(
                'livekit.security',
                str_starts_with((string) config('services.livekit.url'), 'wss://')
                    && filled(config('services.livekit.api_key'))
                    && filled(config('services.livekit.api_secret'))
                    && config('services.livekit.e2ee_required') === true
                    && config('services.livekit.region') === 'eu'
                    && (int) config('services.livekit.token_ttl_minutes') >= 1
                    && (int) config('services.livekit.token_ttl_minutes') <= 10,
                'LiveKit ist EU-gebunden, E2EE-pflichtig und nutzt kurzlebige Tokens.',
                'LiveKit benötigt WSS, EU-Pinning, E2EE, Zugangsdaten und höchstens zehn Minuten Tokenlaufzeit.',
            ),
            $this->check(
                'reverb.abuse_protection',
                $allowedOrigins !== []
                    && ! in_array('*', $allowedOrigins, true)
                    && is_string($appHost)
                    && in_array($appHost, $allowedOrigins, true)
                    && ($rateLimiting['enabled'] ?? null) === true
                    && ($rateLimiting['terminate_on_limit'] ?? null) === true
                    && (int) ($rateLimiting['max_attempts'] ?? 0) > 0
                    && (int) ($rateLimiting['max_attempts'] ?? 0) <= 120
                    && (int) ($rateLimiting['decay_seconds'] ?? 0) > 0
                    && (int) ($rateLimiting['decay_seconds'] ?? 0) <= 300,
                'Reverb beschränkt Origins und trennt Verbindungen bei überschrittenem Rate-Limit.',
                'Reverb benötigt explizite Origins sowie ein terminierendes Rate-Limit.',
            ),
        ];
    }

    /**
     * @param  list<string>  $required
     */
    private function routeHasMiddleware(string $routeName, array $required): bool
    {
        $route = Route::getRoutes()->getByName($routeName);
        if ($route === null) {
            return false;
        }

        $middleware = $route->gatherMiddleware();

        return collect($required)->every(
            static fn (string $name): bool => in_array($name, $middleware, true),
        );
    }

    /**
     * @param  list<string>  $routeNames
     */
    private function allRoutesHaveMiddleware(array $routeNames, string $required): bool
    {
        return collect($routeNames)->every(
            fn (string $routeName): bool => $this->routeHasMiddleware($routeName, [$required]),
        );
    }

    private function telescopeIsGuarded(): bool
    {
        $middleware = config('telescope.middleware', []);

        return is_array($middleware)
            && in_array('auth', $middleware, true)
            && in_array('staff.2fa', $middleware, true)
            && in_array(AuthorizeTelescope::class, $middleware, true);
    }

    private function validCommitSha(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1
            && preg_match('/\A([0-9a-f])\1{39}\z/', $value) !== 1;
    }

    private function validCidr(string $value, bool $requirePrefix = false): bool
    {
        if (in_array($value, ['*', '**', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }

        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return ! $requirePrefix;
        }

        if (preg_match('/\A\d{1,3}\z/', $prefix) !== 1) {
            return false;
        }

        $maxPrefix = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? 32
            : 128;

        return (int) $prefix > 0 && (int) $prefix <= $maxPrefix;
    }

    private function nginxOverwritesForwardedHeaders(): bool
    {
        $path = base_path('docker/production/nginx.conf');
        $config = is_readable($path) ? file_get_contents($path) : false;

        return is_string($config)
            && ! str_contains($config, '$proxy_add_x_forwarded_for')
            && str_contains($config, 'proxy_set_header X-Forwarded-For $remote_addr;')
            && str_contains($config, 'proxy_set_header X-Forwarded-Proto https;')
            && str_contains($config, 'proxy_set_header Forwarded "";')
            && str_contains($config, 'fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;')
            && str_contains($config, 'fastcgi_param HTTP_X_FORWARDED_PROTO https;')
            && str_contains($config, 'fastcgi_param HTTP_FORWARDED "";');
    }

    private function minioPolicyIsBucketScoped(): bool
    {
        $path = base_path('compose.production.yaml');
        $compose = is_readable($path) ? file_get_contents($path) : false;

        return is_string($compose)
            && str_contains($compose, 'AWS_ACCESS_KEY_ID: ${MINIO_APP_USER:')
            && str_contains($compose, 'AWS_SECRET_ACCESS_KEY: ${MINIO_APP_PASSWORD:')
            && str_contains($compose, 'mc admin user add local "$${MINIO_APP_USER}"')
            && str_contains($compose, 'mc admin policy create local erin-app-bucket')
            && str_contains($compose, 'mc admin policy attach local erin-app-bucket --user "$${MINIO_APP_USER}"')
            && str_contains($compose, 'arn:aws:s3:::$${AWS_BUCKET}/*');
    }

    /**
     * @return array{id: string, status: 'pass'|'fail', message: string}
     */
    private function check(
        string $id,
        bool $condition,
        string $success,
        string $failure,
    ): array {
        return [
            'id' => $id,
            'status' => $condition ? 'pass' : 'fail',
            'message' => $condition ? $success : $failure,
        ];
    }
}
