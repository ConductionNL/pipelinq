<?php

/**
 * What BerichtenboxZaakStatusListener hands to the queue.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-OUTBOUND-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A case status change is queued with the case, its contact moment, the new
 * status, the BSN and the case's type, language and attachments. Each queue
 * call is recorded as one flat map of named values.
 *
 * @covers \OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\CaseStatusMessage
 * @uses   \OCA\Pipelinq\Service\Berichtenbox\MessageOptions
 */
class BerichtenboxZaakStatusListenerQueueTest extends TestCase {
	/**
	 * The queue calls the listener made, flattened by parameter or property name.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queued = [];

	/**
	 * Run the listener on one case update.
	 *
	 * @param array<string, mixed> $new     The case after the change.
	 * @param bool                 $refuses Whether the queue throws.
	 *
	 * @return void
	 */
	private function handle(array $new, bool $refuses = false): void {
		$this->queued = [];
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'zaak_schema' ? 'sch-case' : $default)
		);

		$params = (new ReflectionMethod(BerichtenboxService::class, 'queueOutboundMessage'))->getParameters();
		$service = $this->createMock(originalClassName: BerichtenboxService::class);
		$service->method('queueOutboundMessage')->willReturnCallback(
			function (...$args) use ($params, $refuses): array {
				$flat = [];
				foreach ($args as $i => $arg) {
					if (is_object($arg) === true) {
						$flat = [...$flat, ...get_object_vars($arg)];
						continue;
					}

					$flat[$params[$i]->getName()] = $arg;
				}

				$this->queued[] = $flat;
				if ($refuses === true) {
					throw new \RuntimeException('queue refused');
				}

				return [];
			}
		);

		$listener = new BerichtenboxZaakStatusListener(
			appConfig: $config,
			berichtenbox: $service,
			ticketService: $this->createMock(originalClassName: TicketService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$listener->handle(event: new ObjectUpdatedEvent(newObject: $this->entity(data: $new), oldObject: $this->entity(data: ['status' => 'received'])));
	}//end handle()

	/**
	 * A case object on the case schema.
	 *
	 * @param array<string, mixed> $data The case data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('sch-case');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A case with every field is queued with all of them.
	 *
	 * @return void
	 */
	public function testAFullCaseIsQueuedWithItsFields(): void {
		$attachment = ['filename' => 'a.pdf', 'mime' => 'application/pdf', 'sizeBytes' => 10, 'openregisterFileRef' => 'f-1'];
		$this->handle(new: ['id' => 'Z-1', 'uuid' => 'u-1', 'interactionId' => 'cm-1', 'status' => 'handled', 'bsn' => '123456782', 'caseType' => 'paspoort', 'language' => 'en', 'attachments' => [$attachment]]);

		$this->assertSame(
			expected: [[
				'caseId' => 'Z-1',
				'interactionId' => 'cm-1',
				'status' => 'handled',
				'bsn' => '123456782',
				'templateOverride' => null,
				'extraVariables' => ['caseType' => 'paspoort', 'language' => 'en'],
				'attachments' => [$attachment],
			],
			],
			actual: $this->queued
		);
	}//end testAFullCaseIsQueuedWithItsFields()

	/**
	 * A case with only a uuid and a BSN is queued with the defaults.
	 *
	 * @return void
	 */
	public function testASparseCaseIsQueuedWithTheDefaults(): void {
		$this->handle(new: ['uuid' => 'u-1', 'status' => 'rejected', 'bsn' => '123456782']);

		$this->assertSame(
			expected: [[
				'caseId' => 'u-1',
				'interactionId' => '',
				'status' => 'rejected',
				'bsn' => '123456782',
				'templateOverride' => null,
				'extraVariables' => ['caseType' => '', 'language' => 'nl'],
				'attachments' => [],
			],
			],
			actual: $this->queued
		);
	}//end testASparseCaseIsQueuedWithTheDefaults()

	/**
	 * Attachments that are not a list stop the queue call, and the listener
	 * swallows the error.
	 *
	 * @return void
	 */
	public function testAttachmentsThatAreNotAListAreNotQueued(): void {
		$this->handle(new: ['id' => 'Z-1', 'status' => 'handled', 'bsn' => '123456782', 'attachments' => 'a.pdf']);

		$this->assertSame(expected: [], actual: $this->queued);
	}//end testAttachmentsThatAreNotAListAreNotQueued()

	/**
	 * A queue that refuses does not escape the listener.
	 *
	 * @return void
	 */
	public function testARefusedQueueDoesNotEscape(): void {
		$this->handle(new: ['id' => 'Z-1', 'status' => 'handled', 'bsn' => '123456782'], refuses: true);

		$this->assertCount(expectedCount: 1, haystack: $this->queued);
	}//end testARefusedQueueDoesNotEscape()
}//end class
