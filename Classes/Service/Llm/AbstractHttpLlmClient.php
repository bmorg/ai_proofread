<?php

declare(strict_types=1);

namespace Bmorg\AiProofread\Service\Llm;

use Bmorg\AiProofread\Service\ExtensionSettings;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

/**
 * Base for HTTP LLM backends. Subclasses just build a request ({@see buildCall})
 * and parse a response ({@see parseResponse}); this class owns the transport and,
 * crucially, runs a batch of calls **concurrently** via a Guzzle pool.
 *
 * Concurrency matters because a page check is N+1 independent, minutes-long,
 * I/O-bound requests (one per content element + one whole-page) — sending them
 * sequentially wastes nearly all the wall-clock. The pool is bounded by the
 * caller's concurrency cap to stay within provider rate limits.
 *
 * Constructors must stay side-effect-free: the LlmClientFactory has both the
 * real and mock clients injected, so both are instantiated regardless of which
 * is active. Validate config in buildCall(), not the constructor.
 */
abstract class AbstractHttpLlmClient implements LlmClientInterface
{
    public function __construct(
        private readonly GuzzleClientFactory $guzzleClientFactory,
        protected readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * Build the HTTP call for one completion.
     *
     * @param array<string, mixed> $jsonSchema
     * @throws LlmException on configuration errors (missing key/URL, …)
     */
    abstract protected function buildCall(string $systemPrompt, string $userText, array $jsonSchema): HttpLlmCall;

    /**
     * Parse a raw HTTP response into a result.
     *
     * @throws LlmException on any failure (HTTP error, truncation, bad JSON, …)
     */
    abstract protected function parseResponse(HttpLlmCall $call, int $statusCode, string $rawBody): LlmResult;

    /**
     * Total per-request timeout in seconds.
     */
    abstract protected function requestTimeout(): int;

    public function complete(string $systemPrompt, string $userText, array $jsonSchema): LlmResult
    {
        $outcome = $this->completeBatch([
            ['system' => $systemPrompt, 'userText' => $userText, 'schema' => $jsonSchema],
        ], 1)[0];

        if ($outcome instanceof LlmException) {
            throw $outcome;
        }
        return $outcome;
    }

    public function completeBatch(array $requests, int $concurrency, ?callable $onProgress = null): array
    {
        // Build all calls first; a build error (e.g. missing config) becomes that
        // item's outcome without aborting the others.
        $calls = [];
        $results = [];
        foreach ($requests as $i => $request) {
            try {
                $calls[$i] = $this->buildCall(
                    (string)($request['system'] ?? ''),
                    (string)($request['userText'] ?? ''),
                    (array)($request['schema'] ?? [])
                );
            } catch (LlmException $e) {
                $results[$i] = $e;
            }
        }

        if ($calls !== []) {
            $client = $this->guzzleClientFactory->getClient();
            // Per-call timing: the pool pulls each request from the generator when
            // a concurrency slot frees up — i.e. ~when it actually starts — so the
            // yield-time start stamp gives a good wall-clock duration per call.
            $starts = [];
            $makeRequests = function () use ($calls, &$starts): \Generator {
                foreach ($calls as $i => $call) {
                    $starts[$i] = microtime(true);
                    yield $i => new Request($call->method, $call->url, $call->headers, $call->body);
                }
            };

            $pool = new Pool($client, $makeRequests(), [
                'concurrency' => max(1, $concurrency),
                'options' => [
                    'timeout' => $this->requestTimeout(),
                    'http_errors' => false,
                ],
                'fulfilled' => function (ResponseInterface $response, $i) use (&$results, $calls, &$starts, $onProgress): void {
                    $results[$i] = $this->parseOutcome($calls[$i], $response, $this->elapsedMs($starts[$i] ?? null));
                    if ($onProgress !== null) {
                        $onProgress();
                    }
                },
                'rejected' => function ($reason, $i) use (&$results, $calls, &$starts, $onProgress): void {
                    $message = $reason instanceof \Throwable ? $reason->getMessage() : (string)$reason;
                    $e = new LlmException(
                        'HTTP-Anfrage fehlgeschlagen: ' . $message,
                        $calls[$i]->requestBody,
                        '',
                        1718700220,
                        $reason instanceof \Throwable ? $reason : null
                    );
                    $e->durationMs = $this->elapsedMs($starts[$i] ?? null);
                    $results[$i] = $e;
                    if ($onProgress !== null) {
                        $onProgress();
                    }
                },
            ]);
            $pool->promise()->wait();
        }

        ksort($results);
        return array_values($results);
    }

    private function parseOutcome(HttpLlmCall $call, ResponseInterface $response, ?int $durationMs): LlmResult|LlmException
    {
        try {
            $result = $this->parseResponse($call, $response->getStatusCode(), (string)$response->getBody());
            $result->durationMs = $durationMs;
            return $result;
        } catch (LlmException $e) {
            $e->durationMs = $durationMs;
            return $e;
        } catch (\Throwable $e) {
            $wrapped = new LlmException(
                'Antwort konnte nicht verarbeitet werden: ' . $e->getMessage(),
                $call->requestBody,
                '',
                1718700221,
                $e
            );
            $wrapped->durationMs = $durationMs;
            return $wrapped;
        }
    }

    private function elapsedMs(?float $start): ?int
    {
        return $start === null ? null : (int)round((microtime(true) - $start) * 1000);
    }

    protected function config(string $key): mixed
    {
        return $this->settings->get($key);
    }
}
