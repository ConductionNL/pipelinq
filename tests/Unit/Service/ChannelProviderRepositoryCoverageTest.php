<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Listing, lookup and vendor selection of channel providers, including every
 * path where OpenRegister is absent or a read fails.
 *
 * @covers \OCA\Pipelinq\Service\ChannelProviderRepository
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class ChannelProviderRepositoryCoverageTest extends TestCase {

	/**
	 * The OpenRegister ObjectService the container hands out.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger        = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A repository whose container resolves the ObjectService, or throws when OpenRegister is absent.
	 *
	 * @param array<string, string> $config      App config values.
	 * @param bool                  $orAvailable Whether OpenRegister is installed.
	 *
	 * @return ChannelProviderRepository
	 */
	private function repository(array $config = [], bool $orAvailable = true): ChannelProviderRepository {
		$container = $this->createMock(ContainerInterface::class);
		if ($orAvailable === false) {
			$container->expects($this->once())->method('get')->willThrowException(new RuntimeException('no openregister'));
		} else {
			$container->method('get')->willReturnCallback(
				function (string $id): object {
					$this->assertSame('OCA\\OpenRegister\\Service\\ObjectService', $id);
					return $this->objectService;
				}
			);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ChannelProviderRepository($container, $appConfig, $this->logger);
	}//end repository()

	/**
	 * An OpenRegister entity carrying the given row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $row): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($row);
		return $entity;
	}//end entity()

	/**
	 * Active rows come back by ascending priority (missing priority = 100), inactive rows are dropped.
	 *
	 * @return void
	 */
	public function testListActiveSortsByPriorityAndDropsInactiveRows(): void {
		$this->objectService->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => [
						'kind' => 'sms',
						'active' => true,
						'register' => 'pipelinq',
						'schema' => 'channelProvider',
					],
				]
			)
			->willReturn(
				[
					['id' => 'no-priority', 'vendor' => 'twilio'],
					$this->entity(['id' => 'low', 'priority' => 5, 'active' => true, 'vendor' => 'messagebird']),
					['id' => 'off', 'priority' => 1, 'active' => false, 'vendor' => 'twilio'],
					['id' => 'mid', 'priority' => '50', 'vendor' => 'twilio'],
				]
			);

		$rows = $this->repository()->listActive(kind: 'sms');

		$this->assertSame(['low', 'mid', 'no-priority'], array_column($rows, 'id'));
	}//end testListActiveSortsByPriorityAndDropsInactiveRows()

	/**
	 * Register and schema slugs configured in the app config replace the defaults.
	 *
	 * @return void
	 */
	public function testListActiveUsesConfiguredSlugs(): void {
		$this->objectService->expects($this->once())->method('findAll')
			->with(
				[
					'filters' => [
						'kind' => 'whatsapp-bsp',
						'active' => true,
						'register' => 'crm',
						'schema' => 'providers',
					],
				]
			)
			->willReturn([]);

		$repository = $this->repository(config: ['register' => 'crm', 'channelProvider_schema' => 'providers']);

		$this->assertSame([], $repository->listActive(kind: 'whatsapp-bsp'));
	}//end testListActiveUsesConfiguredSlugs()

	/**
	 * A failing findAll is logged with the kind and answers no providers.
	 *
	 * @return void
	 */
	public function testListActiveAnswersNothingWhenTheReadFails(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('ChannelProviderRepository.listActive: findAll failed', ['kind' => 'sms', 'exception' => 'db down']);

		$this->assertSame([], $this->repository()->listActive(kind: 'sms'));
	}//end testListActiveAnswersNothingWhenTheReadFails()

	/**
	 * Without OpenRegister listing and lookup answer nothing, and the absence is logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsFound(): void {
		$this->logger->expects($this->exactly(2))->method('warning')
			->with('ChannelProviderRepository.getObjectService: OpenRegister unavailable', ['exception' => 'no openregister']);

		$this->assertSame([], $this->repository(orAvailable: false)->listActive(kind: 'sms'));
		$this->assertNull($this->repository(orAvailable: false)->findById(id: 'prov-1'));
	}//end testWithoutOpenRegisterNothingIsFound()

	/**
	 * An empty id is answered without touching OpenRegister.
	 *
	 * @return void
	 */
	public function testAnEmptyIdIsNeverLookedUp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$repository = new ChannelProviderRepository($container, $this->createMock(IAppConfig::class), $this->logger);

		$this->assertNull($repository->findById(id: ''));
		$this->assertNull($repository->findByIdForWebhook(id: ''));
	}//end testAnEmptyIdIsNeverLookedUp()

	/**
	 * A user lookup reads with RBAC and multitenancy on and returns the entity as an array.
	 *
	 * @return void
	 */
	public function testFindByIdReadsUnderRbacAndReturnsThePayload(): void {
		$this->objectService->expects($this->once())->method('find')
			->with('prov-1', [], false, 'pipelinq', 'channelProvider', true, true)
			->willReturn($this->entity(['uuid' => 'prov-1', 'vendor' => 'twilio']));

		$this->assertSame(['uuid' => 'prov-1', 'vendor' => 'twilio'], $this->repository()->findById(id: 'prov-1'));
	}//end testFindByIdReadsUnderRbacAndReturnsThePayload()

	/**
	 * A missing object answers null.
	 *
	 * @return void
	 */
	public function testFindByIdAnswersNullForAMissingObject(): void {
		$this->objectService->expects($this->once())->method('find')->willReturn(null);
		$this->logger->expects($this->never())->method('info');

		$this->assertNull($this->repository()->findById(id: 'gone'));
	}//end testFindByIdAnswersNullForAMissingObject()

	/**
	 * A failing find is logged at info with the id and answers null.
	 *
	 * @return void
	 */
	public function testFindByIdAnswersNullWhenTheReadFails(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('not found'));
		$this->logger->expects($this->once())->method('info')
			->with('ChannelProviderRepository.findById: not found', ['id' => 'prov-9', 'exception' => 'not found']);

		$this->assertNull($this->repository()->findById(id: 'prov-9'));
	}//end testFindByIdAnswersNullWhenTheReadFails()

	/**
	 * The first active row of the kind with the vendor wins; an unknown vendor answers null.
	 *
	 * @return void
	 */
	public function testFindByVendorPicksTheHighestPriorityMatch(): void {
		$this->objectService->expects($this->exactly(2))->method('findAll')->willReturn(
			[
				['id' => 'mb-2', 'vendor' => 'messagebird', 'priority' => 20],
				['id' => 'tw', 'vendor' => 'twilio', 'priority' => 1],
				['id' => 'mb-1', 'vendor' => 'messagebird', 'priority' => 10],
			]
		);
		$repository = $this->repository();

		$this->assertSame('mb-1', ($repository->findByVendor(kind: 'sms', vendor: 'messagebird')['id'] ?? null));
		$this->assertNull($repository->findByVendor(kind: 'sms', vendor: 'vonage'));
	}//end testFindByVendorPicksTheHighestPriorityMatch()
}//end class
