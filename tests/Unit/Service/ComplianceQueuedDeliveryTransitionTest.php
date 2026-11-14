<?php

/**
 * Unit tests for ComplianceService's transition of queued BlastDelivery rows
 * to `unsubscribed-before-send` when consent is withdrawn: which rows move,
 * which are left alone, and what happens when OpenRegister fails.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Queued delivery transition on consent withdrawal.
 *
 * @covers \OCA\Pipelinq\Service\ComplianceService
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
final class ComplianceQueuedDeliveryTransitionTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The object service double.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Whether the container resolves the object service.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->openRegister = true;
		$this->config = [];
		$this->objects = new class {
			/** @var list<mixed> */
			public array $rows = [];

			/** @var list<array<string, mixed>> */
			public array $queries = [];

			/** @var list<array<string, mixed>> */
			public array $updates = [];

			/** @var \Throwable|null */
			public ?\Throwable $findAllFails = null;

			/** @var list<string> */
			public array $updateFailsFor = [];

			/**
			 * Find rows.
			 *
			 * @param array<string, mixed> $config The query
			 *
			 * @return list<mixed>
			 */
			public function findAll(array $config = []): array {
				$this->queries[] = $config;
				if ($this->findAllFails !== null) {
					throw $this->findAllFails;
				}

				return $this->rows;
			}

			/**
			 * Update a row.
			 *
			 * @param string $id The id
			 * @param array<string, mixed> $object The payload
			 * @param mixed $register The register
			 * @param mixed $schema The schema
			 *
			 * @return array<string, mixed>
			 */
			public function updateObject(string $id, array $object, mixed $register = null, mixed $schema = null): array {
				if (in_array($id, $this->updateFailsFor, true) === true) {
					throw new RuntimeException('locked ' . $id);
				}

				$this->updates[] = ['id' => $id, 'object' => $object, 'register' => $register, 'schema' => $schema];
				return $object;
			}
		};

	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return ComplianceService
	 */
	private function service(): ComplianceService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService' && $this->openRegister === true) {
					return $this->objects;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		return new ComplianceService(
			$container,
			$appConfig,
			$this->createMock(SegmentService::class),
			$this->logger,
			$this->createMock(SegmentSignalService::class),
			$this->createMock(IntegriqMarketingConsent::class),
		);

	}//end service()

	/**
	 * Run the transition for a contact.
	 *
	 * @param string $contactId The contact
	 * @param string|null $sourceBlastId The withdrawing blast
	 *
	 * @return void
	 */
	private function transition(string $contactId, ?string $sourceBlastId): void {
		$method = new ReflectionMethod(ComplianceService::class, 'transitionQueuedDeliveries');
		$method->invoke($this->service(), $contactId, $sourceBlastId);

	}//end transition()

	/**
	 * Queued rows of the contact move to unsubscribed-before-send with a UTC timestamp; others stay.
	 *
	 * @return void
	 */
	public function testQueuedRowsAreTransitioned(): void {
		$entity = new class {
			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['uuid' => 'd-entity'], 'status' => 'Queued', 'blastId' => 'b-1'];
			}
		};
		$this->objects->rows = [
			['id' => 'd-1', 'status' => 'queued', 'blastId' => 'b-1', 'contactId' => 'c-1'],
			['id' => 'd-2', 'status' => 'sent', 'blastId' => 'b-1'],
			['id' => 'd-3', 'status' => 'failed'],
			$entity,
		];

		$this->transition('c-1', null);

		$this->assertSame(
			[['filters' => ['contactId' => 'c-1', 'register' => 'pipelinq', 'schema' => 'blastDelivery']]],
			$this->objects->queries
		);
		$this->assertSame(['d-1', 'd-entity'], array_column($this->objects->updates, 'id'));
		$first = $this->objects->updates[0];
		$this->assertSame('pipelinq', $first['register']);
		$this->assertSame('blastDelivery', $first['schema']);
		$this->assertSame('unsubscribed-before-send', $first['object']['status']);
		$this->assertSame('c-1', $first['object']['contactId']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['object']['unsubscribedAt']);

	}//end testQueuedRowsAreTransitioned()

	/**
	 * With a source blast pinned, rows of other blasts are left; rows without a blast still move.
	 *
	 * @return void
	 */
	public function testSourceBlastPinsTheTransition(): void {
		$this->objects->rows = [
			['id' => 'd-1', 'status' => 'queued', 'blastId' => 'b-1'],
			['id' => 'd-2', 'status' => 'queued', 'blastId' => 'b-2'],
			['id' => 'd-3', 'status' => 'queued'],
		];

		$this->transition('c-1', 'b-1');
		$this->assertSame(['d-1', 'd-3'], array_column($this->objects->updates, 'id'));

		$this->objects->updates = [];
		$this->transition('c-1', '');
		$this->assertSame(['d-1', 'd-2', 'd-3'], array_column($this->objects->updates, 'id'));

	}//end testSourceBlastPinsTheTransition()

	/**
	 * Unreadable rows and rows without an id are skipped.
	 *
	 * @return void
	 */
	public function testRowsWithoutPayloadOrIdAreSkipped(): void {
		$this->objects->rows = [
			'not-a-row',
			[],
			['status' => 'queued'],
			['id' => '', 'uuid' => 'd-uuid', 'status' => 'queued'],
		];

		$this->transition('c-1', null);

		$this->assertSame(['d-uuid'], array_column($this->objects->updates, 'id'));

	}//end testRowsWithoutPayloadOrIdAreSkipped()

	/**
	 * A failing update is logged and the remaining rows still move.
	 *
	 * @return void
	 */
	public function testFailedUpdateIsLoggedAndOthersContinue(): void {
		$this->objects->rows = [
			['id' => 'd-1', 'status' => 'queued'],
			['id' => 'd-2', 'status' => 'queued'],
		];
		$this->objects->updateFailsFor = ['d-1'];
		$this->logger->expects($this->once())
			->method('warning')
			->with('ComplianceService.transitionQueuedDeliveries: updateObject failed', ['id' => 'd-1', 'exception' => 'locked d-1']);

		$this->transition('c-1', null);

		$this->assertSame(['d-2'], array_column($this->objects->updates, 'id'));

	}//end testFailedUpdateIsLoggedAndOthersContinue()

	/**
	 * A failing lookup is logged at info level and nothing is updated.
	 *
	 * @return void
	 */
	public function testFailedLookupIsLogged(): void {
		$this->objects->findAllFails = new RuntimeException('index down');
		$this->logger->expects($this->once())
			->method('info')
			->with('ComplianceService.transitionQueuedDeliveries: findAll failed', ['contactId' => 'c-1', 'exception' => 'index down']);

		$this->transition('c-1', null);

		$this->assertSame([], $this->objects->updates);

	}//end testFailedLookupIsLogged()

	/**
	 * Configured register and schema slugs are used for the query and the update.
	 *
	 * @return void
	 */
	public function testConfiguredSlugsAreUsed(): void {
		$this->config = ['register' => 'crm', 'blast_delivery_schema' => 'deliveries'];
		$this->objects->rows = [['id' => 'd-1', 'status' => 'queued']];

		$this->transition('c-1', null);

		$this->assertSame(['contactId' => 'c-1', 'register' => 'crm', 'schema' => 'deliveries'], $this->objects->queries[0]['filters']);
		$this->assertSame('crm', $this->objects->updates[0]['register']);
		$this->assertSame('deliveries', $this->objects->updates[0]['schema']);

	}//end testConfiguredSlugsAreUsed()

	/**
	 * Without OpenRegister nothing is queried.
	 *
	 * @return void
	 */
	public function testNoOpenRegisterIsANoOp(): void {
		$this->openRegister = false;
		$this->logger->expects($this->once())->method('warning')->with('ComplianceService.getObjectService: OpenRegister unavailable');

		$this->transition('c-1', null);

		$this->assertSame([], $this->objects->queries);

	}//end testNoOpenRegisterIsANoOp()
}//end class
