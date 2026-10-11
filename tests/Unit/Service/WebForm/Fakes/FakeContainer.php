<?php

/**
 * Test fake for the website form suite: a container holding named services, as the Nextcloud one does.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes
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

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Answers has() and get() from a map; an absent id throws, as the real container does.
 */
class FakeContainer implements ContainerInterface {

	/**
	 * Constructor.
	 *
	 * @param array<string, object> $services Id to service.
	 */
	public function __construct(
		public array $services = [],
	) {
	}//end __construct()

	/**
	 * The service for an id.
	 *
	 * @param string $id The id.
	 *
	 * @return mixed The service.
	 */
	public function get(string $id): mixed {
		if (isset($this->services[$id]) === false) {
			throw new RuntimeException('No service ' . $id);
		}

		return $this->services[$id];
	}//end get()

	/**
	 * Whether the id resolves.
	 *
	 * @param string $id The id.
	 *
	 * @return bool True when it does.
	 */
	public function has(string $id): bool {
		return isset($this->services[$id]);
	}//end has()
}//end class
