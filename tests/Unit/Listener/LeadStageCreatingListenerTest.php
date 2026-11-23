<?php

/**
 * Unit tests for LeadStageCreatingListener.
 *
 * Drives the listener with OpenRegister's ObjectCreatingEvent (the stub that
 * mirrors production member for member) and reads back what MagicMapper
 * would merge: getModifiedData().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\LeadStageCreatingListener;
use OCA\Pipelinq\Service\LeadStagePlacer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A new lead is stored in a stage, whatever created it.
 */
class LeadStageCreatingListenerTest extends TestCase {

	/**
	 * The listener over one stored sales pipeline.
	 *
	 * @return LeadStageCreatingListener The listener.
	 */
	private function listener(): LeadStageCreatingListener {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => '20',
				'lead_schema' => '30',
				'pipeline_schema' => '31',
			][$key] ?? $default
		);

		$objectService = new class {
			/**
			 * Return the stored pipelines.
			 *
			 * @param array<string, mixed> $config Query config.
			 * @param bool                 $_rbac  RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [[
					'id' => 'p-sales',
					'isDefault' => true,
					'stages' => [['name' => 'New', 'order' => 1], ['name' => 'Won', 'order' => 9, 'isClosed' => true]],
				]];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new LeadStageCreatingListener($config, $container, new LeadStagePlacer(), $this->createMock(LoggerInterface::class));
	}//end listener()

	/**
	 * An entity for the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The object data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('lead-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The enquiry flow's lead (pipeline, no stage) is stored in the first open stage.
	 *
	 * @return void
	 */
	public function testEnquiryLeadIsPlacedInTheFirstOpenStage(): void {
		$event = new ObjectCreatingEvent($this->entity('30', ['title' => 'Enquiry', 'pipeline' => 'p-sales', 'status' => 'open']));
		$event->setModifiedData(['source' => 'website']);

		$this->listener()->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame('website', $modified['source'], 'an earlier hook\'s change must survive');
		$this->assertSame('New', $modified['stage']);
		$this->assertSame(1, $modified['stageOrder']);
		$this->assertArrayHasKey('stageEnteredAt', $modified);
		$this->assertArrayNotHasKey('pipeline', $modified, 'the lead already had a pipeline');
	}//end testEnquiryLeadIsPlacedInTheFirstOpenStage()

	/**
	 * A lead created through the API with neither pipeline nor stage gets both.
	 *
	 * @return void
	 */
	public function testApiLeadWithoutPipelineGetsPipelineAndStage(): void {
		$event = new ObjectCreatingEvent($this->entity('30', ['title' => 'API lead']));

		$this->listener()->handle($event);

		$this->assertSame('p-sales', $event->getModifiedData()['pipeline']);
		$this->assertSame('New', $event->getModifiedData()['stage']);
	}//end testApiLeadWithoutPipelineGetsPipelineAndStage()

	/**
	 * Objects of other schemas are not touched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity('99', ['title' => 'A client']));

		$this->listener()->handle($event);

		$this->assertSame([], $event->getModifiedData());
	}//end testOtherSchemasAreIgnored()

	/**
	 * Golden: the pipeline read — its exact query and RBAC flags, the rows it keeps, and when it reads nothing.
	 *
	 * @return void
	 */
	public function testPipelinesAreReadExactly(): void {
		$calls = [];
		$rows = [
			['id' => 'p-1'],
			new class implements \JsonSerializable {
				/**
				 * @return array<string, string> The pipeline.
				 */
				public function jsonSerialize(): array {
					return ['id' => 'p-2'];
				}
			},
			new class implements \JsonSerializable {
				/**
				 * @return string Not a pipeline.
				 */
				public function jsonSerialize(): string {
					return 'p-3';
				}
			},
			new \stdClass(),
			'p-4',
		];
		$objectService = new class ($rows, $calls) {
			/**
			 * @param array<int, mixed>             $rows  The rows.
			 * @param array<int, array<int, mixed>> $calls The call log.
			 */
			public function __construct(private array $rows, private array &$calls) {
			}

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = [$config, $_rbac, $_multitenancy];
				if ($this->rows === []) {
					throw new \RuntimeException('register offline');
				}

				return $this->rows;
			}
		};
		$read = function (string $pipelineSchema, object $objects, LoggerInterface $logger, string $register): array {
			$config = $this->createMock(IAppConfig::class);
			$config->method('getValueString')->willReturn($pipelineSchema);
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($objects);
			$listener = new LeadStageCreatingListener($config, $container, new LeadStagePlacer(), $logger);
			return (new \ReflectionMethod($listener, 'readPipelines'))->invoke($listener, $register);
		};

		$this->assertSame([['id' => 'p-1'], ['id' => 'p-2']], $read('31', $objectService, $this->createMock(LoggerInterface::class), '20'));
		$this->assertSame([[['filters' => ['register' => '20', 'schema' => '31'], 'limit' => 200], false, false]], $calls);

		$calls = [];
		$this->assertSame([], $read('', $objectService, $this->createMock(LoggerInterface::class), '20'));
		$this->assertSame([], $read('31', $objectService, $this->createMock(LoggerInterface::class), ''));
		$this->assertSame([], $calls);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Pipelinq: could not read pipelines to place a new lead in a stage', ['exception' => 'register offline']);
		$empty = [];
		$failing = new class ($empty, $calls) {
			/**
			 * @param array<int, mixed>             $rows  The rows.
			 * @param array<int, array<int, mixed>> $calls The call log.
			 */
			public function __construct(private array $rows, private array &$calls) {
			}

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<int, mixed> Never.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				throw new \RuntimeException('register offline');
			}
		};
		$this->assertSame([], $read('31', $failing, $logger, '20'));
	}//end testPipelinesAreReadExactly()
}//end class
