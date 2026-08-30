<?php

/**
 * Test stub for OpenRegister's ObjectCreatedEvent.
 *
 * Mirrors the getObject() surface the pipelinq listeners consume; OpenRegister
 * is not a test-time dependency. Resolved via the
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
 * Minimal ObjectCreatedEvent stub.
 */
class ObjectCreatedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The created object entity.
	 */
	public function __construct(
		private ObjectEntity $object,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The created object entity.
	 *
	 * @return ObjectEntity The object entity.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
