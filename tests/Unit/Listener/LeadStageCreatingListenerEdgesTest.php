<?php

/**
 * The edges of LeadStageCreatingListener: foreign events and leads it cannot place.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\Listener\LeadStageCreatingListener;
use OCA\Pipelinq\Service\LeadStagePlacer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Any event other than ObjectCreatingEvent reads no config, and a new lead
 * that no pipeline can place keeps exactly the data earlier hooks set.
 *
 * @covers \OCA\Pipelinq\Listener\LeadStageCreatingListener
 * @uses   \OCA\Pipelinq\Service\LeadStagePlacer
 */
class LeadStageCreatingListenerEdgesTest extends TestCase {

	/**
	 * A lead entity.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function lead(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('lead-1');
		$entity->setSchema('30');
		$entity->setObject(['title' => 'Walk-in']);
		return $entity;
	}//end lead()

	/**
	 * An already-created lead is not placed, and no config is read.
	 *
	 * @return void
	 */
	public function testAnEventOtherThanCreatingIsIgnored(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->never())->method('getValueString');
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$listener = new LeadStageCreatingListener($config, $container, new LeadStagePlacer(), $this->createMock(LoggerInterface::class));
		$listener->handle(new ObjectCreatedEvent($this->lead()));
	}//end testAnEventOtherThanCreatingIsIgnored()

	/**
	 * Without a configured pipeline schema there is no pipeline to place the
	 * lead in: no object is read and the modified data is left as it was.
	 *
	 * @return void
	 */
	public function testALeadWithoutAnyPipelineIsLeftUntouched(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => '20',
				'lead_schema' => '30',
			][$key] ?? $default
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$event = new ObjectCreatingEvent($this->lead());
		$event->setModifiedData(['source' => 'website']);

		$listener = new LeadStageCreatingListener($config, $container, new LeadStagePlacer(), $this->createMock(LoggerInterface::class));
		$listener->handle($event);

		$this->assertSame(['source' => 'website'], $event->getModifiedData());
	}//end testALeadWithoutAnyPipelineIsLeftUntouched()
}//end class
