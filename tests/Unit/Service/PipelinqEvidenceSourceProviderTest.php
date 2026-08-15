<?php

/**
 * Unit tests for PipelinqEvidenceSourceProvider.
 *
 * Covers REQ-AVG-016: source id, enabled/disabled gating, subject matching
 * across client/contact/lead/request/contactmoment (own identity + FK links),
 * stable content hashes, and that harvesting never emits the subject id value.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PipelinqEvidenceSourceProvider;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for PipelinqEvidenceSourceProvider.
 */
final class PipelinqEvidenceSourceProviderTest extends TestCase {
	/**
	 * Schema-config-key => id map (provisioned).
	 *
	 * The `request` and `contactmoment` sources have no schema of their own since
	 * unify-ticket-supertype: both resolve to the unified `ticket` schema (26) and
	 * are narrowed by the `ticketType` discriminator.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_IDS = [
		'register' => '11',
		'client_schema' => '21',
		'contact_schema' => '22',
		'lead_schema' => '23',
		'ticket_schema' => '26',
	];

	/**
	 * Mocked app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mocked ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Rows served by findAll, keyed by schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * The provider under test.
	 *
	 * @var PipelinqEvidenceSourceProvider
	 */
	private PipelinqEvidenceSourceProvider $provider;

	/**
	 * Set up a provisioned provider over an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->store = [];

		$store = &$this->store;
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$store): array {
				$filters = (array)($config['filters'] ?? []);
				$schemaId = (string)($filters['schema'] ?? '');
				$rows = ($store[$schemaId] ?? []);

				// Honour the ticketType discriminator so the two ticket-backed
				// sources each see only their own subtype of the shared schema.
				if (isset($filters['ticketType']) === false) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static fn (array $row): bool => (string)($row['ticketType'] ?? '') === (string)$filters['ticketType']
					)
				);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->provider = new PipelinqEvidenceSourceProvider(
			appConfig: $this->appConfig,
			container: $container,
			logger: new NullLogger(),
			ticketService: new TicketService(
				appConfig: $this->appConfig,
				logger: new NullLogger(),
			objectService: $this->objectService,
		),
		);
	}//end setUp()

	/**
	 * Configure the app config as provisioned.
	 *
	 * @return void
	 */
	private function provision(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => self::SCHEMA_IDS[$key] ?? $default
		);
	}//end provision()

	/**
	 * The source id is the stable `pipelinq-crm`.
	 *
	 * @return void
	 */
	public function testSourceId(): void {
		self::assertSame('pipelinq-crm', $this->provider->getSourceId());
	}//end testSourceId()

	/**
	 * isEnabled is false when the register is not provisioned.
	 *
	 * @return void
	 */
	public function testDisabledWhenUnprovisioned(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		self::assertFalse($this->provider->isEnabled());
	}//end testDisabledWhenUnprovisioned()

	/**
	 * isEnabled is true when register + all subject schemas resolve.
	 *
	 * @return void
	 */
	public function testEnabledWhenProvisioned(): void {
		$this->provision();
		self::assertTrue($this->provider->isEnabled());
	}//end testEnabledWhenProvisioned()

	/**
	 * Harvest matches the subject's own client record and its FK-linked
	 * lead/request/contactmoment objects, and skips unrelated objects.
	 *
	 * The request + contactmoment sources both read the unified ticket schema (26)
	 * and are separated only by the `ticketType` discriminator; a subtype that is
	 * not a harvested source (complaint) is never collected even when it FK-links
	 * to the subject.
	 *
	 * @return void
	 */
	public function testHarvestMatchesSubjectAcrossSchemas(): void {
		$this->provision();

		$this->store = [
			// client 21: own identity match on id.
			'21' => [
				['id' => 'subject-1', 'name' => 'Jane'],
				['id' => 'other-client', 'name' => 'Bob'],
			],
			// contact 22: match on contactsUid, plus an FK to the client.
			'22' => [
				['id' => 'contact-1', 'contactsUid' => 'subject-1', 'client' => 'subject-1'],
				['id' => 'contact-x', 'contactsUid' => 'nope', 'client' => 'other-client'],
			],
			// lead 23: FK client match.
			'23' => [
				['id' => 'lead-1', 'client' => 'subject-1'],
				['id' => 'lead-2', 'client' => 'other-client'],
			],
			// ticket 26: a request (FK contact match), a contactmoment (no match)
			// and a complaint that DOES link to the subject but is not a harvested
			// source — the discriminator must keep it out of the dossier.
			'26' => [
				['id' => 'req-1', 'ticketType' => 'request', 'contact' => 'subject-1'],
				['id' => 'cm-1', 'ticketType' => 'contactmoment', 'client' => 'unrelated'],
				['id' => 'cpl-1', 'ticketType' => 'complaint', 'client' => 'subject-1'],
			],
		];

		$items = $this->provider->harvest('case-1', ['subjectId' => 'subject-1', 'subjectType' => 'contact']);

		// client(subject-1) + contact(contact-1) + lead(lead-1) + request(req-1) = 4.
		self::assertCount(4, $items);

		foreach ($items as $item) {
			self::assertSame('pipelinq-crm', $item->getSourceId());
			self::assertStringStartsWith('sha256:', $item->getContentHash());
			self::assertSame('collected', $item->getStatus());
		}

		// The dossier keeps a per-source `schema` label (and therefore a distinct
		// content hash) even though request/contactmoment now share one schema.
		$objectIds = array_map(static fn ($i): string => (string)($i->getPayload()['objectId'] ?? ''), $items);
		sort($objectIds);
		self::assertSame(['contact-1', 'lead-1', 'req-1', 'subject-1'], $objectIds);

		$requestItem = null;
		foreach ($items as $item) {
			if (($item->getPayload()['objectId'] ?? '') === 'req-1') {
				$requestItem = $item;
			}
		}

		self::assertNotNull($requestItem);
		self::assertSame('ticket_request', $requestItem->getPayload()['schema']);

		// Content hashes are unique per object and stable (deterministic).
		$hashes = array_map(static fn ($i): string => $i->getContentHash(), $items);
		self::assertSame($hashes, array_unique($hashes));

		$itemsAgain = $this->provider->harvest('case-1', ['subjectId' => 'subject-1', 'subjectType' => 'contact']);
		self::assertSame($hashes, array_map(static fn ($i): string => $i->getContentHash(), $itemsAgain));
	}//end testHarvestMatchesSubjectAcrossSchemas()

	/**
	 * An empty subjectId harvests nothing.
	 *
	 * @return void
	 */
	public function testHarvestEmptySubjectReturnsNothing(): void {
		$this->provision();
		self::assertSame([], $this->provider->harvest('case-1', ['subjectId' => '']));
	}//end testHarvestEmptySubjectReturnsNothing()

	/**
	 * Harvest on an unprovisioned install returns nothing (no throw).
	 *
	 * @return void
	 */
	public function testHarvestUnprovisionedReturnsNothing(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		self::assertSame([], $this->provider->harvest('case-1', ['subjectId' => 'subject-1']));
	}//end testHarvestUnprovisionedReturnsNothing()
}//end class
