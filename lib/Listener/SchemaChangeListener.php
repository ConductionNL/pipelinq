<?php

/**
 * Pipelinq SchemaChangeListener.
 *
 * Listens for OpenRegister schema-update events and logs column-level drift on
 * pipelinq schemas via SchemaEvolutionService, so a schema change between
 * export runs is observable in the run audit rather than breaking the export.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\Pipelinq\Service\Export\SchemaEvolutionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener for OpenRegister SchemaUpdatedEvent.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
 */
class SchemaChangeListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SchemaEvolutionService $evolution The schema-evolution service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SchemaEvolutionService $evolution,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a schema-update event: log the column drift for the export audit.
	 *
	 * The OR Schema objects are read defensively (duck-typed) so this app does
	 * not hard-couple to OR's Schema entity shape at compile time.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-009
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaUpdatedEvent) === false) {
			return;
		}

		try {
			$newColumns = $this->columnsOf(schema: $event->getNewSchema());
			$oldColumns = $this->columnsOf(schema: $event->getOldSchema());
		} catch (\Throwable $e) {
			return;
		}

		if ($newColumns === [] && $oldColumns === []) {
			return;
		}

		$changes = $this->evolution->compareColumns(current: $newColumns, previous: $oldColumns);
		if ($changes === []) {
			return;
		}

		$this->logger->info(
			'Pipelinq: schema change detected for BI export',
			['changes' => $changes]
		);
	}//end handle()

	/**
	 * Extract a column => type map from an OR Schema (duck-typed).
	 *
	 * @param mixed $schema The OR Schema object.
	 *
	 * @return array<string, string> The column => type map.
	 */
	private function columnsOf(mixed $schema): array {
		if (is_object($schema) === false || method_exists($schema, 'getProperties') === false) {
			return [];
		}

		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return [];
		}

		$columns = [];
		foreach ($properties as $name => $definition) {
			$type = 'string';
			if (is_array($definition) === true && isset($definition['type']) === true) {
				$type = (string)$definition['type'];
			}

			$columns[(string)$name] = $type;
		}

		return $columns;
	}//end columnsOf()
}//end class
