<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

/**
 * Thrown when an LLM call fails. Carries the full request payload and the raw
 * response text so the failure can still be written to the audit log.
 */
final class LlmException extends \RuntimeException
{
    /** Wall-clock duration of the failed call in ms, if it reached the network. */
    public ?int $durationMs = null;

    /**
     * @param array<string, mixed> $requestBody the request that was attempted
     */
    public function __construct(
        string $message,
        public readonly array $requestBody = [],
        public readonly string $rawResponse = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
