<?php

/**
 * Pipelinq contact photo reader.
 *
 * Turns a photo of a business card into text through Nextcloud's optical
 * character recognition task (`core:image2text:ocr`), so the text can go
 * through the same field proposal as a pasted signature (D2).
 *
 * The recognition task takes a list of files, read as the user, so the photo
 * has to exist as a Nextcloud file while the task runs. It is written to a
 * hidden folder in the user's own storage, the task is run synchronously, and
 * the file and the task are deleted in a `finally` whatever the outcome.
 * Nothing goes to OpenRegister and nothing is kept.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactPhotoReaderInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads the text on a card photo through the OCR task, keeping nothing.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */
class ContactPhotoReader implements ContactPhotoReaderInterface {

	/**
	 * The hidden folder in the user's own storage the photo waits in while it is read.
	 *
	 * @var string
	 */
	public const TEMP_FOLDER = '.pipelinq-contact-capture';

	/**
	 * Constructor.
	 *
	 * @param IManager        $taskProcessing Nextcloud's task processing.
	 * @param IRootFolder     $rootFolder     The user's storage.
	 * @param LoggerInterface $logger         The logger.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
	 */
	public function __construct(
		private readonly IManager $taskProcessing,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a recognition provider serves this user.
	 *
	 * @param string|null $userId The user the task would run for.
	 *
	 * @return bool True when `core:image2text:ocr` is an available task type.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-no-recognition-provider
	 */
	public function isAvailable(?string $userId): bool {
		try {
			$types = $this->taskProcessing->getAvailableTaskTypes(false, $userId);
		} catch (Throwable $e) {
			$this->logger->info('ContactPhotoReader: the available task types could not be read', ['exception' => $e->getMessage()]);
			return false;
		}

		return array_key_exists(self::TASK_TYPE, $types);
	}//end isAvailable()

	/**
	 * Recognise the text on a photo, keeping nothing afterwards.
	 *
	 * @param string $bytes  The photo.
	 * @param string $userId The user the photo is read for.
	 *
	 * @return array{status: string, text: string} One of the STATUS_ constants and the recognised text.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-a-sales-rep-scans-a-card-at-a-fair
	 */
	public function read(string $bytes, string $userId): array {
		if ($this->isAvailable(userId: $userId) === false) {
			return ['status' => self::STATUS_UNAVAILABLE, 'text' => ''];
		}

		$file = null;
		$task = null;
		try {
			$created = $this->folder(userId: $userId)->newFile('card-'.bin2hex(random_bytes(8)).'.img', $bytes);
			if (($created instanceof File) === false) {
				throw new RuntimeException('The temporary photo file could not be created');
			}

			$file = $created;
			$task = $this->taskProcessing->runTask(new Task(self::TASK_TYPE, ['input' => [$file->getId()]], AppIdentity::APP_ID, $userId));
			$text = $this->text(task: $task);
		} catch (Throwable $e) {
			$this->logger->warning('ContactPhotoReader: the photo could not be read', ['exception' => $e->getMessage()]);
			return ['status' => self::STATUS_FAILED, 'text' => ''];
		} finally {
			$this->discard(file: $file, task: $task);
		}

		if ($text === '') {
			return ['status' => self::STATUS_EMPTY, 'text' => ''];
		}

		return ['status' => self::STATUS_DONE, 'text' => $text];
	}//end read()

	/**
	 * The text of a finished task; a task that did not succeed throws.
	 *
	 * @param Task $task The finished task.
	 *
	 * @return string The recognised text, one line per text the provider returned.
	 */
	private function text(Task $task): string {
		$output = $task->getOutput();
		if ($task->getStatus() !== Task::STATUS_SUCCESSFUL || is_array($output) === false) {
			throw new RuntimeException('The recognition task did not succeed: '.(string) $task->getErrorMessage());
		}

		$texts = $output['output'] ?? null;
		if (is_string($texts) === true) {
			$texts = [$texts];
		}

		if (is_array($texts) === false) {
			throw new RuntimeException('The recognition task returned no text list');
		}

		$parts = [];
		foreach ($texts as $part) {
			if (is_string($part) === true && trim($part) !== '') {
				$parts[] = trim($part);
			}
		}

		return implode("\n", $parts);
	}//end text()

	/**
	 * The hidden folder in the user's storage, created when absent.
	 *
	 * @param string $userId The user.
	 *
	 * @return Folder The folder.
	 */
	private function folder(string $userId): Folder {
		$home = $this->rootFolder->getUserFolder($userId);
		try {
			$folder = $home->get(self::TEMP_FOLDER);
		} catch (NotFoundException) {
			$folder = $home->newFolder(self::TEMP_FOLDER);
		}

		if (($folder instanceof Folder) === false) {
			throw new RuntimeException('The temporary photo folder is not a folder');
		}

		return $folder;
	}//end folder()

	/**
	 * Delete the temporary photo and the finished task; a failure is logged, never thrown.
	 *
	 * @param File|null $file The photo, or null when none was written.
	 * @param Task|null $task The task, or null when none ran.
	 *
	 * @return void
	 */
	private function discard(?File $file, ?Task $task): void {
		if ($file !== null) {
			try {
				$file->delete();
			} catch (Throwable $e) {
				$this->logger->error('ContactPhotoReader: the temporary photo could not be deleted', ['exception' => $e->getMessage()]);
			}
		}

		if ($task === null || $task->getId() === null) {
			return;
		}

		try {
			$this->taskProcessing->deleteTask($task);
		} catch (Throwable $e) {
			$this->logger->info('ContactPhotoReader: the finished task could not be deleted', ['exception' => $e->getMessage()]);
		}
	}//end discard()
}//end class
