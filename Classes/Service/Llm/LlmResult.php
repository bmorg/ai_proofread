<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

/**
 * Result of an {@see LlmClientInterface::complete()} call. Carries
 * both the parsed payload and the full request/response, so callers can write
 * a complete audit log without re-building anything.
 */
final class LlmResult
{
    /** Wall-clock duration of the API call in milliseconds; set by the transport. */
    public ?int $durationMs = null;

    /** Actual cost in USD if the provider reports one (e.g. OpenRouter); else null. */
    public ?float $cost = null;

    /**
     * @param array<string, mixed> $payload decoded structured-output JSON
     * @param string $model the model id that produced the response
     * @param int $inputTokens prompt tokens billed
     * @param int $outputTokens completion tokens billed
     * @param array<string, mixed> $requestBody the full request payload sent to the API
     * @param array<string, mixed> $responseBody the full decoded API response
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly array $requestBody,
        public readonly array $responseBody,
    ) {
    }
}
