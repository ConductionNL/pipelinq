<?php

/**
 * Pipelinq CallTranscriptionService.
 *
 * Transcribes a recorded call's audio into its contact moment, without ever
 * keeping the audio.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\CallReview
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\CallReview;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Design D1. The recording stays on the telephony platform: its audio is
 * fetched into a temporary file only for the length of the transcription, and
 * that file is deleted in a `finally` whatever happens. The outcome is always
 * written as `transcriptStatus`, so a call that could not be transcribed says
 * why: its recording expired, no speech-to-text provider is installed, or the
 * transcription failed.
 *
 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
 */
class CallTranscriptionService {
	use PayloadToArray;

	/**
	 * The app-config switch; off unless an administrator turns it on.
	 */
	public const ENABLED_KEY = 'call_transcription_enabled';

	/**
	 * Nextcloud's speech-to-text task type (OCP\TaskProcessing\TaskTypes\AudioToText::ID).
	 */
	public const TASK_TYPE = 'core:audio2text';

	/**
	 * Where the audio waits while it is transcribed, inside pipelinq's app data.
	 */
	public const TEMP_FOLDER = 'pipelinq/call-transcription';

	public const STATUS_PENDING     = 'pending';
	public const STATUS_DONE        = 'done';
	public const STATUS_FAILED      = 'failed';
	public const STATUS_UNAVAILABLE = 'unavailable';
	public const STATUS_EXPIRED     = 'expired';

	/**
	 * Constructor.
	 *
	 * @param TicketService      $ticketService  The ticket schema and OpenRegister.
	 * @param IClientService     $clientService  Fetches the recording.
	 * @param IRootFolder        $rootFolder     Holds the temporary audio file.
	 * @param IManager           $taskProcessing Nextcloud's TaskProcessing.
	 * @param CallSummaryWriter  $summaryWriter  Summarises the transcript through hermiq.
	 * @param LoggerInterface    $logger         PSR logger.
	 */
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly IClientService $clientService,
		private readonly IRootFolder $rootFolder,
		private readonly IManager $taskProcessing,
		private readonly CallSummaryWriter $summaryWriter,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Transcribe one contact moment's recording and store the outcome on it.
	 *
	 * @param string                 $ticketId The contact moment ticket's id.
	 * @param DateTimeImmutable|null $now      The moment to judge the retention at.
	 *
	 * @return string The status written, or '' when the ticket could not be read.
	 *
	 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
	 */
	public function transcribe(string $ticketId, ?DateTimeImmutable $now = null): string {
		$ticket = $this->ticket(ticketId: $ticketId);
		if ($ticket === null) {
			return '';
		}

		$fields = $this->outcome(ticket: $ticket, now: ($now ?? new DateTimeImmutable('now')));
		$this->write(ticket: $ticket, fields: $fields);

		return $fields['transcriptStatus'];
	}//end transcribe()

	/**
	 * The fields the transcription leaves on the ticket.
	 *
	 * @param array<string,mixed> $ticket The contact moment.
	 * @param DateTimeImmutable   $now    The moment to judge the retention at.
	 *
	 * @return array<string,string>
	 */
	private function outcome(array $ticket, DateTimeImmutable $now): array {
		$url = trim((string) ($ticket['recording_url'] ?? ''));
		if ($url === '' || $this->expired(ticket: $ticket, now: $now) === true) {
			return ['transcriptStatus' => self::STATUS_EXPIRED];
		}

		if (in_array(self::TASK_TYPE, $this->taskProcessing->getAvailableTaskTypeIds(), true) === false) {
			return ['transcriptStatus' => self::STATUS_UNAVAILABLE];
		}

		$file = null;
		try {
			$file       = $this->download(url: $url, ticketId: (string) ($ticket['id'] ?? ''));
			$transcript = $this->speechToText(file: $file, ticket: $ticket);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CallTranscriptionService: the call was not transcribed',
				['ticket' => ($ticket['id'] ?? ''), 'exception' => $e->getMessage()]
			);
			return ['transcriptStatus' => self::STATUS_FAILED];
		} finally {
			$this->discard(file: $file);
		}

		$fields = ['transcript' => $transcript, 'transcriptStatus' => self::STATUS_DONE];
		$summary = $this->summaryWriter->summarise(transcript: $transcript, userId: ($ticket['assignee'] ?? null));
		if ($summary !== '' && trim((string) ($ticket['callSummary'] ?? '')) === '') {
			$fields['callSummary'] = $summary;
		}

