<?php

/**
 * Golden test for ZgwApiClient::callComponent().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use Exception;
use OCA\Pipelinq\Service\Zgw\ZgwApiClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every request callComponent() sends, and every answer or error it gives,
 * compared with ZgwApiClientCallGolden.json. Set ZGW_CALL_GOLDEN_RECORD=1 to
 * rewrite the file, then run once more without it.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */
class ZgwApiClientCallGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ZgwApiClientCallGolden.json';

	private const SECRET = 's3cret';

	/**
	 * Calls made on the HTTP client in the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Info lines logged in the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logged = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('ZGW_CALL_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: callComponent's arguments, the response or error, and the timeout config.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$client = ['clientIdentifier' => 'pipelinq', 'secretVaultRef' => 'vault://zgw/test', 'userId' => 'u-1', 'userRepresentation' => 'Agent One'];
		$base = ['componentUrl' => 'https://zrc.example/api/v1/', 'method' => 'GET', 'path' => '/zaken', 'client' => $client];
		$ok = ['status' => 200, 'body' => '{"count":1,"results":[{"url":"https://zrc.example/z/1"}]}', 'headers' => ['ETag' => ['"abc"', '"def"'], 'Content-Type' => 'application/json', 'X-Empty' => []]];
		$fault = static fn (int $code, string $message, ?int $status = null, string $body = ''): array => ['code' => $code, 'message' => $message, 'status' => $status, 'body' => $body];
		return [
			'get, plain'                         => ['args' => $base, 'response' => $ok],
			'get with query'                     => ['args' => [...$base, 'query' => ['page' => 2, 'zaaktype' => 'https://ztc.example/zt/1']], 'response' => $ok],
			'get with query on a path with ?'    => ['args' => [...$base, 'path' => 'zaken?status=open', 'query' => ['page' => 3]], 'response' => $ok],
			'get, empty query'                   => ['args' => [...$base, 'componentUrl' => 'https://zrc.example', 'path' => 'zaken', 'query' => []], 'response' => $ok],
			'lowercase method'                   => ['args' => [...$base, 'method' => 'get'], 'response' => $ok],
			'post with body and headers'         => ['args' => [...$base, 'method' => 'POST', 'body' => ['omschrijving' => 'Zaak é/1', 'url' => 'https://x/y'], 'extraHeaders' => ['Accept' => 'application/hal+json', 'If-Match' => '"abc"']], 'response' => ['status' => 201, 'body' => '{"uuid":"z-1"}', 'headers' => ['Location' => 'https://zrc.example/z/1']]],
			'put without body'                   => ['args' => [...$base, 'method' => 'PUT'], 'response' => ['status' => 200, 'body' => '', 'headers' => []]],
			'patch with empty body'              => ['args' => [...$base, 'method' => 'PATCH', 'body' => []], 'response' => ['status' => 200, 'body' => '[]', 'headers' => []]],
			'delete'                             => ['args' => [...$base, 'method' => 'DELETE'], 'response' => ['status' => 204, 'body' => '', 'headers' => []]],
			'non-json body'                      => ['args' => $base, 'response' => ['status' => 200, 'body' => '<html>oops</html>', 'headers' => []]],
			'json scalar body'                   => ['args' => $base, 'response' => ['status' => 200, 'body' => '5', 'headers' => []]],
			'unsupported method'                 => ['args' => [...$base, 'method' => 'HEAD'], 'response' => $ok],
			'token lifespan from the client'     => ['args' => [...$base, 'client' => [...$client, 'tokenLifespanSeconds' => '120']], 'response' => $ok],
			'token lifespan below the floor'     => ['args' => [...$base, 'client' => [...$client, 'tokenLifespanSeconds' => 10]], 'response' => $ok],
			'secret missing'                     => ['args' => [...$base, 'client' => [...$client, 'secretVaultRef' => 'vault://zgw/none']], 'response' => $ok],
			'timeout from config'                => ['args' => $base, 'response' => $ok, 'timeout' => 12],
			'timeout zero falls back'            => ['args' => $base, 'response' => $ok, 'timeout' => 0],
			'401 clock skew in the body'         => ['args' => $base, 'error' => $fault(0, 'Client error', 401, '{"detail":"JWT verlopen"}')],
			'403 clock skew in the message'      => ['args' => [...$base, 'method' => 'POST', 'body' => ['a' => 1]], 'error' => $fault(0, 'jwt nog niet geldig', 403)],
			'401 without skew'                   => ['args' => $base, 'error' => $fault(0, 'Unauthorized', 401, 'nope')],
			'404 not found'                      => ['args' => [...$base, 'method' => 'DELETE'], 'error' => $fault(0, 'Not found', 404)],
			'412 precondition'                   => ['args' => [...$base, 'method' => 'PATCH', 'body' => ['x' => 1]], 'error' => $fault(0, 'Precondition', 412)],
			'500 by code only'                   => ['args' => [...$base, 'method' => 'PUT'], 'error' => $fault(500, 'Server error')],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh client.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$this->logged = [];
		$http = $this->createMock(IClient::class);
		foreach (['get', 'post', 'put', 'delete'] as $verb) {
			$http->method($verb)->willReturnCallback(
				fn (string $uri, array $options = []): IResponse => $this->answer(scenario: $scenario, call: ['call' => $verb, 'uri' => $uri, 'options' => $options])
			);
		}

		$http->method('request')->willReturnCallback(
			fn (string $method, string $uri, array $options = []): IResponse => $this->answer(scenario: $scenario, call: ['call' => 'request', 'method' => $method, 'uri' => $uri, 'options' => $options])
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'zgw.vault.zgw/test' ? self::SECRET : $default)
		);
		$timeout = ($scenario['timeout'] ?? null);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($timeout ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = ['message' => $message, 'context' => $context];
		});

		$client = new ZgwApiClient(clientService: $service, appConfig: $appConfig, logger: $logger);
		try {
			$outcome = ['result' => $client->callComponent(...$scenario['args'])];
		} catch (Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode(), 'previous' => ($e->getPrevious() === null ? null : get_class($e->getPrevious()))];
		}

		return [...$outcome, 'calls' => $this->calls, 'logged' => $this->logged];
	}//end runScenario()

	/**
	 * Record one HTTP call and answer it.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 * @param array<string, mixed> $call     The call, by parameter name.
	 *
	 * @return IResponse
	 */
	private function answer(array $scenario, array $call): IResponse {
		$auth = (string)($call['options']['headers']['Authorization'] ?? '');
		if (str_starts_with($auth, 'Bearer ') === true) {
			$jwt = substr($auth, 7);
			$parts = ZgwApiClient::inspectJwt($jwt);
			$parts['payload']['lifetime'] = ($parts['payload']['exp'] - $parts['payload']['iat']);
			unset($parts['payload']['exp'], $parts['payload']['iat']);
			$parts['verified'] = ZgwApiClient::verifyJwt($jwt, self::SECRET);
			$call['options']['headers']['Authorization'] = $parts;
		}

		$this->calls[] = $call;
		if (isset($scenario['error']) === true) {
			throw new ZgwApiClientCallGoldenFault($scenario['error']);
		}

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($scenario['response']['status']);
		$response->method('getBody')->willReturn($scenario['response']['body']);
		$response->method('getHeaders')->willReturn($scenario['response']['headers']);
		return $response;
	}//end answer()
}//end class

/**
 * A transport error that carries a response, as Guzzle's do.
 */
class ZgwApiClientCallGoldenFault extends Exception {
	/**
	 * The response, or null.
	 *
	 * @var object|null
	 */
	private ?object $response = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fault Code, message, and the response status and body.
	 */
	public function __construct(array $fault) {
		parent::__construct($fault['message'], $fault['code']);
		if ($fault['status'] !== null) {
			$this->response = new class($fault['status'], $fault['body']) {
				/**
				 * Constructor.
				 *
				 * @param int    $status Status.
				 * @param string $body   Body.
				 */
				public function __construct(private int $status, private string $body) {
				}

				/**
				 * The status.
				 *
				 * @return int
				 */
				public function getStatusCode(): int {
					return $this->status;
				}

				/**
				 * The body.
				 *
				 * @return string
				 */
				public function getBody(): string {
					return $this->body;
				}
			};
		}
	}//end __construct()

	/**
	 * The response, or null.
	 *
	 * @return object|null
	 */
	public function getResponse(): ?object {
		return $this->response;
	}//end getResponse()
}//end class
