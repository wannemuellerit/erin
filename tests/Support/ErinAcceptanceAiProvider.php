<?php

namespace Tests\Support;

use App\Contracts\AiProvider;
use App\Data\AiRequest;
use App\Data\AiResponse;

final class ErinAcceptanceAiProvider implements AiProvider
{
    public int $calls = 0;

    /** @var list<AiRequest> */
    public array $requests = [];

    public function respond(AiRequest $request): AiResponse
    {
        $this->calls++;
        $this->requests[] = $request;

        return new AiResponse(
            providerId: 'fake-ai-response-1',
            model: 'fake-recruiting-model',
            result: [
                'title' => 'Neutrale Zusammenfassung',
                'content' => 'Die fachlichen Angaben wurden zusammengefasst.',
                'suggestions' => ['Menschlich prüfen'],
                'caveats' => ['Keine automatische Entscheidung'],
            ],
            inputTokens: 41,
            outputTokens: 17,
        );
    }

    public function supportsSensitiveDocuments(): bool
    {
        return false;
    }
}