		return $fields;
	}//end outcome()

	/**
	 * Whether the platform has already deleted the recording.
	 *
	 * @param array<string,mixed> $ticket The contact moment.
	 * @param DateTimeImmutable   $now    The moment to judge at.
	 *
	 * @return bool
	 */
	private function expired(array $ticket, DateTimeImmutable $now): bool {
		$until = trim((string) ($ticket['recording_retention_expires_at'] ?? ''));
		if ($until === '') {
			return false;
		}

		try {
			return new DateTimeImmutable($until) <= $now;
		} catch (Throwable) {
			return false;
		}
	}//end expired()

	/**
	 * Fetch the recording into a temporary file in pipelinq's app data.
	 *
	 * @param string $url      The recording's URL.
	 * @param string $ticketId The ticket, for the file name.
	 *
	 * @return File
	 */
	private function download(string $url, string $ticketId): File {
		$audio = $this->clientService->newClient()->get($url, ['timeout' => 120])->getBody();
		if (is_string($audio) === false) {
			$audio = stream_get_contents($audio);
		}

		$file = $this->folder()->newFile(sprintf('%s-%s.audio', preg_replace('/[^A-Za-z0-9-]/', '', $ticketId), bin2hex(random_bytes(6))), (string) $audio);
		if (($file instanceof File) === false) {
			throw new \RuntimeException('The temporary audio file could not be created');
		}

		return $file;
	}//end download()

	/**
	 * Run the speech-to-text task on the file and read its text.
	 *
	 * @param File                $file   The audio.
	 * @param array<string,mixed> $ticket The contact moment.
	 *
	 * @return string
	 */
	private function speechToText(File $file, array $ticket): string {
		$task   = new Task(self::TASK_TYPE, ['input' => $file->getId()], Application::APP_ID, null, 'ticket:'.($ticket['id'] ?? ''));
		$done   = $this->taskProcessing->runTask($task);
		$output = $done->getOutput();
		if ($done->getStatus() !== Task::STATUS_SUCCESSFUL || is_array($output) === false || is_string($output['output'] ?? null) === false) {
			throw new \RuntimeException('The speech-to-text task did not succeed');
		}

		return trim($output['output']);
	}//end speechToText()

	/**
	 * The temporary folder, created when absent.
	 *
	 * @return Folder
	 */
	private function folder(): Folder {
		$path = $this->rootFolder->getAppDataDirectoryName().'/'.self::TEMP_FOLDER;
		try {
			$folder = $this->rootFolder->get($path);
		} catch (NotFoundException) {
			$folder = $this->rootFolder->newFolder($path);
		}

		if (($folder instanceof Folder) === false) {
			throw new \RuntimeException('The temporary audio folder is not a folder');
		}

		return $folder;
	}//end folder()

	/**
	 * Delete the temporary audio; a failure is logged, never thrown.
	 *
	 * @param File|null $file The audio, or null when none was written.
	 *
	 * @return void
	 */
	private function discard(?File $file): void {
		if ($file === null) {
			return;
		}

		try {
			$file->delete();
		} catch (Throwable $e) {
			$this->logger->error('CallTranscriptionService: the temporary audio could not be deleted', ['exception' => $e->getMessage()]);
		}
	}//end discard()

	/**
	 * The contact moment, read as the system; null when it cannot be read.
	 *
	 * @param string $ticketId The ticket's id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function ticket(string $ticketId): ?array {
		if ($ticketId === '' || $this->ticketService->isConfigured() === false) {
			return null;
		}

		try {
			$found = $this->ticketService->getObjectService()->find(
				id: $ticketId,
				register: $this->ticketService->getRegisterId(),
				schema: $this->ticketService->getSchemaId(),
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('CallTranscriptionService: the contact moment could not be read', ['ticket' => $ticketId, 'exception' => $e->getMessage()]);
			return null;
		}

		$ticket = $this->toArray(value: $found);
		if ($ticket === []) {
			return null;
		}

		$ticket['id'] = (string) ($ticket['id'] ?? $ticketId);
		return $ticket;
	}//end ticket()

	/**
	 * Store the outcome on the contact moment, as the system.
	 *
	 * @param array<string,mixed>  $ticket The contact moment.
	 * @param array<string,string> $fields The fields to write.
	 *
	 * @return void
	 */
	private function write(array $ticket, array $fields): void {
		$object = $this->ticketService->sanitizeForSave(payload: array_merge($ticket, $fields));
		unset($object['@self']);

		try {
			$this->ticketService->getObjectService()->saveObject(
				object: $object,
				register: $this->ticketService->getRegisterId(),
				schema: $this->ticketService->getSchemaId(),
				uuid: (string) $ticket['id'],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->error('CallTranscriptionService: the transcript could not be stored', ['ticket' => $ticket['id'], 'exception' => $e->getMessage()]);
		}
	}//end write()
}//end class
