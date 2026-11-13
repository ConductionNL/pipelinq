<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\CallReview
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

namespace OCA\Pipelinq\Tests\Unit\Service\CallReview;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CallReview\CallSummaryWriter;
use OCA\Pipelinq\Service\CallReview\CallTranscriptionService;
use OCA\Pipelinq\Service\TicketService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A recorded call is transcribed into its contact moment, and its audio never stays.
 *
 * @covers \OCA\Pipelinq\Service\CallReview\CallTranscriptionService
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
 */
class CallTranscriptionServiceTest extends TestCase {

	/**
	 * The moment every test judges at.
	 */
	private const NOW = '2026-11-13T12:00:00+00:00';

	/**
	 * The saves made, as [object, uuid, rbac].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * The temporary files written, as [name, content].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $written = [];

	/**
	 * How many temporary files were deleted.
	 *
	 * @var int
	 */
	private int $deleted = 0;

	/**
	 * The tasks run.
	 *
	 * @var array<int, Task>
	 */
	private array $tasks = [];

	/**
	 * The service over a stored contact moment and a speech-to-text outcome.
	 *
	 * @param array<string, mixed>|null $ticket    The stored ticket, or null when it cannot be read.
	 * @param array<int, string>        $taskTypes The available task types.
	 * @param string|null               $text      What speech-to-text answers; null when the task fails.
	 * @param string                    $summary   What hermiq answers.
	 * @param bool                      $folderExists Whether the temporary folder exists.
	 *
	 * @return CallTranscriptionService
	 */
	private function service(?array $ticket, array $taskTypes = ['core:audio2text'], ?string $text = 'Goedemiddag, ik bel over mijn afvalpas.', string $summary = 'Afvalpas kwijt.', bool $folderExists = true): CallTranscriptionService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(function () use ($ticket): ?ObjectEntityInterface {
			if ($ticket === null) {
				throw new RuntimeException('gone');
			}

			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($ticket);
			return $entity;
		});
		$objects->method('saveObject')->willReturnCallback(function (...$args): ObjectEntityInterface {
			// Positional: object, extend, register, schema, uuid, _rbac, _multitenancy.
			$this->saved[] = [$args[0], $args[4], $args[5]];
			return $this->createMock(ObjectEntityInterface::class);
		});

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('getObjectService')->willReturn($objects);
		$tickets->method('getRegisterId')->willReturn('r');
		$tickets->method('getSchemaId')->willReturn('ticket');
		$tickets->method('sanitizeForSave')->willReturnArgument(0);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('RIFF-audio');
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(4242);
		$file->method('delete')->willReturnCallback(function (): void {
			$this->deleted++;
		});
		$folder = $this->createMock(Folder::class);
		$folder->method('newFile')->willReturnCallback(function (string $name, $content) use ($file): File {
			$this->written[] = [$name, $content];
			return $file;
		});
		$root = $this->createMock(IRootFolder::class);
		$root->method('getAppDataDirectoryName')->willReturn('appdata_x');
		if ($folderExists === true) {
			$root->method('get')->with('appdata_x/pipelinq/call-transcription')->willReturn($folder);
		} else {
			$root->method('get')->willThrowException(new NotFoundException());
			$root->expects($this->once())->method('newFolder')->with('appdata_x/pipelinq/call-transcription')->willReturn($folder);
		}

