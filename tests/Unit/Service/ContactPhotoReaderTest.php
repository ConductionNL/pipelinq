<?php

/**
 * Unit tests for ContactPhotoReader.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContactPhotoReader;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A card photo goes through the OCR task as a short-lived file in the user's
 * storage, and that file is gone afterwards whatever happened.
 *
 * @covers \OCA\Pipelinq\Service\ContactPhotoReader
 */
class ContactPhotoReaderTest extends TestCase {

	/**
	 * The task processing double.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $manager;

	/**
	 * The temporary photo double.
	 *
	 * @var File&MockObject
	 */
	private File&MockObject $file;

	/**
	 * The hidden folder double.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $folder;

	/**
	 * The user's home folder double.
	 *
	 * @var Folder&MockObject
	 */
	private Folder&MockObject $home;

	/**
	 * What was written to the temporary file, by name.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Set up the doubles: a user folder whose hidden folder takes one file, id 42.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$this->file    = $this->createMock(File::class);
		$this->file->method('getId')->willReturn(42);
		$this->folder = $this->createMock(Folder::class);
		$this->folder->method('newFile')->willReturnCallback(function (string $name, $content): File {
			$this->written[$name] = (string) $content;
			return $this->file;
		});
		$this->home = $this->createMock(Folder::class);
		$this->home->method('get')->with(ContactPhotoReader::TEMP_FOLDER)->willReturn($this->folder);
	}//end setUp()

	/**
	 * The reader over the doubles.
	 *
	 * @return ContactPhotoReader The reader.
	 */
	private function reader(): ContactPhotoReader {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('rep')->willReturn($this->home);

		return new ContactPhotoReader(taskProcessing: $this->manager, rootFolder: $root, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * Make the OCR task type available, or not.
	 *
	 * @param bool $available Whether a provider serves it.
	 *
	 * @return void
	 */
	private function available(bool $available): void {
		$types = ['core:text2text' => []];
		if ($available === true) {
			$types[ContactPhotoReader::TASK_TYPE] = [];
		}

		$this->manager->method('getAvailableTaskTypes')->willReturn($types);
	}//end available()

	/**
	 * Availability follows the available task types, and an error reads as unavailable.
	 *
	 * @return void
	 */
	public function testAvailabilityFollowsTheTaskTypes(): void {
		$this->manager->expects($this->exactly(3))->method('getAvailableTaskTypes')->with(false, 'rep')->willReturnOnConsecutiveCalls(
			[ContactPhotoReader::TASK_TYPE => []],
			['core:text2text' => []],
			$this->throwException(new RuntimeException('down')),
		);

		$reader = $this->reader();
		$this->assertTrue($reader->isAvailable(userId: 'rep'));
		$this->assertFalse($reader->isAvailable(userId: 'rep'));
		$this->assertFalse($reader->isAvailable(userId: 'rep'));
	}//end testAvailabilityFollowsTheTaskTypes()

	/**
	 * Available: the photo becomes a file in the hidden folder, the OCR task reads it as the
	 * user, its texts come back one per line, and the file and the task are deleted.
	 *
	 * @return void
	 */
	public function testAReadPhotoAnswersItsTextAndKeepsNothing(): void {
		$this->available(available: true);
		$ran = null;
		$this->manager->expects($this->once())->method('runTask')->willReturnCallback(function (Task $task) use (&$ran): Task {
			$ran = $task;
			$task->setId(7);
			$task->setStatus(Task::STATUS_SUCCESSFUL);
			$task->setOutput(['output' => ['  Anna de Vries  ', '', 'anna@voorbeeld.nl']]);
			return $task;
		});
		$this->file->expects($this->once())->method('delete');
		$this->manager->expects($this->once())->method('deleteTask')->with($this->callback(static fn (Task $task): bool => $task->getId() === 7));

		$result = $this->reader()->read(bytes: 'jpeg-bytes', userId: 'rep');

		$this->assertSame(['status' => 'done', 'text' => "Anna de Vries\nanna@voorbeeld.nl"], $result);
		$this->assertInstanceOf(Task::class, $ran);
		$this->assertSame(ContactPhotoReader::TASK_TYPE, $ran->getTaskTypeId());
		$this->assertSame(['input' => [42]], $ran->getInput());
		$this->assertSame('rep', $ran->getUserId());
		$this->assertSame('pipelinq', $ran->getAppId());
		$this->assertSame(['jpeg-bytes'], array_values($this->written));
		$this->assertMatchesRegularExpression('/^card-[0-9a-f]{16}\.img$/', (string) array_key_first($this->written));
	}//end testAReadPhotoAnswersItsTextAndKeepsNothing()

	/**
	 * Unavailable: nothing is written and no task is run.
	 *
	 * @return void
	 */
	public function testUnavailableWritesNothing(): void {
		$this->available(available: false);
		$this->manager->expects($this->never())->method('runTask');
		$this->folder->expects($this->never())->method('newFile');

		$this->assertSame(['status' => 'unavailable', 'text' => ''], $this->reader()->read(bytes: 'jpeg-bytes', userId: 'rep'));
	}//end testUnavailableWritesNothing()

	/**
	 * A task that ends as failed is a failure, and the file is still deleted.
	 *
	 * @return void
	 */
	public function testAFailedTaskIsAFailureAndTheFileIsDeleted(): void {
		$this->available(available: true);
		$this->manager->method('runTask')->willReturnCallback(static function (Task $task): Task {
			$task->setStatus(Task::STATUS_FAILED);
			$task->setErrorMessage('provider crashed');
			return $task;
		});
		$this->file->expects($this->once())->method('delete');
		$this->manager->expects($this->never())->method('deleteTask');

		$this->assertSame(['status' => 'failed', 'text' => ''], $this->reader()->read(bytes: 'jpeg-bytes', userId: 'rep'));
	}//end testAFailedTaskIsAFailureAndTheFileIsDeleted()

	/**
	 * A task that throws (no synchronous provider, a validation error) is a failure, and the
	 * file is still deleted; a failing delete is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAThrowingTaskIsAFailureAndTheFileIsDeleted(): void {
		$this->available(available: true);
		$this->manager->method('runTask')->willThrowException(new RuntimeException('no synchronous provider'));
		$this->file->expects($this->once())->method('delete')->willThrowException(new RuntimeException('locked'));

		$this->assertSame(['status' => 'failed', 'text' => ''], $this->reader()->read(bytes: 'jpeg-bytes', userId: 'rep'));
	}//end testAThrowingTaskIsAFailureAndTheFileIsDeleted()

	/**
	 * A successful task without text is empty, and a malformed output is a failure; both delete the file.
	 *
	 * @return void
	 */
	public function testNoTextIsEmptyAndAMalformedOutputFails(): void {
		$this->available(available: true);
		$this->manager->method('runTask')->willReturnOnConsecutiveCalls(
			$this->finished(output: ['output' => ['  ', '']]),
			$this->finished(output: ['other' => 'x']),
			$this->finished(output: ['output' => 'Single text']),
		);
		$this->file->expects($this->exactly(3))->method('delete');

		$reader = $this->reader();
		$this->assertSame(['status' => 'empty', 'text' => ''], $reader->read(bytes: 'a', userId: 'rep'));
		$this->assertSame(['status' => 'failed', 'text' => ''], $reader->read(bytes: 'a', userId: 'rep'));
		$this->assertSame(['status' => 'done', 'text' => 'Single text'], $reader->read(bytes: 'a', userId: 'rep'));
	}//end testNoTextIsEmptyAndAMalformedOutputFails()

	/**
	 * The hidden folder is created when it is not there yet.
	 *
	 * @return void
	 */
	public function testTheHiddenFolderIsCreatedWhenAbsent(): void {
		$this->available(available: true);
		$home = $this->createMock(Folder::class);
		$home->method('get')->willThrowException(new NotFoundException());
		$home->expects($this->once())->method('newFolder')->with('.pipelinq-contact-capture')->willReturn($this->folder);
		$this->home = $home;
		$this->manager->method('runTask')->willReturn($this->finished(output: ['output' => ['Text']]));
		$this->file->expects($this->once())->method('delete');

		$this->assertSame(['status' => 'done', 'text' => 'Text'], $this->reader()->read(bytes: 'a', userId: 'rep'));
	}//end testTheHiddenFolderIsCreatedWhenAbsent()

	/**
	 * When the storage hands back something that is not a file, nothing is run and nothing is deleted.
	 *
	 * @return void
	 */
	public function testANonFileIsAFailure(): void {
		$this->available(available: true);
		$folder = $this->createMock(Folder::class);
		$folder->method('newFile')->willReturn($this->createMock(Folder::class));
		$this->home = $this->createMock(Folder::class);
		$this->home->method('get')->willReturn($folder);
		$this->manager->expects($this->never())->method('runTask');

		$this->assertSame(['status' => 'failed', 'text' => ''], $this->reader()->read(bytes: 'a', userId: 'rep'));
	}//end testANonFileIsAFailure()

	/**
	 * A finished, successful task with an output.
	 *
	 * @param array<string, mixed> $output The output.
	 *
	 * @return Task The task.
	 */
	private function finished(array $output): Task {
		$task = new Task(ContactPhotoReader::TASK_TYPE, ['input' => [42]], 'pipelinq', 'rep');
		$task->setStatus(Task::STATUS_SUCCESSFUL);
		$task->setOutput($output);
		return $task;
	}//end finished()
}//end class
