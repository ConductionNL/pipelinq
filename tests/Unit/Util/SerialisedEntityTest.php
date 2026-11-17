<?php

/**
 * Behavioural tests for the SerialisedEntity trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\SerialisedEntity;
use PHPUnit\Framework\TestCase;

/**
 * An entity serialising to an array is that array; anything else is null.
 *
 * @covers \OCA\Pipelinq\Util\SerialisedEntity
 */
class SerialisedEntityTest extends TestCase {

	/**
	 * @return void
	 */
	public function testSerialisedEntity(): void {
		$host = new class {
			use SerialisedEntity;

			/**
			 * @param mixed $entity The entity.
			 * @return array<string, mixed>|null
			 */
			public function read(mixed $entity): ?array {
				return $this->serialisedEntity(entity: $entity);
			}
		};
		$entity = static fn (mixed $data): \JsonSerializable => new class($data) implements \JsonSerializable {
			/**
			 * @param mixed $data The data.
			 */
			public function __construct(private mixed $data) {
			}

			/**
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};

		$this->assertSame(['id' => 'a'], $host->read($entity(['id' => 'a'])));
		$this->assertNull($host->read($entity('scalar')));
		$this->assertNull($host->read(null));
	}//end testSerialisedEntity()
}//end class
