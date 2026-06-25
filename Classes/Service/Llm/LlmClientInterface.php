<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

/**
 * Thin abstraction over the LLM backend so the proofreading logic stays
 * transport-agnostic. The real implementation is {@see OpenAiCompatibleClient}
 * (OpenAI chat-completions wire format, used with OpenRouter); {@see MockClient}
 * is a deterministic test double.
 */
interface LlmClientInterface
{
    /**
     * Run a single completion that must return JSON conforming to $jsonSchema.
     *
     * @param array<string, mixed> $jsonSchema JSON schema for the structured response
     * @return LlmResult decoded payload plus model/usage metadata
     * @throws LlmException on any failure (still carries the request for the audit log)
     */
    public function complete(string $systemPrompt, string $userText, array $jsonSchema): LlmResult;

    /**
     * Run several completions, possibly concurrently (bounded by $concurrency).
     * Returns one outcome per request, **in the same order**: an LlmResult on
     * success or an LlmException on failure — so one failed call neither aborts
     * the batch nor loses the others, and the caller logs each outcome itself.
     *
     * @param list<array{system: string, userText: string, schema: array<string, mixed>}> $requests
     * @return list<LlmResult|LlmException>
     */
    public function completeBatch(array $requests, int $concurrency): array;
}
