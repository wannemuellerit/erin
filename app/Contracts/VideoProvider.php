<?php

namespace App\Contracts;

use App\Data\VideoAccess;

interface VideoProvider
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function issueAccess(
        string $roomName,
        string $participantIdentity,
        string $participantName,
        array $metadata = [],
    ): VideoAccess;
}
