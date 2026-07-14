<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Tests\Unit\Service\Llm;

use Bmorg\AiProofread\Service\ActivePreset;
use Bmorg\AiProofread\Service\ExtensionSettings;
use Bmorg\AiProofread\Service\Llm\HttpLlmCall;
use Bmorg\AiProofread\Service\Llm\LlmException;
use Bmorg\AiProofread\Service\Llm\OpenAiCompatibleClient;
use Bmorg\AiProofread\Service\PromptSettings;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Request construction and response parsing against the OpenRouter/OpenAI wire
 * format. Encodes the hard-won operational lessons as regressions: truncation
 * must throw (a force-closed JSON parses fine!), provider pinning must be
 * snake_case with a real boolean, fenced JSON must still parse.
 *
 * Model-shaping keys (model/reasoning/maxTokens/structuredOutput/pinProvider)
 * are fed through the Custom preset path (Registry), matching production wiring;
 * provider keys (baseUrl/apiKey/…) through mocked ext config.
 */
final class OpenAiCompatibleClientTest extends UnitTestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['findings' => ['type' => 'array']]];

    /**
     * @param array<string, mixed> $config ext-config values (baseUrl, apiKey, …)
     * @param array<string, mixed> $modelSettings the five model-shaping keys
     */
    private function createClient(array $config = [], array $modelSettings = []): OpenAiCompatibleClient
    {
        $config += ['apiKey' => 'sk-or-test', 'baseUrl' => 'https://openrouter.ai/api/v1'];
        $modelSettings += [
            'model' => 'anthropic/claude-opus-4.8',
            'reasoning' => false,
            'maxTokens' => 0,
            'structuredOutput' => 'json_schema',
            'pinProvider' => '',
        ];

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static function (string $extension, string $path = '') use ($config) {
                if (\array_key_exists($path, $config)) {
                    return $config[$path];
                }
                throw new \RuntimeException('not set: ' . $path, 1751500001);
            }
        );

        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $namespace, string $key) => $key === 'activePreset' ? 'custom' : $modelSettings
        );

        return new OpenAiCompatibleClient(
            new GuzzleClientFactory(),
            new ExtensionSettings($extensionConfiguration, new ActivePreset($registry), new PromptSettings($registry))
        );
    }

    private function buildCall(OpenAiCompatibleClient $client): HttpLlmCall
    {
        return $client->buildCall('System-Prompt', 'Zu prüfender Text', self::SCHEMA);
    }

    // -- request construction -------------------------------------------------

    public function testRequestCarriesModelMessagesAndUsageInclude(): void
    {
        $call = $this->buildCall($this->createClient());

        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $call->url);
        self::assertSame('anthropic/claude-opus-4.8', $call->requestBody['model']);
        self::assertSame('System-Prompt', $call->requestBody['messages'][0]['content']);
        self::assertSame('Zu prüfender Text', $call->requestBody['messages'][1]['content']);
        self::assertSame(['include' => true], $call->requestBody['usage']);
        self::assertSame('Bearer sk-or-test', $call->headers['Authorization']);
    }

    /**
     * Pinning must produce snake_case allow_fallbacks with a REAL boolean —
     * OpenRouter silently ignores a camelCase key or a "false" string, and
     * fallbacks would stay on (routing to providers that ignore response_format).
     */
    public function testPinProviderProducesStrictProviderBlock(): void
    {
        $call = $this->buildCall($this->createClient([], ['pinProvider' => 'anthropic']));

        self::assertSame(['order' => ['anthropic'], 'allow_fallbacks' => false], $call->requestBody['provider']);
        self::assertFalse($call->requestBody['provider']['allow_fallbacks']);
        self::assertStringContainsString('"allow_fallbacks":false', $call->body);
    }

    public function testEmptyPinProviderMeansFreeRouting(): void
    {
        $call = $this->buildCall($this->createClient());

        self::assertArrayNotHasKey('provider', $call->requestBody);
    }

    public function testReasoningTogglesReasoningBlockAndTokenBudget(): void
    {
        $off = $this->buildCall($this->createClient());
        $on = $this->buildCall($this->createClient([], ['reasoning' => true]));

        self::assertArrayNotHasKey('reasoning', $off->requestBody);
        self::assertSame(8000, $off->requestBody['max_tokens']);
        self::assertSame(['enabled' => true], $on->requestBody['reasoning']);
        self::assertSame(32000, $on->requestBody['max_tokens']);
    }

    public function testExplicitMaxTokensWins(): void
    {
        $call = $this->buildCall($this->createClient([], ['maxTokens' => 12345, 'reasoning' => true]));

        self::assertSame(12345, $call->requestBody['max_tokens']);
    }

    public function testJsonSchemaModeSendsStrictResponseFormat(): void
    {
        $call = $this->buildCall($this->createClient());

        self::assertSame('json_schema', $call->requestBody['response_format']['type']);
        self::assertTrue($call->requestBody['response_format']['json_schema']['strict']);
        self::assertSame(self::SCHEMA, $call->requestBody['response_format']['json_schema']['schema']);
        // Schema enforced → no schema dump in the prompt.
        self::assertSame('System-Prompt', $call->requestBody['messages'][0]['content']);
    }

    public function testJsonObjectModeEmbedsSchemaInThePrompt(): void
    {
        $call = $this->buildCall($this->createClient([], ['structuredOutput' => 'json_object']));

        self::assertSame(['type' => 'json_object'], $call->requestBody['response_format']);
        self::assertStringContainsString('Antworte ausschließlich mit JSON', $call->requestBody['messages'][0]['content']);
    }

    public function testMissingApiKeyThrowsConfigurationError(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/API-Key/');

        $this->buildCall($this->createClient(['apiKey' => '   ']));
    }

    /**
     * An empty model slug (a Custom preset saved without one) must fail at build
     * time with a pointed message — not as a provider 400 in a failed queue job.
     */
    public function testEmptyModelThrowsConfigurationError(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/Kein Modell konfiguriert/');

        $this->buildCall($this->createClient([], ['model' => '']));
    }

    // -- response parsing ------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     */
    private function responseBody(string $content, array $overrides = []): string
    {
        return json_encode(array_replace_recursive([
            'model' => 'anthropic/claude-opus-4.8',
            'provider' => 'anthropic',
            'choices' => [[
                'finish_reason' => 'stop',
                'native_finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => 0.0123],
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    public function testSuccessfulResponseYieldsPayloadUsageAndCost(): void
    {
        $client = $this->createClient();
        $payload = ['findings' => [], 'pageFindings' => [], 'other' => ['Hinweis']];

        $result = $client->parseResponse(
            $this->buildCall($client),
            200,
            $this->responseBody(json_encode($payload, JSON_THROW_ON_ERROR))
        );

        self::assertSame($payload, $result->payload);
        self::assertSame(100, $result->inputTokens);
        self::assertSame(50, $result->outputTokens);
        self::assertSame(0.0123, $result->cost);
    }

    /**
     * THE truncation regression: with strict structured output the decoder
     * force-closes the JSON, so a cut-off answer still parses — "the JSON is
     * valid" must never be trusted; only the finish reason is reliable.
     */
    public function testFinishReasonLengthThrowsDespiteParseableJson(): void
    {
        $client = $this->createClient();

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/abgeschnitten/');

        $client->parseResponse(
            $this->buildCall($client),
            200,
            $this->responseBody('{"findings":[],"pageFindings":[],"other":[]}', [
                'choices' => [['finish_reason' => 'length']],
            ])
        );
    }

    public function testNativeFinishReasonMaxTokensThrows(): void
    {
        $client = $this->createClient();

        $this->expectException(LlmException::class);

        $client->parseResponse(
            $this->buildCall($client),
            200,
            $this->responseBody('{}', [
                'choices' => [['finish_reason' => 'stop', 'native_finish_reason' => 'max_tokens']],
            ])
        );
    }

    /**
     * A provider that ignores response_format wraps the JSON in ```json fences
     * (observed: Google/Vertex serving Claude) — tolerant parsing must recover.
     */
    public function testFencedJsonIsParsedTolerantly(): void
    {
        $client = $this->createClient();

        $result = $client->parseResponse(
            $this->buildCall($client),
            200,
            $this->responseBody("```json\n{\"findings\":[],\"pageFindings\":[],\"other\":[]}\n```")
        );

        self::assertSame(['findings' => [], 'pageFindings' => [], 'other' => []], $result->payload);
    }

    public function testErrorObjectInHttp200Throws(): void
    {
        $client = $this->createClient();

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/boom/');

        $client->parseResponse(
            $this->buildCall($client),
            200,
            json_encode(['error' => ['message' => 'boom']], JSON_THROW_ON_ERROR)
        );
    }

    public function testHttp401PointsAtTheApiKey(): void
    {
        $client = $this->createClient();

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/API-Key fehlt oder ist ungültig/');

        $client->parseResponse(
            $this->buildCall($client),
            401,
            json_encode(['error' => ['message' => 'No auth credentials found']], JSON_THROW_ON_ERROR)
        );
    }

    public function testUnparseableContentThrowsInSchemaMode(): void
    {
        $client = $this->createClient();

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/kein gültiges JSON/');

        $client->parseResponse($this->buildCall($client), 200, $this->responseBody('kaputt, kein JSON'));
    }

    public function testUnparseableContentYieldsEmptyReportInPromptMode(): void
    {
        $client = $this->createClient([], ['structuredOutput' => 'prompt']);

        $result = $client->parseResponse(
            $this->buildCall($client),
            200,
            $this->responseBody('Hier ist meine Antwort in Prosa.')
        );

        self::assertSame(['findings' => [], 'pageFindings' => [], 'other' => []], $result->payload);
    }
}