		$manager = $this->createMock(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn($taskTypes);
		$manager->method('runTask')->willReturnCallback(function (Task $task) use ($text): Task {
			$this->tasks[] = $task;
			if ($text === null) {
				$task->setStatus(Task::STATUS_FAILED);
				return $task;
			}

			$task->setStatus(Task::STATUS_SUCCESSFUL);
			$task->setOutput(['output' => "  $text  "]);
			return $task;
		});

		$writer = $this->createMock(CallSummaryWriter::class);
		$writer->method('summarise')->willReturn($summary);

		return new CallTranscriptionService($tickets, $clients, $root, $manager, $writer, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A recorded contact moment.
	 *
	 * @param array<string, mixed> $extra Extra fields.
	 *
	 * @return array<string, mixed>
	 */
	private function call(array $extra = []): array {
		return array_merge(['id' => 'cm-1', 'ticketType' => 'contactmoment', 'assignee' => 'anna', 'recording_url' => 'https://rec/1', 'recording_retention_expires_at' => '2026-12-01T00:00:00Z'], $extra);
	}//end call()

	/**
	 * Transcribe cm-1 at NOW.
	 *
	 * @param CallTranscriptionService $service The service.
	 *
	 * @return string
	 */
	private function transcribe(CallTranscriptionService $service): string {
		return $service->transcribe('cm-1', new DateTimeImmutable(self::NOW));
	}//end transcribe()

	/**
	 * The transcript and summary are written as the system, and the temporary audio is gone.
	 *
	 * @return void
	 */
	public function testACallIsTranscribedAndSummarised(): void {
		$this->assertSame('done', $this->transcribe($this->service($this->call())));

		$this->assertSame('core:audio2text', $this->tasks[0]->getTaskTypeId());
		$this->assertSame(['input' => 4242], $this->tasks[0]->getInput());
		$this->assertSame('pipelinq', $this->tasks[0]->getAppId());
		$this->assertSame('RIFF-audio', $this->written[0][1]);
		$this->assertMatchesRegularExpression('/^cm-1-[0-9a-f]{12}\.audio$/', $this->written[0][0]);
		$this->assertSame(1, $this->deleted);
		$this->assertSame('Goedemiddag, ik bel over mijn afvalpas.', $this->saved[0][0]['transcript']);
		$this->assertSame('done', $this->saved[0][0]['transcriptStatus']);
		$this->assertSame('Afvalpas kwijt.', $this->saved[0][0]['callSummary']);
		$this->assertSame(['cm-1', false], [$this->saved[0][1], $this->saved[0][2]]);
	}//end testACallIsTranscribedAndSummarised()

	/**
	 * Without hermiq the summary stays empty, and an agent's own summary is never overwritten.
	 *
	 * @return void
	 */
	public function testTheSummaryIsLeftAloneWhenThereIsNoneOrTheAgentWroteOne(): void {
		$this->transcribe($this->service($this->call(), summary: ''));
		$this->assertArrayNotHasKey('callSummary', $this->saved[0][0]);

		$this->transcribe($this->service($this->call(['callSummary' => 'Mijn eigen samenvatting'])));
		$this->assertSame('Mijn eigen samenvatting', $this->saved[1][0]['callSummary']);
	}//end testTheSummaryIsLeftAloneWhenThereIsNoneOrTheAgentWroteOne()

	/**
	 * A failed task is status failed, and the temporary audio is still deleted.
	 *
	 * @return void
	 */
	public function testAFailedTranscriptionDeletesTheAudio(): void {
		$this->assertSame('failed', $this->transcribe($this->service($this->call(), text: null, folderExists: false)));

		$this->assertSame(1, $this->deleted);
		$this->assertSame('failed', $this->saved[0][0]['transcriptStatus']);
		$this->assertArrayNotHasKey('transcript', $this->saved[0][0]);
	}//end testAFailedTranscriptionDeletesTheAudio()

	/**
	 * Without a speech-to-text provider the status is unavailable, and nothing is downloaded.
	 *
	 * @return void
	 */
	public function testWithoutAProviderTheStatusIsUnavailable(): void {
		$this->assertSame('unavailable', $this->transcribe($this->service($this->call(), taskTypes: ['core:text2text'])));

		$this->assertSame([], $this->written);
		$this->assertSame(0, $this->deleted);
		$this->assertSame('unavailable', $this->saved[0][0]['transcriptStatus']);
	}//end testWithoutAProviderTheStatusIsUnavailable()

	/**
	 * A recording past its retention, or none at all, is status expired.
	 *
	 * @return void
	 */
	public function testAnExpiredRecordingIsSaidSo(): void {
		$this->assertSame('expired', $this->transcribe($this->service($this->call(['recording_retention_expires_at' => '2026-11-01T00:00:00Z']))));
		$this->assertSame('expired', $this->transcribe($this->service($this->call(['recording_url' => '']))));

		$this->assertSame([], $this->tasks);
		$this->assertSame(['expired', 'expired'], array_map(static fn (array $s): string => $s[0]['transcriptStatus'], $this->saved));
	}//end testAnExpiredRecordingIsSaidSo()

	/**
	 * A contact moment that cannot be read is left alone.
	 *
	 * @return void
	 */
	public function testAnUnreadableTicketIsLeftAlone(): void {
		$this->assertSame('', $this->transcribe($this->service(null)));
		$this->assertSame('', $this->service($this->call())->transcribe(''));
		$this->assertSame([], $this->saved);
	}//end testAnUnreadableTicketIsLeftAlone()
}//end class
