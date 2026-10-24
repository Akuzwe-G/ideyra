<?php

namespace App\Support;

interface AiProviderContract
{
    public function available(): bool;

    /** @return array{content: array, input_tokens: ?int, output_tokens: ?int} */
    public function respond(string $instructions, array $context): array;
}
