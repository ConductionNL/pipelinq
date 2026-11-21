<?php

/**
 * Golden tests for HaalCentraalClient: the lookup through integriq's source
 * over the BRP leaf, and the failed lookup when the source cannot answer,
 * pinned call by call.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use ArrayObject;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every allowed and refused outcome of lookupPersoon().
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
 */
class HaalCentraalClientGoldenTest extends TestCase {
	/**
	 * The BSN asked for.
	 *
	 * @var string
	 */
	private const BSN = '123456782';

	/**
	 * Every HTTP call and log line, in order.
	 *
	 * @var ArrayObject<int, array<int, mixed>>
	 */
	private ArrayObject $calls;

	/**
	 * Set up the call log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new ArrayObject();
	}//end setUp()

	/**
	 * The leaf answers 200 with a person: the person comes back stamped with
	 * the leaf's meta, one success line is logged and nothing else is called.
	 *
	 * @return void
	 */
	public function testLeafPersonIsStampedFromItsMeta(): void {
		$leaf = ['results' => [$this->raw(bsn: '999999990'), $this->raw(bsn: self::BSN)], 'meta' => ['correlationId' => 42, 'durationMs' => '17', 'status' => 203]];
		$person = $this->client(leaf: [200, $leaf])->lookupPersoon(self::BSN);

		$this->assertSame('P' . self::BSN, $person['givenNames']);
		$this->assertSame('42', $person['_correlationId']);
		$this->assertSame(17, $person['_responseDurationMs']);
		$this->assertSame(203, $person['_responseStatus']);
		$this->assertSame(
			[
				['get', 'http://localhost/apps/openregister/api/integrations/brp/person?bsn=' . self::BSN, ['timeout' => 5, 'connect_timeout' => 2, 'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'], 'nextcloud' => ['allow_local_address' => true]]],
				['info', 'BRP OR leaf lookup succeeded', ['bsn' => $this->masked(), 'correlationId' => '42', 'duration_ms' => 17, 'source' => 'openregister-leaf']],
			],
			$this->calls->getArrayCopy()
		);
	}//end testLeafPersonIsStampedFromItsMeta()

	/**
	 * Without meta the stamps default; without a person carrying the BSN the
	 * first result is read.
	 *
	 * @return void
	 */
	public function testLeafWithoutMetaDefaultsTheStamps(): void {
		$person = $this->client(leaf: [200, ['results' => [$this->raw(bsn: '999999990')], 'meta' => 'flat']])->lookupPersoon(self::BSN);

		$this->assertSame('P999999990', $person['givenNames']);
		$this->assertNull($person['_correlationId']);
		$this->assertSame(0, $person['_responseDurationMs']);
		$this->assertSame(200, $person['_responseStatus']);
	}//end testLeafWithoutMetaDefaultsTheStamps()

	/**
	 * The leaf answers 200 without a person: not found, no legacy call.
	 *
	 * @return void
	 */
	public function testLeafWithoutPersonIsNotFound(): void {
		$this->assertNull(actual: $this->client(leaf: [200, ['results' => []]])->lookupPersoon(self::BSN));
		$this->assertSame(['get'], array_column($this->calls->getArrayCopy(), 0));
	}//end testLeafWithoutPersonIsNotFound()

	/**
	 * Every unusable leaf answer fails the lookup with the message that names
	 * integriq; nothing is posted to Haal Centraal.
	 *
	 * @param array{0: int, 1: mixed}|null $leaf The leaf answer, null to throw.
	 *
	 * @return void
	 *
	 * @dataProvider unusableLeafProvider
	 */
	public function testUnusableLeafFailsTheLookup(?array $leaf): void {
		try {
			$this->client(leaf: $leaf)->lookupPersoon(self::BSN, 'req-7');
			$this->fail(message: 'a lookup without a working source must fail');
		} catch (HaalCentraalException $e) {
			$this->assertSame(expected: HaalCentraalClient::NO_SOURCE_MESSAGE, actual: $e->getMessage());
			$this->assertSame(503, $e->getCode());
		}

		$expected = [['get']];
		if ($leaf === null) {
			$expected[] = ['debug', 'BRP leaf unavailable', ['bsn' => $this->masked(), 'error' => 'leaf down']];
		}

		$expected[] = ['warning', 'BRP lookup failed: no working integriq source', ['bsn' => $this->masked(), 'requestId' => 'req-7']];

		$calls = array_map(static fn (array $call): array => $call[0] === 'get' ? ['get'] : $call, $this->calls->getArrayCopy());
		$this->assertSame($expected, $calls);
	}//end testUnusableLeafFailsTheLookup()

	/**
	 * The unusable leaf answers.
	 *
	 * @return array<string, array{0: array{0: int, 1: mixed}|null}>
	 */
	public static function unusableLeafProvider(): array {
		return [
			'leaf throws' => [null],
			'not 200' => [[503, ['results' => [['burgerservicenummer' => self::BSN]]]]],
			'201' => [[201, ['results' => []]]],
			'not json' => [[200, 'not json']],
			'no results' => [[200, ['total' => 0]]],
			'results not a list' => [[200, ['results' => 'x']]],
			'first result not a person' => [[200, ['results' => ['x']]]],
		];
	}//end unusableLeafProvider()

	/**
	 * The masked BSN the client logs.
	 *
	 * @return string
	 */
	private function masked(): string {
		return BsnValidationService::mask(self::BSN);
	}//end masked()

	/**
	 * A raw HaalCentraal person.
	 *
	 * @param string $bsn The person's BSN.
	 *
	 * @return array<string, mixed>
	 */
	private function raw(string $bsn): array {
		return ['burgerservicenummer' => $bsn, 'name' => ['givenNames' => 'P' . $bsn, 'surname' => 'Jansen'], 'gender' => ['code' => 'M']];
	}//end raw()

	/**
	 * A response double.
	 *
	 * @param array{0: int, 1: mixed, 2?: array<string, mixed>} $answer Status, body (encoded unless a string) and headers.
	 *
	 * @return IResponse
	 */
	private function response(array $answer): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($answer[0]);
		$body = $answer[1];
		if (is_string($body) === false) {
			$body = (string)json_encode($body);
		}

		$response->method('getBody')->willReturn($body);
		$headers = ($answer[2] ?? []);
		$response->method('getHeader')->willReturnCallback(
			static fn (string $name): mixed => ($headers[$name] ?? '')
		);
		return $response;
	}//end response()

	/**
	 * Build the client over recording doubles.
	 *
	 * @param array{0: int, 1: mixed}|null $leaf The leaf answer, null to throw.
	 *
	 * @return HaalCentraalClient
	 */
	private function client(?array $leaf): HaalCentraalClient {
		$calls = $this->calls;
		$answers = [
			'leaf' => $leaf === null ? null : $this->response(answer: $leaf),
		];
		$http = new class($calls, $answers) implements IClient {
			public function __construct(
				private ArrayObject $calls,
				private array $answers,
			) {
			}

			public function setDefaultOptions(array $options): void {
				$this->calls[] = ['defaults', $options];
			}

			public function get(string $uri, array $options = []): IResponse {
				$this->calls[] = ['get', $uri, $options];
				return $this->answer(key: 'leaf', error: 'leaf down');
			}

			public function post(string $uri, array $options = []): IResponse {
				$this->calls[] = ['post', $uri, $options];
				throw new RuntimeException('no direct call to Haal Centraal');
			}

			private function answer(string $key, string $error): IResponse {
				if ($this->answers[$key] === null) {
					throw new RuntimeException($error);
				}

				return $this->answers[$key];
			}

			public function head(string $uri, array $options = []): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function put(string $uri, array $options = []): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function patch(string $uri, array $options = []): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function delete(string $uri, array $options = []): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function options(string $uri, array $options = []): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function getResponseFromThrowable(Throwable $e): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function request(string $method, string $uri, array $options = []): IResponse {
				throw new RuntimeException('unexpected');
			}

			public function getAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise {
				throw new RuntimeException('unexpected');
			}

			public function headAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise {
				throw new RuntimeException('unexpected');
			}

			public function postAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise {
				throw new RuntimeException('unexpected');
			}

			public function putAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise {
				throw new RuntimeException('unexpected');
			}

			public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
				throw new RuntimeException('unexpected');
			}

			public function deleteAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise {
				throw new RuntimeException('unexpected');
			}

			public function optionsAsync(string $uri, array $options = []): \OCP\Http\Client\IPromise {
				throw new RuntimeException('unexpected');
			}
		};

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($http);

		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'http://localhost' . $path
		);

		return new HaalCentraalClient(clientService: $clientService, logger: $logger, urlGenerator: $urlGenerator, appManager: $appManager);
	}//end client()
}//end class
