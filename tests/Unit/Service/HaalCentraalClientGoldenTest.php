<?php

/**
 * Golden tests for HaalCentraalClient: the OpenRegister leaf path, the OAuth2
 * token exchange and the legacy direct lookup, pinned call by call.
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
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
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
	 * The fully configured legacy path.
	 *
	 * @var array<string, string>
	 */
	private const CONFIGURED = [
		'brp.client_id' => 'cid',
		'brp.client_secret_encrypted' => 'enc',
		'brp.oauth_endpoint' => 'https://oauth.test/token',
		'brp.base_url' => 'https://brp.test/v2/',
		'brp.cert_path' => '/certs/c.pem',
		'brp.key_path' => '/certs/k.pem',
	];

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
		$this->assertNull($this->client(leaf: [200, ['results' => []]], config: self::CONFIGURED)->lookupPersoon(self::BSN));
		$this->assertSame(['get'], array_column($this->calls->getArrayCopy(), 0));
	}//end testLeafWithoutPersonIsNotFound()

	/**
	 * Every unusable leaf answer falls back to the legacy path, which here is
	 * not configured and refuses.
	 *
	 * @param array{0: int, 1: mixed}|null $leaf The leaf answer, null to throw.
	 *
	 * @return void
	 *
	 * @dataProvider unusableLeafProvider
	 */
	public function testUnusableLeafFallsBackToLegacy(?array $leaf): void {
		try {
			$this->client(leaf: $leaf)->lookupPersoon(self::BSN);
			$this->fail('the unconfigured legacy path must refuse');
		} catch (HaalCentraalException $e) {
			$this->assertSame('BRP-integratie is niet geconfigureerd (OAuth2 client_id/secret ontbreken).', $e->getMessage());
			$this->assertSame(503, $e->getCode());
		}

		$expected = [['get']];
		if ($leaf === null) {
			$expected[] = ['debug', 'BRP OR leaf unavailable, falling back to legacy HaalCentraal path', ['bsn' => $this->masked(), 'error' => 'leaf down']];
		}

		$calls = array_map(static fn (array $call): array => $call[0] === 'get' ? ['get'] : $call, $this->calls->getArrayCopy());
		$this->assertSame($expected, $calls);
	}//end testUnusableLeafFallsBackToLegacy()

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
	 * Each refusal of the token exchange, with its message, code and cause.
	 *
	 * @param array<string, string>         $config   The app config.
	 * @param bool                          $decrypts Whether the secret decrypts.
	 * @param array{0: int, 1: mixed}|null  $token    The token answer, null to throw.
	 * @param string                        $message  The refusal message.
	 * @param int                           $code     The refusal code.
	 * @param bool                          $previous Whether the refusal carries a cause.
	 *
	 * @return void
	 *
	 * @dataProvider tokenRefusalProvider
	 */
	public function testTokenRefusals(array $config, bool $decrypts, ?array $token, string $message, int $code, bool $previous): void {
		try {
			$this->client(leaf: null, config: $config, token: $token, decrypts: $decrypts)->lookupPersoon(self::BSN);
			$this->fail('the token exchange must refuse');
		} catch (HaalCentraalException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame($code, $e->getCode());
			$this->assertSame($previous, $e->getPrevious() !== null);
			$this->assertNull($e->getCorrelationId());
		}

		$this->assertNotContains('/personen', array_column($this->calls->getArrayCopy(), 1));
	}//end testTokenRefusals()

	/**
	 * The token refusals.
	 *
	 * @return array<string, array{0: array<string, string>, 1: bool, 2: array{0: int, 1: mixed}|null, 3: string, 4: int, 5: bool}>
	 */
	public static function tokenRefusalProvider(): array {
		$noSecret = self::CONFIGURED;
		$noSecret['brp.client_secret_encrypted'] = '';
		$noId = self::CONFIGURED;
		$noId['brp.client_id'] = '';
		$failed = 'BRP OAuth2-token aanvraag is mislukt.';
		return [
			'no client id' => [$noId, true, [200, ['access_token' => 't']], 'BRP-integratie is niet geconfigureerd (OAuth2 client_id/secret ontbreken).', 503, false],
			'no secret' => [$noSecret, true, [200, ['access_token' => 't']], 'BRP-integratie is niet geconfigureerd (OAuth2 client_id/secret ontbreken).', 503, false],
			'secret unreadable' => [self::CONFIGURED, false, [200, ['access_token' => 't']], 'BRP-integratie configuratie is onleesbaar — controleer admin instellingen.', 503, true],
			'endpoint down' => [self::CONFIGURED, true, null, 'BRP OAuth2-endpoint is niet bereikbaar.', 0, true],
			'status 401' => [self::CONFIGURED, true, [401, ['access_token' => 't']], $failed, 401, false],
			'status 199' => [self::CONFIGURED, true, [199, ['access_token' => 't']], $failed, 199, false],
			'status 300' => [self::CONFIGURED, true, [300, ['access_token' => 't']], $failed, 300, false],
			'empty token' => [self::CONFIGURED, true, [200, ['access_token' => '']], $failed, 200, false],
			'no token' => [self::CONFIGURED, true, [200, ['token_type' => 'bearer']], $failed, 200, false],
			'not json' => [self::CONFIGURED, true, [204, 'nope'], $failed, 204, false],
		];
	}//end tokenRefusalProvider()

	/**
	 * The legacy lookup: one token exchange (cached for the second lookup),
	 * the mTLS options on the lookup client only, the exact requests and the
	 * stamped person.
	 *
	 * @return void
	 */
	public function testLegacyLookupSucceedsAndCachesTheToken(): void {
		$client = $this->client(
			leaf: null,
			config: self::CONFIGURED + ['brp.ca_bundle' => '/certs/ca.pem'],
			token: [200, ['access_token' => 'tok-1']],
			lookup: [200, ['_embedded' => ['personen' => [$this->raw(bsn: self::BSN)]]], ['x-correlation-id' => 'corr-9']],
		);

		$person = $client->lookupPersoon(self::BSN);
		$this->assertSame('P' . self::BSN, $person['givenNames']);
		$this->assertSame('corr-9', $person['_correlationId']);
		$this->assertSame(200, $person['_responseStatus']);
		$this->assertIsInt($person['_responseDurationMs']);

		$client->lookupPersoon(self::BSN);

		$calls = array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => in_array($call[0], ['post', 'defaults'], true)));
		$lookupOptions = [
			'headers' => [
				'Authorization' => 'Bearer tok-1',
				'Accept' => 'application/hal+json',
				'Content-Type' => 'application/json',
				'User-Agent' => 'Pipelinq/pipelinq (Nextcloud)',
			],
			'body' => '{"type":"RaadpleegMetBurgerservicenummer","burgerservicenummer":["' . self::BSN . '"],"fields":["burgerservicenummer","naam.voornamen","naam.voorletters","naam.voorvoegsel","naam.geslachtsnaam","naam.adellijkeTitelPredicaat","geboorte.datum","geboorte.plaats","geboorte.land","gender","residence","indicationSecret"]}',
			'timeout' => 5,
			'connect_timeout' => 2,
		];
		$mtls = ['defaults', ['cert' => '/certs/c.pem', 'ssl_key' => '/certs/k.pem', 'verify' => '/certs/ca.pem']];
		$this->assertSame(
			[
				[
					'post',
					'https://oauth.test/token',
					[
						'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
						'body' => 'grant_type=client_credentials&client_id=cid&client_secret=plain-secret&scope=haalcentraal.brp.personen',
						'timeout' => 5,
						'connect_timeout' => 2,
					],
				],
				$mtls,
				['post', 'https://brp.test/v2/personen', $lookupOptions],
				$mtls,
				['post', 'https://brp.test/v2/personen', $lookupOptions],
			],
			$calls
		);
	}//end testLegacyLookupSucceedsAndCachesTheToken()

	/**
	 * The legacy lookup's other answers: not found, no person, a refused
	 * status (with its correlation id), a broken body and a transport failure.
	 *
	 * @return void
	 */
	public function testLegacyLookupOutcomes(): void {
		$headers = ['X-Correlation-ID' => 'corr-1'];
		$this->assertNull($this->legacy(lookup: [404, [], $headers])->lookupPersoon(self::BSN));
		$this->assertSame(['info', 'HaalCentraal lookup not-found', ['bsn', 'correlationId', 'duration_ms']], $this->lastLog(keysOnly: true));
		$this->assertSame('corr-1', $this->lastLog(keysOnly: false)[2]['correlationId']);

		$this->assertNull($this->legacy(lookup: [200, ['personen' => []], $headers])->lookupPersoon(self::BSN));
		$this->assertNull($this->legacy(lookup: [200, ['personen' => ['x']], $headers])->lookupPersoon(self::BSN));
		$this->assertNull($this->legacy(lookup: [200, '"not a payload"', $headers])->lookupPersoon(self::BSN));

		foreach ([500, 302, 199] as $status) {
			try {
				$this->legacy(lookup: [$status, [], $headers])->lookupPersoon(self::BSN);
				$this->fail('a refused status must throw');
			} catch (HaalCentraalException $e) {
				$this->assertSame('BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.', $e->getMessage());
				$this->assertSame($status, $e->getCode());
				$this->assertSame('corr-1', $e->getCorrelationId());
				$this->assertNull($e->getPrevious());
				$this->assertSame(['warning', 'HaalCentraal lookup error', ['bsn' => $this->masked(), 'status' => $status, 'correlationId' => 'corr-1']], $this->lastLog(keysOnly: false));
			}
		}

		foreach ([null, [200, '{broken', []]] as $lookup) {
			try {
				$this->legacy(lookup: $lookup)->lookupPersoon(self::BSN, 'req-7');
				$this->fail('a transport failure must throw');
			} catch (HaalCentraalException $e) {
				$this->assertSame('BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.', $e->getMessage());
				$this->assertSame(0, $e->getCode());
				$this->assertNull($e->getCorrelationId());
				$this->assertInstanceOf(Throwable::class, $e->getPrevious());
				$log = $this->lastLog(keysOnly: false);
				$this->assertSame(['error', 'HaalCentraal transport error'], [$log[0], $log[1]]);
				$this->assertSame(['bsn', 'error', 'requestId'], array_keys($log[2]));
				$this->assertSame($this->masked(), $log[2]['bsn']);
				$this->assertSame('req-7', $log[2]['requestId']);
			}
		}
	}//end testLegacyLookupOutcomes()

	/**
	 * Without cert and key the lookup client gets no mTLS options, and
	 * without a CA bundle the peer is verified against the system store.
	 *
	 * @return void
	 */
	public function testMutualTlsOptions(): void {
		$config = self::CONFIGURED;
		$config['brp.key_path'] = '';
		$this->legacy(lookup: [404, [], []], config: $config)->lookupPersoon(self::BSN);
		$this->assertNotContains('defaults', array_column($this->calls->getArrayCopy(), 0));

		$this->calls = new ArrayObject();
		$this->legacy(lookup: [404, [], []])->lookupPersoon(self::BSN);
		$defaults = array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => $call[0] === 'defaults'));
		$this->assertSame([['defaults', ['cert' => '/certs/c.pem', 'ssl_key' => '/certs/k.pem', 'verify' => true]]], $defaults);
	}//end testMutualTlsOptions()

	/**
	 * A configured client whose leaf is down.
	 *
	 * @param array{0: int, 1: mixed, 2: array<string, mixed>}|null $lookup The lookup answer, null to throw.
	 * @param array<string, string>                                  $config The app config.
	 *
	 * @return HaalCentraalClient
	 */
	private function legacy(?array $lookup, array $config = self::CONFIGURED): HaalCentraalClient {
		return $this->client(leaf: null, config: $config, token: [200, ['access_token' => 'tok']], lookup: $lookup);
	}//end legacy()

	/**
	 * The last log line.
	 *
	 * @param bool $keysOnly Whether to reduce the context to its keys.
	 *
	 * @return array<int, mixed>
	 */
	private function lastLog(bool $keysOnly): array {
		$logs = array_values(array_filter($this->calls->getArrayCopy(), static fn (array $call): bool => in_array($call[0], ['debug', 'info', 'warning', 'error'], true)));
		$last = end($logs);
		if ($keysOnly === true) {
			$last[2] = array_keys($last[2]);
		}

		return $last;
	}//end lastLog()

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
	 * @param array{0: int, 1: mixed}|null                           $leaf     The leaf answer, null to throw.
	 * @param array<string, string>                                  $config   The app config.
	 * @param array{0: int, 1: mixed}|null                           $token    The token answer, null to throw.
	 * @param array{0: int, 1: mixed, 2: array<string, mixed>}|null  $lookup   The lookup answer, null to throw.
	 * @param bool                                                   $decrypts Whether the secret decrypts.
	 *
	 * @return HaalCentraalClient
	 */
	private function client(?array $leaf, array $config = [], ?array $token = null, ?array $lookup = null, bool $decrypts = true): HaalCentraalClient {
		$calls = $this->calls;
		$answers = [
			'leaf' => $leaf === null ? null : $this->response(answer: $leaf),
			'token' => $token === null ? null : $this->response(answer: $token),
			'lookup' => $lookup === null ? null : $this->response(answer: $lookup),
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
				if (str_ends_with($uri, '/personen') === true) {
					return $this->answer(key: 'lookup', error: 'lookup down');
				}

				return $this->answer(key: 'token', error: 'token down');
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$crypto = $this->createMock(ICrypto::class);
		if ($decrypts === true) {
			$crypto->method('decrypt')->willReturn('plain-secret');
		} else {
			$crypto->method('decrypt')->willThrowException(new RuntimeException('bad key'));
		}

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

		return new HaalCentraalClient($clientService, $appConfig, $crypto, $logger, $urlGenerator);
	}//end client()
}//end class
