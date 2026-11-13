<?php

/**
 * Minimal OpenRegister ObjectDeletingEvent stub.
 *
 * Mirrors openregister `lib/Event/ObjectDeletingEvent.php`: the stoppable
 * pre-delete event both the soft and the permanent delete dispatch; getObject()
 * returns the entity, and a listener vetoes with setErrors() + stopPropagation().
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
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Minimal ObjectDeletingEvent stub.
 */
class ObjectDeletingEvent extends Event implements StoppableEventInterface {

	/**
	 * Whether a listener stopped the delete.
	 *
	 * @var boolean
	 */
	private bool $propagationStopped = false;

	/**
	 * The veto errors.
	 *
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object about to be deleted.
	 */
	public function __construct(
		private ObjectEntity $object,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object about to be deleted.
	 *
	 * @return ObjectEntity
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * Whether a listener stopped the delete.
	 *
	 * @return bool
	 */
	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}//end isPropagationStopped()

	/**
	 * Stop the delete.
	 *
	 * @return void
	 */
	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}//end stopPropagation()

	/**
	 * Record the veto errors.
	 *
	 * @param array<string, mixed> $errors The errors.
	 *
	 * @return void
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}//end setErrors()

	/**
	 * The veto errors.
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
