<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use App\Data\AiRequest;
use App\Data\AiResponse;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;

final readonly class OpenAiResponsesProvider implements AiProvider
{
    public function __construct(private HttpFactory $http) {}

    public function respond(AiRequest $request): AiResponse
    {
        if ($request->sensitive && ! $this->supportsSensitiveDocuments()) {
            throw new RuntimeException(
                'Die Verarbeitung sensibler Dokumente erfordert einen aktivierten OpenAI-EU-Endpunkt mit Datenkontrollen.',
            );
        }

        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY ist nicht konfiguriert.');
        }

        $model = $request->model ?: $this->modelFor($request->task);
        $content = [[
            'type' => 'input_text',
            'text' => json_encode($request->input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]];

        foreach ($request->documents as $document) {
            $content[] = [
                'type' => 'input_file',
                'filename' => $document['filename'] ?? 'document',
                'file_data' => sprintf(
                    'data:%s;base64,%s',
                    $document['mime_type'],
                    $document['data'],
                ),
            ];
        }

        $response = $this->http
            ->baseUrl(rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/'))
            ->withToken($apiKey)
            ->withHeaders(array_filter([
                'OpenAI-Project' => config('services.openai.project'),
                'OpenAI-Organization' => config('services.openai.organization'),
            ], fn (mixed $value): bool => filled($value)))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.openai.timeout', 90))
            ->retry(2, 500, throw: false)
            ->post('/v1/responses', [
                'model' => $model,
                'store' => false,
                'instructions' => $request->instructions,
                'input' => [[
                    'role' => 'user',
                    'content' => $content,
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $this->schemaName($request->task),
                        'strict' => true,
                        'schema' => $request->schema,
                    ],
                ],
            ]);

        $this->ensureSuccessful($response);

        $payload = $response->json();
        $text = $this->extractText(is_array($payload) ? $payload : []);
        $result = json_decode($text, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($result)) {
            throw new RuntimeException('OpenAI hat kein strukturiertes Ergebnis geliefert.');
        }

        return new AiResponse(
            providerId: (string) $response->json('id'),
            model: (string) ($response->json('model') ?: $model),
            result: $result,
            inputTokens: (int) $response->json('usage.input_tokens', 0),
            outputTokens: (int) $response->json('usage.output_tokens', 0),
        );
    }

    public function supportsSensitiveDocuments(): bool
    {
        return (bool) config('services.openai.eu_data_controls', false)
            && str_starts_with(
                (string) config('services.openai.base_url'),
                'https://eu.api.openai.com',
            );
    }

    private function modelFor(string $task): string
    {
        $economyTasks = [
            'translate',
            'extract_profile',
            'summarize',
            'classify',
        ];

        return in_array($task, $economyTasks, true)
            ? (string) config('services.openai.economy_model', 'gpt-5.6-luna')
            : (string) config('services.openai.quality_model', 'gpt-5.6-terra');
    }

    private function schemaName(string $task): string
    {
        $name = preg_replace('/[^a-z0-9_-]+/', '_', strtolower($task)) ?: 'erin_result';

        return substr("erin_{$name}", 0, 64);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractText(array $payload): string
    {
        foreach (Arr::wrap($payload['output'] ?? []) as $output) {
            if (! is_array($output)) {
                continue;
            }

            foreach (Arr::wrap($output['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }

        throw new RuntimeException('Die OpenAI-Antwort enthielt keinen Textinhalt.');
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) $response->json('error.message', 'Unbekannter OpenAI-Fehler.');

        throw new RuntimeException("OpenAI-Anfrage fehlgeschlagen ({$response->status()}): {$message}");
    }
}
