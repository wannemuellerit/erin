<?php

namespace App\Contracts;

use App\Data\ExternalMessageRequest;
use App\Data\ExternalMessageResult;

interface ExternalMessageProvider
{
    public function send(ExternalMessageRequest $request): ExternalMessageResult;
}
