<?php

/**
 * Unit tests for HaalCentraalClient (config + cert helpers + OR-first re-point).
 *
 * The full legacy lookup/token-grant transport requires an IClient mock that
 * returns configurable IResponse instances; the OAuth-unconfigured + cert paths
 * are covered here, plus the OR-first BRP leaf re-point: OR-200 (with meta →
 * audit fields), OR-200 zero-results (not-found), OR-503/absent fallback to the
 * legacy path, and the BSN-never-logged invariant on both paths.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#9.2
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

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

/**
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003
 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
 */
class HaalCentraalClientTest extends TestCase {
	/**
	 * A formally-valid demo BSN (passes the 11-proef) used across tests.
	 *
	 * @var string
	 */
	private const DEMO_BSN = '123456782';

	/**
	 * isConfigured returns false when any required key is missing.
	 *
	 * @return void
	 */
	public function testIsConfiguredFalseWhenUnset(): void {
		$client = $this->buildClient(values: []);
		self::assertFalse($client->isConfigured());
	}//end testIsConfiguredFalseWhenUnset()

	/**
	 * isConfigured returns true when all four required keys are set.
	 *
	 * @return void
	 */
	public function testIsConfiguredTrueWhenAllSet(): void {
		$client = $this->buildClient(
			values: [
				'brp.client_id' => 'demo-client',
				'brp.client_secret_encrypted' => 'cipher',
				'brp.cert_path' => '/etc/brp/cert.pem',
				'brp.key_path' => '/etc/brp/key.pem',
			]
		);
		self::assertTrue($client->isConfigured());
	}//end testIsConfiguredTrueWhenAllSet()

	/**
	 * Cert expiry returns null when cert path is unset.
	 *
	 * @return void
	 */
	public function testGetCertificateExpiryReturnsNullWhenUnset(): void {
		$client = $this->buildClient(values: []);
		self::assertNull($client->getCertificateExpiry());
	}//end testGetCertificateExpiryReturnsNullWhenUnset()

	/**
	 * Lookup throws a HaalCentraalException with 503 when OAuth credentials are
	 * missing AND the OR leaf is unavailable (legacy fallback hits the missing
	 * OAuth config). Proves the safe-partial fallback still reaches the legacy
	 * path when OR is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003-03
	 */
	public function testLookupThrowsWhenUnconfigured(): void {
		// OR leaf throws (absent) → falls back to legacy → OAuth unconfigured → throws.
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willThrowException(new \RuntimeException('OR absent'));

		$client = $this->buildClient(values: [], httpClient: $httpClient);
		$this->expectException(HaalCentraalException::class);
		$client->lookupPersoon(self::DEMO_BSN);
	}//end testLookupThrowsWhenUnconfigured()

