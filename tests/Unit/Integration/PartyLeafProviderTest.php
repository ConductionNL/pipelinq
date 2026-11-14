<?php

/**
 * Unit tests for PartyLeafProvider.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Integration;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Integration\PartyLeafProvider;
use OCA\Pipelinq\Service\PartyIndicatorService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the party panel a host app renders.
 */
class PartyLeafProviderTest extends TestCase {
	/**
	 * The parties the double holds, keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $parties = [];

	/**
	 * The declared field sets the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $fieldSets = [];

	/**
	 * The declared indicators the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $vocabulary = [];

	/**
	 * The stored indicator values the double answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $values = [];

	/**
	 * Build the provider over doubles, with a REAL indicator service.
	 *
	 * The indicator service is not doubled: the panel's whole job is to carry
	 * what it resolves, and a double would let the panel claim indicators the
	 * real service never produces.
	 *
	 * @return PartyLeafProvider The provider under test.
	 */
	private function provider(): PartyLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'partyFieldSet_schema' => 'sch-fields',
					'partyIndicator_schema' => 'sch-ind',
					'partyIndicatorValue_schema' => 'sch-val',
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				$data = ($this->parties[(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($data);

				return $entity;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				return match (($config['filters']['schema'] ?? '')) {
					'sch-fields' => $this->fieldSets,
					'sch-ind' => $this->vocabulary,
					'sch-val' => $this->values,
					default => [],
				};
			}
		);

		$indicatorService = new PartyIndicatorService(
			$appConfig,
			$objectService,
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
		);

		return new PartyLeafProvider(
			$appConfig,
			$objectService,
			$indicatorService,
			$this->createMock(LoggerInterface::class),
		);
	}//end provider()

	/**
	 * The panel carries the declared fields, their values and the indicators.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md#requirement-a-consuming-app-shall-read-party-fields-and-indicators-through-a-leaf-req-pfi-007
	 */
	public function testThePanelCarriesFieldsAndIndicators(): void {
		$this->parties = [
			'p1' => [
				'id' => 'p1',
				'partyKind' => 'organisatie',
				'fieldValues' => ['vestigingsnummer' => '000012345678'],
			],
		];
		$this->fieldSets = [
			['key' => 'vestigingsnummer', 'label' => 'Vestigingsnummer', 'fieldKind' => 'text', 'order' => 1],
			['key' => 'sbiCode', 'label' => 'SBI-code', 'fieldKind' => 'text', 'order' => 2],
		];
		$this->vocabulary = [
			['code' => 'agressie-registratie', 'label' => 'Agressie-registratie', 'severity' => 'warning'],
		];
		$this->values = [
			['id' => 'v1', 'party' => 'p1', 'indicator' => 'agressie-registratie', 'validFrom' => '2026-01-01'],
		];

		$panel = $this->provider()->describe(partyId: 'p1');

		$this->assertSame(200, $panel['status']);
		$this->assertSame(['vestigingsnummer', 'sbiCode'], array_column($panel['fields'], 'key'));
		$this->assertSame('000012345678', $panel['fields'][0]['value']);
		$this->assertNull($panel['fields'][1]['value'], 'An unset field is unset, not dressed up as filled in.');
		$this->assertSame('agressie-registratie', $panel['indicators'][0]['code']);
	}//end testThePanelCarriesFieldsAndIndicators()

	/**
	 * The declared order is the order, and is not re-sorted for display.
	 *
	 * @return void
	 */
	public function testTheDeclaredOrderIsKept(): void {
		$this->parties = ['p1' => ['id' => 'p1', 'partyKind' => 'organisatie']];
		$this->fieldSets = [
			['key' => 'tweede', 'label' => 'Tweede', 'fieldKind' => 'text', 'order' => 2],
			['key' => 'eerste', 'label' => 'Eerste', 'fieldKind' => 'text', 'order' => 1],
		];

		$panel = $this->provider()->describe(partyId: 'p1');

		$this->assertSame(['eerste', 'tweede'], array_column($panel['fields'], 'key'));
	}//end testTheDeclaredOrderIsKept()

	/**
	 * A caller who may not read the party is refused.
	 *
	 * The least privileged principal that should be refused: a signed-in user
	 * for whom the party does not resolve.
	 *
	 * @return void
	 */
	public function testACallerWhoMayNotReadThePartyIsRefused(): void {
		$this->parties = [];

		$panel = $this->provider()->describe(partyId: 'p1');

		$this->assertSame(403, $panel['status']);
		$this->assertArrayNotHasKey('fields', $panel);
		$this->assertArrayNotHasKey('indicators', $panel);
	}//end testACallerWhoMayNotReadThePartyIsRefused()

