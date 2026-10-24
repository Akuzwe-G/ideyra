<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AiProvider implements AiProviderContract
{
    public function available(): bool
    {
        if (! is_string(config('ai.key')) || trim(config('ai.key')) === ''
            || ! is_string(config('ai.model')) || trim(config('ai.model')) === '' || strlen(config('ai.model')) > 120
            || ! is_string(config('ai.endpoint'))) {
            return false;
        }
        $endpoint = parse_url(config('ai.endpoint'));

        return config('ai.enabled') && config('ai.provider') === 'openai-compatible' && filled($endpoint['host'] ?? null) && ($endpoint['scheme'] ?? '') === 'https' && ! isset($endpoint['user']) && ! isset($endpoint['pass']) && ! isset($endpoint['query']) && ! isset($endpoint['fragment']);
    }

    /** @return array{content: array, input_tokens: ?int, output_tokens: ?int} */
    public function respond(string $instructions, array $context): array
    {
        $inputTokens = null;
        $outputTokens = null;
        try {
            $response = Http::withToken(config('ai.key'))->acceptJson()->timeout(min(60, max(5, config('ai.timeout'))))->connectTimeout(5)->withOptions(['allow_redirects' => false])->post(config('ai.endpoint'), [
                'model' => config('ai.model'), 'store' => false, 'max_completion_tokens' => config('ai.max_tokens'), 'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $instructions], ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR)]],
            ]);
            $inputTokens = $this->tokens($response->json('usage.prompt_tokens'));
            $outputTokens = $this->tokens($response->json('usage.completion_tokens'));
            if (! $response->successful()) {
                throw new RuntimeException($response->status() === 429 ? 'rate_limited' : 'provider_failed');
            }
            $raw = $response->json('choices.0.message.content');
            if (! is_string($raw) || strlen($raw) > config('ai.max_output') || $response->json('choices.0.finish_reason') !== 'stop') {
                throw new RuntimeException('invalid_response');
            }
            $content = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($content)) {
                throw new RuntimeException('invalid_response');
            }

            return ['content' => $content, 'input_tokens' => $this->tokens($response->json('usage.prompt_tokens')), 'output_tokens' => $this->tokens($response->json('usage.completion_tokens'))];
        } catch (Throwable $error) {
            throw new AiProviderFailure(in_array($error->getMessage(), ['rate_limited', 'provider_failed', 'invalid_response'], true) ? $error->getMessage() : 'provider_failed', $inputTokens, $outputTokens);
        }
    }

    private function tokens(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value < 10000000 ? $value : null;
    }
}
