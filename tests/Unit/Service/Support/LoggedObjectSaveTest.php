<?php

/**
 * Behavioural tests for the LoggedObjectSave trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\LoggedObjectSave;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The save, its missing-service and failure answers, and the failure log.
 *
 * @covers \OCA\Pipelinq\Service\Support\LoggedObjectSave
 */
class LoggedObjectSaveTest extends TestCase {

	/**
	 * A store recording its saves, failing on a payload with `fail`.
	 *
	 * @return object The store.
	 */
	private function store(): object {
		return new class {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $object   Object.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string|null          $uuid     Uuid.
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): array {
				if (($object['fail'] ?? false) === true) {
					throw new RuntimeException('locked');
				}

				$this->calls[] = [$register, $schema, $uuid];
				return $object + ['id' => 'saved'];
			}
		};
	}//end store()

	/**
	 * @return void
	 */
	public function testSavesIntoTheTarget(): void {
		$store = $this->store();
		$host = new LoggedObjectSaveHost($this->createStub(LoggerInterface::class), $store);

		$this->assertSame(['a' => 1, 'id' => 'saved'], $host->call(['a' => 1], ['reg', 'sch', 'u1', []]));
		$this->assertSame([['reg', 'sch', 'u1']], $store->calls);
	}//end testSavesIntoTheTarget()

	/**
	 * @return void
	 */
	public function testNoObjectServiceIsNull(): void {
		$host = new LoggedObjectSaveHost($this->createStub(LoggerInterface::class), null);
		$this->assertNull($host->call(['a' => 1], ['reg', 'sch', null, []]));
	}//end testNoObjectServiceIsNull()

	/**
	 * @return void
	 */
	public function testAFailedSaveIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('LoggedObjectSaveHost.saveObject: save failed', ['id' => 'x', 'exception' => 'locked']);
		$host = new LoggedObjectSaveHost($logger, $this->store());

		$this->assertNull($host->call(['fail' => true], ['reg', 'sch', null, ['id' => 'x']]));
	}//end testAFailedSaveIsLogged()
}//end class

/**
 * A host exposing the trait's save.
 */
final class LoggedObjectSaveHost {
	use LoggedObjectSave;

	/**
	 * @param LoggerInterface $logger The logger.
	 * @param object|null     $store  The object service.
	 */
	public function __construct(
		private LoggerInterface $logger,
		private ?object $store,
	) {
	}

	/**
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		return $this->store;
	}

	/**
	 * @param mixed $value The value.
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		return (array)$value;
	}

	/**
	 * @param array<string, mixed>                                                 $payload The payload.
	 * @param array{0: string, 1: string, 2: string|null, 3: array<string, mixed>} $target  The target.
	 * @return array<string, mixed>|null
	 */
	public function call(array $payload, array $target): ?array {
		return $this->saveLogged(payload: $payload, target: $target);
	}
}//end class