	/**
	 * OR-first happy path: when the OpenRegister BRP leaf returns 200 with a
	 * raw HAL+JSON person and `meta`, lookupPersoon maps the person through
	 * normalisePerson AND attaches the Wet-BRP audit fields from meta —
	 * `_correlationId`/`_responseDurationMs`/`_responseStatus` — exactly the
	 * shape the BrpController persists into `brpLookupVerzoek`. The legacy
	 * OAuth/mTLS path is NOT touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testLookupUsesOpenRegisterLeafWithMeta(): void {
		$orUrl = null;
		$legacyHit = false;

		$rawPerson = $this->sampleRawPerson();
		$leafBody = json_encode(
			[
				'results' => [$rawPerson],
				'total' => 1,
				'meta' => [
					'correlationId' => 'corr-or-abc-123',
					'durationMs' => 87,
					'status' => 200,
				],
			]
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($leafBody);

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturnCallback(
			static function (string $uri, array $options = []) use (&$orUrl, &$legacyHit, $response): IResponse {
				if (str_contains($uri, 'integrations/brp/person') === true) {
					$orUrl = $uri;
					return $response;
				}

				$legacyHit = true;
				throw new \RuntimeException('legacy path must not be hit when OR returns 200');
			}
		);
		// Guard: legacy path POSTs (token + personen) — those must never run here.
		$httpClient->method('post')->willReturnCallback(
			static function () use (&$legacyHit): IResponse {
				$legacyHit = true;
				throw new \RuntimeException('legacy POST must not run when OR returns 200');
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$client = $this->buildClient(values: [], httpClient: $httpClient, logger: $logger);

		$person = $client->lookupPersoon(self::DEMO_BSN);

		self::assertFalse($legacyHit, 'legacy HaalCentraal transport must not run when OR returns 200');
		self::assertNotNull($orUrl, 'OR BRP leaf endpoint was not called');
		self::assertStringContainsString('integrations/brp/person', (string)$orUrl);
		self::assertIsArray($person);

		// The audit fields the controller persists into brpLookupVerzoek.
		self::assertSame('corr-or-abc-123', $person['_correlationId'], 'haalcentraalCorrelationId source');
		self::assertSame(87, $person['_responseDurationMs'], 'responseDuurMs source');
		self::assertSame(200, $person['_responseStatus'], 'responseStatus source');

		// The normalised person body is identical to what normalisePerson()
		// yields for the same raw upstream data (the mapping is unchanged).
		self::assertSame('Jan', $person['givenNames']);
		self::assertSame('Jansen', $person['surname']);
		self::assertSame('man', $person['geslacht']);
		self::assertSame('1990-01-01', $person['dateOfBirth']);
		self::assertSame('HaalCentraal-BRP-v2.0', $person['bronsysteem']);
		self::assertSame('Hoofdstraat', $person['residence']['straat']);
	}//end testLookupUsesOpenRegisterLeafWithMeta()

	/**
	 * OR-first + null meta correlation: the leaf may relay a null correlationId
	 * (no upstream X-Correlation-ID header). The audit field must be null —
	 * exactly as the legacy path records null when the header is absent —
	 * never an empty string or a synthesised value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testLookupOpenRegisterLeafNullCorrelationId(): void {
		$leafBody = json_encode(
			[
				'results' => [$this->sampleRawPerson()],
				'total' => 1,
				'meta' => ['correlationId' => null, 'durationMs' => 12, 'status' => 200],
			]
		);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($leafBody);

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);

		$client = $this->buildClient(values: [], httpClient: $httpClient);
		$person = $client->lookupPersoon(self::DEMO_BSN);

		self::assertIsArray($person);
		self::assertNull($person['_correlationId'], 'null correlationId must persist as null');
		self::assertSame(12, $person['_responseDurationMs']);
		self::assertSame(200, $person['_responseStatus']);
	}//end testLookupOpenRegisterLeafNullCorrelationId()

	/**
	 * OR-first not-found: a 200 with an empty `results` array means the BSN is
	 * not in the BRP. lookupPersoon must return null (legacy not-found
	 * semantics) WITHOUT falling through to the legacy direct call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testLookupOpenRegisterLeafEmptyResultsIsNotFound(): void {
		$legacyHit = false;

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['results' => [], 'total' => 0, 'meta' => []]));

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);
		$httpClient->method('post')->willReturnCallback(
			static function () use (&$legacyHit): IResponse {
				$legacyHit = true;
				throw new \RuntimeException('legacy POST must not run when OR returns 200 empty');
			}
		);

		$client = $this->buildClient(values: [], httpClient: $httpClient);
		$result = $client->lookupPersoon(self::DEMO_BSN);

		self::assertNull($result, 'empty OR results must map to not-found (null)');
		self::assertFalse($legacyHit, 'legacy path must not run for an OR-200 empty result');
	}//end testLookupOpenRegisterLeafEmptyResultsIsNotFound()

	/**
	 * OR-first safe-partial fallback: when the OR leaf is unavailable (503 /
	 * connection refused / absent surfaces as a client exception on get()),
	 * lookupPersoon falls back to the legacy OAuth2 + mTLS direct path. Here we
	 * leave the legacy OAuth config empty so the fallback provably reaches the
	 * legacy code (which then throws the unconfigured 503) — proving the legacy
	 * leg runs unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testLookupFallsBackToLegacyWhenLeafUnavailable(): void {
		$legacyReached = false;

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturnCallback(
			static function (string $uri, array $options = []): IResponse {
				if (str_contains($uri, 'integrations/brp/person') === true) {
					throw new \RuntimeException('OR source unavailable (503)');
				}

				throw new \RuntimeException('unexpected GET');
			}
		);
		// The legacy path's first network action is the OAuth token POST — but
		// it is gated by the unconfigured-credentials check, which throws before
		// any POST. We assert the legacy leg was entered via the exception type.
		$httpClient->method('post')->willReturnCallback(
			static function () use (&$legacyReached): IResponse {
				$legacyReached = true;
				throw new \RuntimeException('should not reach POST when unconfigured');
			}
		);

		$client = $this->buildClient(values: [], httpClient: $httpClient);

		try {
			$client->lookupPersoon(self::DEMO_BSN);
			self::fail('expected HaalCentraalException from the legacy fallback path');
		} catch (HaalCentraalException $e) {
			// Legacy path reached: unconfigured OAuth → 503 (the legacy contract).
			self::assertSame(503, $e->getStatusCode(), 'legacy fallback must surface its own 503');
		}

		self::assertFalse($legacyReached, 'unconfigured legacy path throws before any POST (unchanged behaviour)');
	}//end testLookupFallsBackToLegacyWhenLeafUnavailable()

	/**
	 * The raw BSN must NEVER appear in any log message/context on the OR path.
	 * Inspect every logger call's message + context recursively for the raw
	 * BSN; only the masked form may appear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testRawBsnNeverLoggedOnOpenRegisterPath(): void {
		$leafBody = json_encode(
			[
				'results' => [$this->sampleRawPerson()],
				'total' => 1,
				'meta' => ['correlationId' => 'c1', 'durationMs' => 5, 'status' => 200],
			]
		);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn($leafBody);

		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);

		$captured = [];
		$logger = $this->createMock(LoggerInterface::class);
		$recorder = static function (string $message, array $context = []) use (&$captured): void {
			$captured[] = [$message, $context];
		};
		$logger->method('debug')->willReturnCallback($recorder);
		$logger->method('info')->willReturnCallback($recorder);
		$logger->method('warning')->willReturnCallback($recorder);
		$logger->method('error')->willReturnCallback($recorder);

		$client = $this->buildClient(values: [], httpClient: $httpClient, logger: $logger);
		$client->lookupPersoon(self::DEMO_BSN);

		foreach ($captured as [$message, $context]) {
			self::assertStringNotContainsString(self::DEMO_BSN, $message, 'raw BSN leaked into log message');
			self::assertStringNotContainsString(self::DEMO_BSN, json_encode($context), 'raw BSN leaked into log context');
		}
	}//end testRawBsnNeverLoggedOnOpenRegisterPath()

	/**
	 * The raw BSN must NEVER appear in any log when the OR leaf fails and the
	 * legacy fallback is taken (debug "falling back" line uses the masked BSN).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testRawBsnNeverLoggedOnFallbackPath(): void {
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willThrowException(new \RuntimeException('OR down'));

		$captured = [];
		$logger = $this->createMock(LoggerInterface::class);
		$recorder = static function (string $message, array $context = []) use (&$captured): void {
			$captured[] = [$message, $context];
		};
		$logger->method('debug')->willReturnCallback($recorder);
		$logger->method('info')->willReturnCallback($recorder);
		$logger->method('warning')->willReturnCallback($recorder);
		$logger->method('error')->willReturnCallback($recorder);

		$client = $this->buildClient(values: [], httpClient: $httpClient, logger: $logger);

		try {
			$client->lookupPersoon(self::DEMO_BSN);
		} catch (HaalCentraalException $e) {
			// Expected — unconfigured legacy fallback.
			unset($e);
		}

		self::assertNotEmpty($captured, 'expected at least the fallback debug log line');
		foreach ($captured as [$message, $context]) {
			self::assertStringNotContainsString(self::DEMO_BSN, $message, 'raw BSN leaked into log message');
			self::assertStringNotContainsString(self::DEMO_BSN, json_encode($context), 'raw BSN leaked into log context');
		}
	}//end testRawBsnNeverLoggedOnFallbackPath()

	/**
	 * A representative raw HaalCentraal HAL+JSON person, as the OR leaf relays
	 * it under `results[0]`. Mirrors the legacy upstream shape so both paths
	 * feed identical data through normalisePerson().
	 *
	 * @return array<string,mixed>
	 */
	private function sampleRawPerson(): array {
		return [
			'burgerservicenummer' => '999999990',
			'name' => [
				'givenNames' => 'Jan',
				'initials' => 'J.',
				'surname' => 'Jansen',
			],
			'geboorte' => [
				'datum' => ['datum' => '1990-01-01'],
				'plaats' => ['omschrijving' => 'Utrecht'],
				'land' => ['code' => '6030'],
			],
			'geslacht' => ['code' => 'M'],
			'residence' => [
				'verblijfadres' => [
					'officieleStraatnaam' => 'Hoofdstraat',
					'huisnummer' => 12,
					'postcode' => '3500AA',
					'woonplaats' => 'Utrecht',
				],
			],
			'indicationGeheim' => '0',
		];
	}//end sampleRawPerson()

	/**
	 * Build a client with a stubbed IAppConfig that returns the given values.
	 *
	 * @param array<string,string> $values App-config key→value
	 *                                     map.
	 * @param IClient|null $httpClient Optional HTTP client mock.
	 * @param LoggerInterface|null $logger Optional logger mock.
	 *
	 * @return HaalCentraalClient
	 */
	private function buildClient(array $values, ?IClient $httpClient = null, ?LoggerInterface $logger = null): HaalCentraalClient {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig
			->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') use ($values) {
					return $values[$key] ?? $default;
				}
			);

		$clientService = $this->createMock(IClientService::class);
		if ($httpClient !== null) {
			$clientService->method('newClient')->willReturn($httpClient);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'http://localhost' . $path
		);

		return new HaalCentraalClient(
			$clientService,
			$appConfig,
			$this->createMock(ICrypto::class),
			($logger ?? $this->createMock(LoggerInterface::class)),
			$urlGenerator,
		);
	}//end buildClient()
}//end class
