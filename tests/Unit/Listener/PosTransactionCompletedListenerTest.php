<?php

/**
 * Unit tests for PosTransactionCompletedListener.
 *
 * The listener awards loyalty points when a posTransaction settles. Both of the
 * reasons it never did so are pinned here: the `getSchema()` probe that rejected
 * every entity, and the retired Dutch payload vocabulary that resolved the
 * customer link and the amount to nothing (pipelinq#807).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\PosTransactionCompletedListener;
use OCA\Pipelinq\Service\LoyaltyEngineService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the POS → loyalty seam.
 */
class PosTransactionCompletedListenerTest extends TestCase {

	/**
	 * Calls captured from the loyalty engine.
	 *
	 * @var array<int, array{klantId: string, transaction: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * Build a real posTransaction entity (never a mock — see EntityAccessorTest).
	 *
	 * @param string $schema The schema id carried by the entity.
	 * @param array<string, mixed> $data The object payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid((string)($data['uuid'] ?? 'txn-1'));
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Build the listener with a capturing loyalty engine.
	 *
	 * @param string|null $mappedType The entity type SchemaMapService resolves to.
	 * @param string $configuredPos The `posTransaction_schema` app-config value.
	 *
	 * @return PosTransactionCompletedListener The listener under test.
	 */
	private function listener(?string $mappedType = 'posTransaction', string $configuredPos = ''): PosTransactionCompletedListener {
		$this->calls = [];

		$engine = $this->createMock(LoyaltyEngineService::class);
		$engine->method('processPosTransaction')->willReturnCallback(
			function (string $klantId, array $transaction): array {
				$this->calls[] = ['klantId' => $klantId, 'transaction' => $transaction];
				return [];
			}
		);

		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturn($mappedType);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configuredPos);

		return new PosTransactionCompletedListener(
			$engine,
			$schemaMap,
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

	}//end listener()

	/**
	 * The comparand contract: pipelinq matches on the NUMERIC schema id.
	 *
	 * OpenRegister stamps the schema's numeric id into the entity — measured on
	 * a live Nextcloud 34, `MagicMapper::find(<uuid>)->getSchema()` returns
	 * `'434'` for a posTransaction, and pipelinq's `posTransaction_schema`
	 * app-config holds `434` because `SettingsMapBuilder::addSchemaToMap()`
	 * stores `$schema['id']` keyed by slug. So reading the raw value is
	 * sufficient here and slug resolution would BREAK the comparison
	 * (`'posTransaction' !== '434'`).
	 *
	 * This test drives the app-config fallback arm with `resolveEntityType()`
	 * returning null, so the only thing that can make it pass is the raw
	 * numeric value matching the configured id.
	 *
	 * @return void
	 */
	public function testTheGuardMatchesOnTheNumericSchemaIdOpenRegisterStamps(): void {
		$listener = $this->listener(mappedType: null, configuredPos: '434');
		$entity = $this->entity(
			'434',
			['status' => 'settled', 'customer' => 'contact-42', 'total' => 5]
		);

		$listener->handle(new ObjectCreatedEvent($entity));

		$this->assertCount(1, $this->calls, 'the numeric schema id must match the configured id');

		// The mirror: an entity carrying the SLUG does not match a numeric-id
		// configuration, which is what makes the id-vs-slug distinction real.
		$listener = $this->listener(mappedType: null, configuredPos: '434');
		$slugged = $this->entity(
			'posTransaction',
			['status' => 'settled', 'customer' => 'contact-42', 'total' => 5]
		);

		$listener->handle(new ObjectCreatedEvent($slugged));

		$this->assertSame([], $this->calls);

	}//end testTheGuardMatchesOnTheNumericSchemaIdOpenRegisterStamps()

	/**
	 * A settled posTransaction awards points, with the amount and the customer
	 * link read from the fields the posTransaction schema actually declares.
	 *
	 * Reverting `isPosTransaction()` to `method_exists($entity, 'getSchema')`
	 * makes this test fail, because the probe is false for a magic accessor.
	 *
	 * @return void
	 */
	public function testASettledTransactionReachesTheLoyaltyEngine(): void {
		$listener = $this->listener();
		$entity = $this->entity(
			'schema-pos',
			[
				'uuid' => 'txn-9',
				'status' => 'settled',
				'customer' => 'contact-42',
				'total' => 87.5,
				'settledAt' => '2026-08-12T10:00:00+00:00',
				'reference' => 'TXN-2026-0009',
				'terminalId' => 'POS-1',
			]
		);

		$listener->handle(new ObjectUpdatedEvent($entity, null));

		$this->assertCount(1, $this->calls);
		$this->assertSame('contact-42', $this->calls[0]['klantId']);

		$transaction = $this->calls[0]['transaction'];
		$this->assertSame(87.5, $transaction['amount']);
		$this->assertSame('2026-08-12T10:00:00+00:00', $transaction['timestamp']);
		$this->assertSame('TXN-2026-0009', $transaction['posTransactionId']);
		$this->assertSame('POS-1', $transaction['posTerminalId']);
		$this->assertSame('purchase', $transaction['trigger']);

	}//end testASettledTransactionReachesTheLoyaltyEngine()

	/**
	 * With no `reference` on the payload the transaction id falls back to the
	 * entity uuid — which is itself a magic accessor.
	 *
	 * @return void
	 */
	public function testTheTransactionIdFallsBackToTheEntityUuid(): void {
		$listener = $this->listener();
		$entity = $this->entity(
			'schema-pos',
			[
				'uuid' => 'txn-fallback',
				'status' => 'settled',
				'customer' => 'contact-7',
				'total' => 10,
			]
		);

		$listener->handle(new ObjectCreatedEvent($entity));

		$this->assertCount(1, $this->calls);
		$this->assertSame('txn-fallback', $this->calls[0]['transaction']['posTransactionId']);

	}//end testTheTransactionIdFallsBackToTheEntityUuid()

	/**
	 * A transaction with no customer link awards nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousTransactionAwardsNothing(): void {
		$listener = $this->listener();
		$entity = $this->entity('schema-pos', ['status' => 'settled', 'total' => 12]);

		$listener->handle(new ObjectCreatedEvent($entity));

		$this->assertSame([], $this->calls);

	}//end testAnAnonymousTransactionAwardsNothing()

	/**
	 * A transaction that has not settled awards nothing.
	 *
	 * @return void
	 */
	public function testADraftTransactionAwardsNothing(): void {
		$listener = $this->listener();
		$entity = $this->entity('schema-pos', ['status' => 'draft', 'customer' => 'contact-1', 'total' => 12]);

		$listener->handle(new ObjectCreatedEvent($entity));

		$this->assertSame([], $this->calls);

	}//end testADraftTransactionAwardsNothing()

	/**
	 * An entity on another schema is ignored — the guard still discriminates.
	 *
	 * @return void
	 */
	public function testAnUnrelatedSchemaIsIgnored(): void {
		$listener = $this->listener(mappedType: 'lead');
		$entity = $this->entity('schema-lead', ['status' => 'settled', 'customer' => 'contact-1', 'total' => 12]);

		$listener->handle(new ObjectCreatedEvent($entity));

		$this->assertSame([], $this->calls);

	}//end testAnUnrelatedSchemaIsIgnored()

}//end class
