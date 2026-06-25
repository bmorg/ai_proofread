<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

/**
 * A fully-prepared HTTP request for one completion, plus the metadata a client
 * needs to parse the response. Produced by {@see AbstractHttpLlmClient::buildCall()}
 * so that many calls can be sent concurrently (Guzzle pool) and parsed afterwards.
 */
final class HttpLlmCall
{
    /**
     * @param array<string, string> $headers request headers (name => value)
     * @param array<string, mixed> $requestBody the decoded body, for logging and LlmException
     * @param array<string, mixed> $context client-specific parse hints (e.g. structured-output mode)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly array $requestBody,
        public readonly string $model,
        public readonly array $context = [],
    ) {
    }
}
