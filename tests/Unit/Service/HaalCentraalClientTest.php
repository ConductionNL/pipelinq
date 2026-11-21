<?php

/**
 * Unit tests for HaalCentraalClient: the lookup through integriq's source.
 *
 * A 200 with meta (the audit fields), a 200 with zero results (not found), a
 * failed lookup without integriq or when the source does not answer (no
 * direct call to Haal Centraal), and the BSN-never-logged invariant.
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

use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
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
	 * Without integriq no BRP lookup can be made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 */
	public function testIsConfiguredFalseWithoutIntegriq(): void {
		self::assertFalse($this->buildClient(integriq: false)->isConfigured());
	}//end testIsConfiguredFalseWithoutIntegriq()

	/**
	 * With integriq installed a lookup can be made; pipelinq holds no connection keys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 */
	public function testIsConfiguredTrueWithIntegriq(): void {
		self::assertTrue($this->buildClient()->isConfigured());
	}//end testIsConfiguredTrueWithIntegriq()

	/**
	 * Without integriq the lookup fails with the message that names integriq,
	 * and nothing is called: no leaf, no direct Haal Centraal call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#scenario-without-integriq-a-lookup-fails-and-says-why
	 */
	public function testLookupFailsWithoutIntegriqAndCallsNothing(): void {
		$httpClient = $this->createMock(IClient::class);
		$httpClient->expects(self::never())->method('get');
		$httpClient->expects(self::never())->method('post');

		$client = $this->buildClient(httpClient: $httpClient, integriq: false);

		try {
			$client->lookupPersoon(self::DEMO_BSN);
			self::fail('expected HaalCentraalException without integriq');
		} catch (HaalCentraalException $e) {
			self::assertSame(HaalCentraalClient::NO_SOURCE_MESSAGE, $e->getMessage());
			self::assertSame(HaalCentraalClient::NO_SOURCE_STATUS, $e->getStatusCode());
		}
	}//end testLookupFailsWithoutIntegriqAndCallsNothing()

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
		$client = $this->buildClient(httpClient: $httpClient, logger: $logger);

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
		self::assertSame('male', $person['gender']);
		self::assertSame('1990-01-01', $person['dateOfBirth']);
		self::assertSame('HaalCentraal-BRP-v2.0', $person['sourceSystem']);
		self::assertSame('Hoofdstraat', $person['residence']['straat']);
	}//end testLookupUsesOpenRegisterLeafWithMeta()

	/**
	 * A source in mock mode answers all its people to any BSN: the lookup
	 * reads the person whose BSN it asked for (integriq's mock knows the
	 * Zuiddrecht residents after its first test person).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/brp-lookup/spec.md#requirement-the-lookup-reads-the-person-it-asked-for
	 */
	public function testLookupReadsThePersonWhoseBsnItAskedFor(): void {
		$other = $this->sampleRawPerson();
		$asked = $this->sampleRawPerson();
		$asked['burgerservicenummer'] = self::DEMO_BSN;
		$asked['name']['surname'] = 'de Vries';
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['results' => [$other, $asked], 'total' => 2]));
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);

		$person = $this->buildClient(httpClient: $httpClient, logger: $this->createMock(LoggerInterface::class))->lookupPersoon(self::DEMO_BSN);

		self::assertSame('de Vries', $person['surname']);
	}//end testLookupReadsThePersonWhoseBsnItAskedFor()

	/**
	 * HaalCentraal Personen v2 spells the name in Dutch (`naam`); the lookup
	 * reads it as it reads the English shape.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/brp-lookup/spec.md#requirement-the-lookup-reads-the-person-it-asked-for
	 */
	public function testLookupReadsTheDutchNameShape(): void {
		$raw = $this->sampleRawPerson();
		unset($raw['name']);
		$raw['naam'] = ['voornamen' => 'Sanne', 'voorletters' => 'S.', 'voorvoegsel' => 'de', 'geslachtsnaam' => 'Vries'];
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode(['results' => [$raw], 'total' => 1]));
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);

		$person = $this->buildClient(httpClient: $httpClient, logger: $this->createMock(LoggerInterface::class))->lookupPersoon(self::DEMO_BSN);

		self::assertSame(['Sanne', 'de', 'Vries'], [$person['givenNames'], $person['namePrefix'], $person['surname']]);
	}//end testLookupReadsTheDutchNameShape()

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

		$client = $this->buildClient(httpClient: $httpClient);
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

		$client = $this->buildClient(httpClient: $httpClient);
		$result = $client->lookupPersoon(self::DEMO_BSN);

		self::assertNull($result, 'empty OR results must map to not-found (null)');
		self::assertFalse($legacyHit, 'legacy path must not run for an OR-200 empty result');
	}//end testLookupOpenRegisterLeafEmptyResultsIsNotFound()

	/**
	 * No fallback: when integriq's source does not answer (503, connection
	 * refused), the lookup fails with the message that names integriq and no
	 * direct call to Haal Centraal is made.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 */
	public function testLookupFailsWhenTheSourceDoesNotAnswer(): void {
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willThrowException(new \RuntimeException('source unavailable (503)'));
		$httpClient->expects(self::never())->method('post');

		$client = $this->buildClient(httpClient: $httpClient);

		try {
			$client->lookupPersoon(self::DEMO_BSN);
			self::fail('expected HaalCentraalException when the source does not answer');
		} catch (HaalCentraalException $e) {
			self::assertSame(HaalCentraalClient::NO_SOURCE_MESSAGE, $e->getMessage());
			self::assertSame(503, $e->getStatusCode());
		}
	}//end testLookupFailsWhenTheSourceDoesNotAnswer()

	/**
	 * A non-200 answer from the leaf fails the lookup the same way.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-brp-person-lookup-goes-through-integriq-only
	 */
	public function testLookupFailsOnANon200Answer(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(502);
		$httpClient = $this->createMock(IClient::class);
		$httpClient->method('get')->willReturn($response);

		$this->expectException(HaalCentraalException::class);
		$this->expectExceptionMessage(HaalCentraalClient::NO_SOURCE_MESSAGE);
		$this->buildClient(httpClient: $httpClient)->lookupPersoon(self::DEMO_BSN);
	}//end testLookupFailsOnANon200Answer()

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

		$client = $this->buildClient(httpClient: $httpClient, logger: $logger);
		$client->lookupPersoon(self::DEMO_BSN);

		foreach ($captured as [$message, $context]) {
			self::assertStringNotContainsString(self::DEMO_BSN, $message, 'raw BSN leaked into log message');
			self::assertStringNotContainsString(self::DEMO_BSN, json_encode($context), 'raw BSN leaked into log context');
		}
	}//end testRawBsnNeverLoggedOnOpenRegisterPath()

	/**
	 * The raw BSN must NEVER appear in any log when the source fails and the
	 * lookup is refused (the debug and warning lines use the masked BSN).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-brp-via-or-leaf/specs/brp-lookup/spec.md
	 */
	public function testRawBsnNeverLoggedOnAFailedLookup(): void {
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

		$client = $this->buildClient(httpClient: $httpClient, logger: $logger);

		try {
			$client->lookupPersoon(self::DEMO_BSN);
		} catch (HaalCentraalException $e) {
			// Expected: no working source.
			unset($e);
		}

		self::assertNotEmpty($captured, 'expected the failure log lines');
		foreach ($captured as [$message, $context]) {
			self::assertStringNotContainsString(self::DEMO_BSN, $message, 'raw BSN leaked into log message');
			self::assertStringNotContainsString(self::DEMO_BSN, json_encode($context), 'raw BSN leaked into log context');
		}
	}//end testRawBsnNeverLoggedOnAFailedLookup()

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
			'gender' => ['code' => 'M'],
			'residence' => [
				'verblijfadres' => [
					'officieleStraatnaam' => 'Hoofdstraat',
					'huisnummer' => 12,
					'postcode' => '3500AA',
					'woonplaats' => 'Utrecht',
				],
			],
			'indicationSecret' => '0',
		];
	}//end sampleRawPerson()

	/**
	 * Build a client over the given HTTP client, with or without integriq.
	 *
	 * @param IClient|null         $httpClient Optional HTTP client mock.
	 * @param LoggerInterface|null $logger     Optional logger mock.
	 * @param bool                 $integriq   Whether integriq is installed.
	 *
	 * @return HaalCentraalClient
	 */
	private function buildClient(?IClient $httpClient = null, ?LoggerInterface $logger = null, bool $integriq = true): HaalCentraalClient {
		$clientService = $this->createMock(IClientService::class);
		if ($httpClient !== null) {
			$clientService->method('newClient')->willReturn($httpClient);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'http://localhost' . $path
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => ($app === 'integriq' && $integriq === true)
		);

		return new HaalCentraalClient(
			clientService: $clientService,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			urlGenerator: $urlGenerator,
			appManager: $appManager,
		);
	}//end buildClient()
}//end class
