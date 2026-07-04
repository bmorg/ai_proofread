<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

/**
 * OpenAI-compatible chat-completions client, used with OpenRouter. The OpenAI
 * wire format also covers other compatible endpoints, but OpenRouter is the
 * configured and tested target.
 *
 * Configured via the "provider" settings: base URL, API key (sent as
 * `Authorization: Bearer`), model, structured-output mode, reasoning, max tokens.
 *
 * Transport + concurrency live in {@see AbstractHttpLlmClient}; this class only
 * builds the request and parses the response.
 */
final class OpenAiCompatibleClient extends AbstractHttpLlmClient
{
    private const DEFAULT_MAX_TOKENS = 8000;

    // Reasoning tokens count against max_tokens; a verbose reasoner can exhaust
    // a small budget and emit a truncated/empty answer. Default much higher when
    // reasoning is on.
    private const REASONING_MAX_TOKENS = 32000;

    /**
     * @internal public (widened from protected) only so request construction —
     *   provider pinning, reasoning, token budgets, response_format — is
     *   unit-testable on this final class; call complete()/completeBatch().
     */
    public function buildCall(string $systemPrompt, string $userText, array $jsonSchema): HttpLlmCall
    {
        $baseUrl = rtrim((string)$this->config('baseUrl'), '/'); // default in ExtensionSettings::DEFAULTS
        $model = (string)($this->config('model') ?? '');
        $mode = (string)($this->config('structuredOutput') ?: 'json_schema');
        $reasoning = (bool)$this->config('reasoning');

        // For modes without enforced schema, tell the model the exact shape.
        $system = $systemPrompt;
        if ($mode !== 'json_schema') {
            $system .= "\n\nAntworte ausschließlich mit JSON nach diesem Schema "
                . "(keine Erklärungen außerhalb des JSON):\n"
                . (json_encode($jsonSchema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        $body = [
            'model' => $model,
            'max_tokens' => $this->maxTokens($reasoning),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userText],
            ],
            // Ask OpenRouter to include the actual cost (USD) in usage.cost. This
            // `usage` field — like `reasoning` and `provider` below — is
            // OpenRouter-specific: a stricter OpenAI-compatible endpoint may reject it
            // as an unknown top-level argument with a 400 (api.openai.com historically
            // does), not silently ignore it. Only OpenRouter is tested — see README.
            'usage' => ['include' => true],
        ];
        $body += $this->responseFormat($mode, $jsonSchema);

        // OpenRouter provider routing: pin strictly to one provider, or route
        // freely. Pinning (pinProvider set, e.g. "anthropic") serves the request
        // *only* by that provider with no fallbacks — so a provider that enforces
        // valid structured-output JSON is guaranteed, and a temporary outage fails
        // with a clear error instead of silently routing to one that ignores
        // response_format (Google → fenced/invalid JSON). allow_fallbacks is
        // snake_case and a real boolean (a camelCase key or "false" string is
        // silently ignored → fallbacks stay on). Empty pinProvider = free routing.
        $pinProvider = trim((string)($this->config('pinProvider') ?? ''));
        if ($pinProvider !== '') {
            $body['provider'] = ['order' => [$pinProvider], 'allow_fallbacks' => false];
        }

        // Provider reasoning/thinking (OpenRouter "reasoning" — OpenRouter-specific,
        // see the usage note above) — improves recall noticeably, at the cost of
        // extra (reasoning) output tokens.
        if ($reasoning) {
            $body['reasoning'] = ['enabled' => true];
        }

        if ($baseUrl === '') {
            throw new LlmException('Keine OpenAI-kompatible Basis-URL konfiguriert (Erweiterungskonfiguration ai_proofread).', $body, '', 1718700210);
        }
        // Shipped presets always pin a slug — an empty model means the Custom
        // preset was saved without one. Fail here with a pointed message instead
        // of a provider 400 in a failed queue job later.
        if (trim($model) === '') {
            throw new LlmException('Kein Modell konfiguriert (KI-Lektorat → Einstellungen, Preset „Benutzerdefiniert“).', $body, '', 1718700217);
        }
        if (trim((string)($this->config('apiKey') ?? '')) === '') {
            throw new LlmException('Kein API-Key konfiguriert (Erweiterungskonfiguration ai_proofread → Provider).', $body, '', 1718700216);
        }

        return new HttpLlmCall(
            'POST',
            $baseUrl . '/chat/completions',
            $this->headers(),
            json_encode($body, JSON_THROW_ON_ERROR),
            $body,
            $model,
            ['mode' => $mode],
        );
    }

    /**
     * @internal public (widened from protected) only so response handling —
     *   truncation detection, tolerant JSON parsing, error surfacing — is
     *   unit-testable on this final class; call complete()/completeBatch().
     */
    public function parseResponse(HttpLlmCall $call, int $statusCode, string $rawBody): LlmResult
    {
        $body = $call->requestBody;
        $model = $call->model;
        $mode = (string)($call->context['mode'] ?? 'json_schema');

        if ($statusCode !== 200) {
            // Surface the provider's own error message, and for auth failures point
            // at the most likely cause (a missing/invalid API key).
            $apiMessage = '';
            $maybe = json_decode($rawBody, true);
            if (\is_array($maybe)) {
                $apiMessage = (string)($maybe['error']['message'] ?? '');
            }
            $detail = $apiMessage !== '' ? ' (' . $apiMessage . ')' : '';
            $hint = ($statusCode === 401 || $statusCode === 403)
                ? ' Der API-Key fehlt oder ist ungültig — bitte in der Erweiterungskonfiguration prüfen.'
                : '';
            throw new LlmException(
                sprintf('Der OpenAI-kompatible Endpunkt hat HTTP %d zurückgegeben%s.%s', $statusCode, $detail, $hint),
                $body,
                $rawBody,
                1718700212
            );
        }

        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (isset($decoded['error'])) {
            $message = (string)($decoded['error']['message'] ?? 'unbekannter Fehler');
            throw new LlmException('Fehler des OpenAI-kompatiblen Endpunkts: ' . $message, $body, $rawBody, 1718700213);
        }

        // Truncation: with structured output the decoder force-closes the JSON,
        // so a cut-off answer can still parse — the only reliable signal is the
        // finish reason. Must be checked, or reasoning models silently produce
        // near-empty reports.
        $finishReason = (string)($decoded['choices'][0]['finish_reason'] ?? '');
        $nativeFinish = (string)($decoded['choices'][0]['native_finish_reason'] ?? '');
        if ($finishReason === 'length' || $nativeFinish === 'max_tokens') {
            throw new LlmException(
                'Antwort wurde abgeschnitten (max_tokens erreicht). Max-Tokens erhöhen oder Reasoning deaktivieren.',
                $body,
                $rawBody,
                1718700214
            );
        }

        $content = (string)($decoded['choices'][0]['message']['content'] ?? '');

        // The response is complete here (truncation is already caught via the
        // finish reason), but OpenRouter may route to a provider that ignores the
        // json_schema constraint and wraps the JSON in ```json fences or prose —
        // so parse tolerantly (first {...} span) in every mode. A genuine parse
        // failure in a schema mode is surfaced rather than stored as empty.
        $payload = $this->tryParseJson($content);
        if ($payload === null) {
            if ($mode === 'prompt') {
                $payload = ['findings' => [], 'pageFindings' => [], 'other' => []];
            } else {
                throw new LlmException(
                    'Antwort war kein gültiges JSON (möglicherweise abgeschnitten oder fehlerhaft).',
                    $body,
                    $rawBody,
                    1718700215
                );
            }
        }

        $result = new LlmResult(
            $payload,
            (string)($decoded['model'] ?? $model),
            (int)($decoded['usage']['prompt_tokens'] ?? 0),
            (int)($decoded['usage']['completion_tokens'] ?? 0),
            $body,
            \is_array($decoded) ? $decoded : [],
        );

        // OpenRouter (and some gateways) report the actual spend in usage.cost.
        $cost = $decoded['usage']['cost'] ?? null;
        if (is_numeric($cost)) {
            $result->cost = (float)$cost;
        }

        return $result;
    }

    protected function requestTimeout(): int
    {
        $configured = (int)($this->config('requestTimeout') ?? 0);
        if ($configured > 0) {
            return $configured;
        }
        return (bool)$this->config('reasoning') ? 600 : 180;
    }

    /**
     * @param array<string, mixed> $jsonSchema
     * @return array<string, mixed>
     */
    private function responseFormat(string $mode, array $jsonSchema): array
    {
        return match ($mode) {
            'json_schema' => ['response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'proofread', 'strict' => true, 'schema' => $jsonSchema],
            ]],
            'json_object' => ['response_format' => ['type' => 'json_object']],
            // 'prompt' (or anything else): no response_format — rely on the prompt.
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['content-type' => 'application/json'];

        // OpenRouter (and the OpenAI wire format) authenticate with a bearer token.
        $apiKey = trim((string)($this->config('apiKey') ?? ''));
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
    }

    /**
     * Best-effort JSON parse of a model response: try the raw content, then —
     * since a provider may wrap the JSON in ```json fences or prose even when a
     * schema was requested — the first {...} span. Returns null if neither parses.
     *
     * @return array<string, mixed>|null
     */
    private function tryParseJson(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (\is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /**
     * Output token cap: the configured value if set, otherwise an automatic
     * default that is much higher when reasoning is on.
     */
    private function maxTokens(bool $reasoning): int
    {
        $configured = (int)($this->config('maxTokens') ?? 0);
        if ($configured > 0) {
            return $configured;
        }
        return $reasoning ? self::REASONING_MAX_TOKENS : self::DEFAULT_MAX_TOKENS;
    }
}
