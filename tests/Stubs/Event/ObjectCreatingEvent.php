<?php

/**
 * Test stub for OpenRegister's ObjectCreatingEvent.
 *
 * Mirrors the production class (openregister lib/Event/ObjectCreatingEvent.php)
 * member for member: the pre-save hook a listener uses to reject a create or
 * to hand back modified data, which MagicMapper merges over the object before
 * the insert. OpenRegister is not a test-time dependency. Resolved via the
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
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * ObjectCreatingEvent stub, same members as production.
 */
class ObjectCreatingEvent extends Event implements StoppableEventInterface {
	/**
	 * Whether a hook stopped propagation.
	 *
	 * @var boolean
	 */
	private bool $propagationStopped = false;

	/**
	 * Errors from the hook that stopped propagation.
	 *
	 * @var array<string, mixed>
	 */
	private array $errors = [];

	/**
	 * Data hooks hand back, merged over the object before the insert.
	 *
	 * @var array<string, mixed>
	 */
	private array $modifiedData = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object entity being created.
	 */
	public function __construct(
		private ObjectEntity $object,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object entity being created.
	 *
	 * @return ObjectEntity The object entity.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * Whether a hook stopped propagation.
	 *
	 * @return bool True when stopped.
	 */
	public function isPropagationStopped(): bool {
		return $this->propagationStopped;
	}//end isPropagationStopped()

	/**
	 * Stop propagation (a hook rejecting the create).
	 *
	 * @return void
	 */
	public function stopPropagation(): void {
		$this->propagationStopped = true;
	}//end stopPropagation()

	/**
	 * Set the errors from a hook.
	 *
	 * @param array<string, mixed> $errors The error details.
	 *
	 * @return void
	 */
	public function setErrors(array $errors): void {
		$this->errors = $errors;
	}//end setErrors()

	/**
	 * The errors from a hook.
	 *
	 * @return array<string, mixed> The error details.
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()

	/**
	 * Set the modified data (replaces what an earlier hook set).
	 *
	 * @param array<string, mixed> $data The modified data.
	 *
	 * @return void
	 */
	public function setModifiedData(array $data): void {
		$this->modifiedData = $data;
	}//end setModifiedData()

	/**
	 * The modified data.
	 *
	 * @return array<string, mixed> The modified data.
	 */
	public function getModifiedData(): array {
		return $this->modifiedData;
	}//end getModifiedData()
}//end class
