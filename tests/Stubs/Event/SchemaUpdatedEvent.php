<?php

/**
 * Test stub for OpenRegister's SchemaUpdatedEvent.
 *
 * Mirrors the getNewSchema/getOldSchema surface the pipelinq export listener
 * consumes; OpenRegister is not a test-time dependency.
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Minimal SchemaUpdatedEvent stub.
 */
class SchemaUpdatedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param Schema $newSchema The updated schema.
	 * @param Schema $oldSchema The previous schema.
	 */
	public function __construct(
		private Schema $newSchema,
		private Schema $oldSchema,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The updated schema.
	 *
	 * @return Schema The new schema.
	 */
	public function getNewSchema(): Schema {
		return $this->newSchema;
	}//end getNewSchema()

	/**
	 * The previous schema.
	 *
	 * @return Schema The old schema.
	 */
	public function getOldSchema(): Schema {
		return $this->oldSchema;
	}//end getOldSchema()
}//end class
