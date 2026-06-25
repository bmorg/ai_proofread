<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

use Bmorg\AiProofread\Service\ExtensionSettings;

/**
 * Provides the active LLM client. There is one real backend — the
 * OpenAI-compatible connector, used with OpenRouter — plus a deterministic
 * mock for testing, selected by the "useMock" extension setting.
 */
final class LlmClientFactory
{
    public function __construct(
        private readonly OpenAiCompatibleClient $client,
        private readonly MockClient $mock,
        private readonly ExtensionSettings $settings,
    ) {
    }

    public function create(): LlmClientInterface
    {
        return (bool)$this->settings->get('useMock') ? $this->mock : $this->client;
    }
}
