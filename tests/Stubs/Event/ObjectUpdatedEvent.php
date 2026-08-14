<?php

/**
 * Test stub for OpenRegister's ObjectUpdatedEvent.
 *
 * Mirrors the getNewObject()/getOldObject() surface the pipelinq listeners
 * consume; OpenRegister is not a test-time dependency. Resolved via the
 * `OCA\OpenRegister\ => tests/Stubs/` autoload-dev mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal ObjectUpdatedEvent stub.
 */
class ObjectUpdatedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $newObject The updated object entity.
	 * @param ObjectEntity|null $oldObject The previous object entity.
	 */
	public function __construct(
		private ObjectEntity $newObject,
		private ?ObjectEntity $oldObject,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The updated object entity.
	 *
	 * @return ObjectEntity The new object entity.
	 */
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()

	/**
	 * The previous object entity.
	 *
	 * @return ObjectEntity|null The old object entity, or null.
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()
}//end class
