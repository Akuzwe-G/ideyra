<?php

namespace App\Support;

class AiProviderFailure extends \RuntimeException
{
    public function __construct(string $safeStatus, public readonly ?int $inputTokens = null, public readonly ?int $outputTokens = null)
    {
        parent::__construct($safeStatus);
    }
}
