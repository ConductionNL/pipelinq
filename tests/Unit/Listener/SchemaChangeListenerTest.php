<?php

/**
 * Unit tests for SchemaChangeListener.
 *
 * Asserts the listener logs the column changes between an old and a new
 * schema for the BI export, and stays silent for other events, unreadable
 * schemas and updates that change no column.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\Pipelinq\Listener\SchemaChangeListener;
use OCA\Pipelinq\Service\Export\SchemaEvolutionService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for SchemaChangeListener.
 *
 * @covers \OCA\Pipelinq\Listener\SchemaChangeListener
 * @uses   \OCA\Pipelinq\Service\Export\SchemaEvolutionService
 */
class SchemaChangeListenerTest extends TestCase {

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The listener under test, with the real column comparison.
	 *
	 * @var SchemaChangeListener
	 */
	private SchemaChangeListener $listener;

	/**
	 * Set up the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new SchemaChangeListener(evolution: new SchemaEvolutionService(), logger: $this->logger);
	}//end setUp()

	/**
	 * A schema double with the given properties, or one whose getProperties() throws.
	 *
	 * @param array|\Throwable $properties The properties or the failure.
	 *
	 * @return Schema
	 */
	private function schema(array|\Throwable $properties): Schema {
		$schema = $this->createMock(originalClassName: Schema::class);
		if ($properties instanceof \Throwable) {
			$schema->method('getProperties')->willThrowException(exception: $properties);
			return $schema;
		}

		$schema->method('getProperties')->willReturn(value: $properties);
		return $schema;
	}//end schema()

	/**
	 * Added, changed and removed columns are logged; a property without a type counts as string.
	 *
	 * @return void
	 */
	public function testColumnChangesAreLogged(): void {
		$old = $this->schema(properties: ['title' => ['type' => 'string'], 'value' => ['type' => 'number'], 'gone' => ['type' => 'string']]);
		$new = $this->schema(properties: ['title' => [], 'value' => ['type' => 'integer'], 'stage' => 'free-form']);
		$this->logger->expects($this->once())->method('info')->with(
			'Pipelinq: schema change detected for BI export',
			['changes' => ['changed: value (number -> integer)', 'added: stage', 'removed: gone']]
		);

		$this->listener->handle(event: new SchemaUpdatedEvent($new, $old));
	}//end testColumnChangesAreLogged()

	/**
	 * An update that changes no column logs nothing.
	 *
	 * @return void
	 */
	public function testAnUnchangedSchemaLogsNothing(): void {
		$this->logger->expects($this->never())->method('info');

		$this->listener->handle(
			event: new SchemaUpdatedEvent($this->schema(properties: ['a' => ['type' => 'string']]), $this->schema(properties: ['a' => []]))
		);
		$this->listener->handle(event: new SchemaUpdatedEvent($this->schema(properties: []), $this->schema(properties: [])));
	}//end testAnUnchangedSchemaLogsNothing()

	/**
	 * Other events and a schema whose properties cannot be read are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAndUnreadableSchemasAreIgnored(): void {
		$this->logger->expects($this->never())->method('info');

		$this->listener->handle(event: new Event());
		$this->listener->handle(
			event: new SchemaUpdatedEvent($this->schema(properties: ['a' => []]), $this->schema(properties: new RuntimeException('broken')))
		);
	}//end testOtherEventsAndUnreadableSchemasAreIgnored()
}//end class