	/**
	 * An unprovisioned instance renders a party without fields, not an error.
	 *
	 * @return void
	 */
	public function testAPartyWithNoDeclaredFieldsStillRenders(): void {
		$this->parties = ['p1' => ['id' => 'p1', 'partyKind' => 'organisatie']];
		$this->fieldSets = [];

		$panel = $this->provider()->describe(partyId: 'p1');

		$this->assertSame(200, $panel['status']);
		$this->assertSame([], $panel['fields']);
	}//end testAPartyWithNoDeclaredFieldsStillRenders()

	/**
	 * Golden: the exact panel — every field default, a skipped keyless field, values that are not a map —
	 * and the refusals.
	 *
	 * @return void
	 */
	public function testThePanelIsExact(): void {
		$this->parties = [
			'p2' => ['id' => 'p2', 'type' => 'persoon', 'fieldValues' => 'not-a-map', 'parentOrganisation' => 'org-1'],
			'p3' => ['id' => 'p3', 'partyKind' => ' organisatie ', 'fieldValues' => ['kvk' => '123']],
		];
		$this->fieldSets = [
			['key' => 'kvk', 'label' => 'KvK', 'fieldKind' => 'number', 'required' => 1, 'order' => '2', 'choices' => 'one'],
			['key' => '', 'label' => 'Keyless'],
			['key' => 'bsn'],
			new class implements \JsonSerializable {
				/**
				 * @return array<string, mixed> A field.
				 */
				public function jsonSerialize(): array {
					return ['key' => 'note', 'order' => 1];
				}
			},
		];

		$this->assertSame(
			[
				'status' => 200,
				'party' => ['id' => 'p2', 'kind' => 'persoon', 'parentOrganisation' => 'org-1', 'organisationPath' => ''],
				'fields' => [
					['key' => 'bsn', 'label' => 'bsn', 'fieldKind' => 'text', 'required' => false, 'order' => 0, 'choices' => [], 'value' => null],
					['key' => 'note', 'label' => 'note', 'fieldKind' => 'text', 'required' => false, 'order' => 1, 'choices' => [], 'value' => null],
					['key' => 'kvk', 'label' => 'KvK', 'fieldKind' => 'number', 'required' => true, 'order' => 2, 'choices' => ['one'], 'value' => null],
				],
				'indicators' => [],
			],
			$this->provider()->describe(partyId: ' p2 ')
		);
		$this->assertSame('123', $this->provider()->describe(partyId: 'p3')['fields'][2]['value']);
		$this->assertSame('organisatie', $this->provider()->describe(partyId: 'p3')['party']['kind']);
		$this->assertSame(['status' => 400, 'error' => 'A party is required.'], $this->provider()->describe(partyId: '  '));
		$this->assertSame(['status' => 403, 'error' => 'You may not read this party.'], $this->provider()->describe(partyId: 'ghost'));
	}//end testThePanelIsExact()

	/**
	 * Golden: the field-set query per kind, and a failing read that still renders the panel.
	 *
	 * @return void
	 */
	public function testTheFieldSetQueryIsExact(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg-1', 'partyFieldSet_schema' => 'sch-fields'][$key] ?? $default)
		);
		$queries = [];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$queries): array {
				$queries[] = $config;
				if (($config['filters']['partyKind'] ?? '') === 'broken') {
					throw new \RuntimeException('register offline');
				}

				return [];
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$indicators = $this->createMock(PartyIndicatorService::class);
		$indicators->method('resolve')->willReturn([]);
		$provider = new PartyLeafProvider($appConfig, $objectService, $indicators, $logger);
		$read = new \ReflectionMethod($provider, 'declaredFields');

		$this->assertSame([], $read->invoke($provider, ''));
		$this->assertSame([], $read->invoke($provider, 'organisatie'));
		$this->assertSame([], $read->invoke($provider, 'broken'));
		$this->assertSame(
			[
				['filters' => ['register' => 'reg-1', 'schema' => 'sch-fields'], 'limit' => 200],
				['filters' => ['register' => 'reg-1', 'schema' => 'sch-fields', 'partyKind' => 'organisatie'], 'limit' => 200],
				['filters' => ['register' => 'reg-1', 'schema' => 'sch-fields', 'partyKind' => 'broken'], 'limit' => 200],
			],
			$queries
		);
		$this->assertSame([['PartyLeafProvider: the field set read failed', ['kind' => 'broken', 'exception' => 'register offline']]], $warnings);
	}//end testTheFieldSetQueryIsExact()
}//end class
