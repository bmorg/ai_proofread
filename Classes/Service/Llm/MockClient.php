<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

/**
 * Test double for {@see LlmClientInterface}: returns a deterministic fake
 * report without calling any API. Enable via the "useMock" extension setting
 * to exercise the full flow (reports, status transitions, audit log, UI) with
 * no API key and no cost.
 *
 * It reads the allowed categories from the request schema so the fake findings
 * stay consistent with the configured categories, and populates the same
 * request/response shape the real client logs.
 */
final class MockClient implements LlmClientInterface
{
    public function complete(string $systemPrompt, string $userText, array $jsonSchema): LlmResult
    {
        $allowed = $jsonSchema['properties']['findings']['items']['properties']['category']['enum'] ?? [];
        if (!\is_array($allowed) || $allowed === []) {
            $allowed = ['spelling', 'grammar', 'punctuation'];
        }

        $snippet = $this->firstWords($userText);
        $findings = [];
        foreach ($allowed as $category) {
            $findings[] = [
                'category' => (string)$category,
                'quote' => $snippet,
                'suggestion' => $snippet . ' [korrigiert]',
                'explanation' => sprintf('Beispielhafter %s-Hinweis (Mock-Backend).', (string)$category),
            ];
        }
        $pageFindings = [[
            'category' => (string)($allowed[count($allowed) - 1] ?? 'style'),
            'observation' => 'Beispielhafter seitenweiter Hinweis (Mock-Backend): uneinheitliche Schreibweise.',
            'suggestion' => 'Eine Form durchgängig verwenden.',
        ]];
        $other = ['Beispielhafter weiterer Hinweis (Mock-Backend): Formulierung prüfen.'];
        $payload = ['findings' => $findings, 'pageFindings' => $pageFindings, 'other' => $other];
        $jsonText = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"findings":[],"pageFindings":[],"other":[]}';

        // Mirror the real (OpenAI-compatible) client's request/response shapes so
        // the audit log is representative. Model "mock" has no reported cost.
        $requestBody = [
            'model' => 'mock',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userText],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'proofread', 'strict' => true, 'schema' => $jsonSchema],
            ],
        ];
        $inputTokens = (int)ceil(mb_strlen($systemPrompt . $userText) / 4);
        $outputTokens = (int)ceil(mb_strlen($jsonText) / 4);
        $responseBody = [
            'model' => 'mock',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $jsonText],
            ]],
            'usage' => [
                'prompt_tokens' => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens' => $inputTokens + $outputTokens,
            ],
        ];

        return new LlmResult($payload, 'mock', $inputTokens, $outputTokens, $requestBody, $responseBody);
    }

    public function completeBatch(array $requests, int $concurrency): array
    {
        // No I/O, so concurrency is irrelevant — just map each request.
        $out = [];
        foreach ($requests as $request) {
            $start = microtime(true);
            $result = $this->complete(
                (string)($request['system'] ?? ''),
                (string)($request['userText'] ?? ''),
                (array)($request['schema'] ?? [])
            );
            $result->durationMs = (int)round((microtime(true) - $start) * 1000);
            $out[] = $result;
        }
        return $out;
    }

    private function firstWords(string $text, int $count = 4): string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $slice = array_slice($words, 0, $count);
        return $slice === [] ? 'Beispieltext' : implode(' ', $slice);
    }
}
