<?php

/**
 * Events PosTransactionCompletedListener ignores before reading a transaction.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\Pipelinq\Listener\PosTransactionCompletedListener;
use OCA\Pipelinq\Service\LoyaltyEngineService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted transaction and an object without a schema award nothing and
 * never reach the schema map; the configured posTransaction_schema still
 * identifies a transaction the schema map does not know.
 *
 * @covers \OCA\Pipelinq\Listener\PosTransactionCompletedListener
 * @uses   \OCA\Pipelinq\AppInfo\OpenRegisterAutoloader
 */
class PosTransactionCompletedListenerGuardsTest extends TestCase {
	/**
	 * The loyalty engine.
	 *
	 * @var LoyaltyEngineService&MockObject
	 */
	private LoyaltyEngineService&MockObject $engine;

	/**
	 * The schema map.
	 *
	 * @var SchemaMapService&MockObject
	 */
	private SchemaMapService&MockObject $schemaMap;

	/**
	 * The listener under test.
	 *
	 * @var PosTransactionCompletedListener
	 */
	private PosTransactionCompletedListener $listener;

	/**
	 * Build the listener with `s-pos` configured as the transaction schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = $this->createMock(originalClassName: LoyaltyEngineService::class);
		$this->schemaMap = $this->createMock(originalClassName: SchemaMapService::class);
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'posTransaction_schema' ? 's-pos' : $default)
		);

		$this->listener = new PosTransactionCompletedListener(
			loyaltyEngineService: $this->engine,
			schemaMapService: $this->schemaMap,
			appConfig: $config,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A paid transaction for a customer.
	 *
	 * @param string|null $schema The schema id, or null for none.
	 *
	 * @return ObjectEntity The transaction.
	 */
	private function transaction(?string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('tx-1');
		if ($schema !== null) {
			$entity->setSchema($schema);
		}

		$entity->setObject(['status' => 'paid', 'customer' => 'c-1', 'total' => 10]);
		return $entity;
	}//end transaction()

	/**
	 * A deleted transaction awards nothing.
	 *
	 * @return void
	 */
	public function testADeletedTransactionAwardsNothing(): void {
		$this->schemaMap->expects($this->never())->method('resolveEntityType');
		$this->engine->expects($this->never())->method('processPosTransaction');

		$this->listener->handle(event: new ObjectDeletedEvent(object: $this->transaction(schema: 's-pos')));
	}//end testADeletedTransactionAwardsNothing()

	/**
	 * An object without a schema awards nothing and is not resolved.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutASchemaAwardsNothing(): void {
		$this->schemaMap->expects($this->never())->method('resolveEntityType');
		$this->engine->expects($this->never())->method('processPosTransaction');

		$this->listener->handle(event: new ObjectCreatedEvent(object: $this->transaction(schema: null)));
	}//end testAnObjectWithoutASchemaAwardsNothing()

	/**
	 * The configured transaction schema identifies a transaction the map does not know.
	 *
	 * @return void
	 */
	public function testTheConfiguredSchemaIdentifiesATransaction(): void {
		$this->schemaMap->method('resolveEntityType')->willReturn(null);
		$this->engine->expects($this->once())->method('processPosTransaction')->with('c-1', $this->anything());

		$this->listener->handle(event: new ObjectCreatedEvent(object: $this->transaction(schema: 's-pos')));
	}//end testTheConfiguredSchemaIdentifiesATransaction()
}//end class
