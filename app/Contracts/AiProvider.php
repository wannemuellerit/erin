<?php

namespace App\Contracts;

use App\Data\AiRequest;
use App\Data\AiResponse;

interface AiProvider
{
    public function respond(AiRequest $request): AiResponse;

    public function supportsSensitiveDocuments(): bool;
}
