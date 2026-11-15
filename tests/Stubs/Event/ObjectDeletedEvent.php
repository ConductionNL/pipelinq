<?php

/**
 * Minimal OpenRegister ObjectDeletedEvent stub.
 *
 * Mirrors openregister `lib/Event/ObjectDeletedEvent.php`: constructor takes
 * the ObjectEntity, getObject() returns it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Minimal ObjectDeletedEvent stub.
 */
class ObjectDeletedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The deleted object entity.
	 */
	public function __construct(
		private ObjectEntity $object,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The deleted object entity.
	 *
	 * @return ObjectEntity The object entity.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
