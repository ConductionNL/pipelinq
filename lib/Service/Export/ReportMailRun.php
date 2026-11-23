<?php

/**
 * Pipelinq ReportMailRun.
 *
 * One run of an export job delivered by mail: every recipient gets the rows
 * they may read themselves, and a failed send is told to the job's owner.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\Service\NotificationService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a mail destination's users and groups to people, extracts the
 * job's schemas once per person while acting as that person, so
 * OpenRegister's access rules filter the rows, mails each their own files,
 * and notifies the job's owner when a send fails (REQ-RPD-002).
 */
class ReportMailRun {

	/**
	 * Notification subject for a mail delivery that failed for someone.
	 */
	public const SUBJECT_FAILED = 'export_mail_delivery_failed';

	/**
	 * The MIME type of each export format.
	 */
	private const MIME_TYPES = [
		'csv'     => 'text/csv',
		'jsonl'   => 'application/x-ndjson',
		'parquet' => 'application/vnd.apache.parquet',
	];

	/**
	 * Constructor.
	 *
	 * @param IUserManager        $userManager   Finds the recipients.
	 * @param IGroupManager       $groupManager  Resolves recipient groups to their members.
	 * @param IUserSession        $userSession   Acts as each recipient while their rows are read.
	 * @param ExportDataService   $data          Extracts and formats a schema.
	 * @param ReportMailDelivery  $delivery      Sends the mail.
	 * @param NotificationService $notifications Tells the owner about a failed send.
	 * @param LoggerInterface     $logger        The logger.
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#requirement-a-scheduled-report-can-be-delivered-by-mail-req-rpd-002
	 */
	public function __construct(
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private ExportDataService $data,
		private ReportMailDelivery $delivery,
		private NotificationService $notifications,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The people a mail destination reaches: each named user, and every
	 * member of each named group, once, in the order named.
	 *
	 * @param mixed $recipients The destination's recipients: `[{type: user|group, id}]`.
	 *
	 * @return list<string> The user ids.
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#requirement-a-scheduled-report-can-be-delivered-by-mail-req-rpd-002
	 */
	public function recipientIds(mixed $recipients): array {
		$ids = [];
		if (is_array($recipients) === false) {
			return [];
		}

		foreach ($recipients as $recipient) {
			foreach ($this->idsOf(recipient: (array)$recipient) as $uid) {
				$ids[$uid] = true;
			}
		}

		return array_keys($ids);
	}//end recipientIds()

	/**
	 * Run a mail delivery: one mail per person with the rows that person may
	 * read. The outcome is shaped for ExportRunService::completeRun(): the
	 * run succeeded when every mail went out, failed when none did, and is
	 * partial otherwise; the manifest lists each person's outcome.
	 *
	 * @param array<string, mixed> $job         The export job.
	 * @param array<string, mixed> $destination The mail destination.
	 *
	 * @return array<string, mixed> The run outcome.
	 *
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#requirement-a-scheduled-report-can-be-delivered-by-mail-req-rpd-002
	 */
	public function deliver(array $job, array $destination): array {
		$outcomes = [];
		$rows = 0;
		$bytes = 0;
		$files = 0;
		foreach ($this->recipientIds(recipients: $destination['recipients'] ?? []) as $uid) {
			$sent = $this->deliverTo(job: $job, userId: $uid);
			$outcomes[] = $sent['outcome'];
			$rows += $sent['rows'];
			$bytes += $sent['bytes'];
			$files += $sent['files'];
		}

		$failed = array_values(array_filter($outcomes, static fn (array $sent): bool => $sent['status'] !== ReportMailDelivery::DELIVERED));
		if ($failed !== []) {
			$this->notifyOwner(job: $job, failed: $failed);
		}

		return [
			'status'       => $this->statusOf(total: count($outcomes), failed: count($failed)),
			'rowCount'     => $rows,
			'byteCount'    => $bytes,
			'fileCount'    => $files,
			'manifest'     => ['mail' => $outcomes],
			'errorMessage' => $this->reasonOf(failed: $failed),
		];
	}//end deliver()

	/**
	 * Extract the job's schemas as one person and mail them the result.
	 *
	 * @param array<string, mixed> $job    The export job.
	 * @param string               $userId The person.
	 *
	 * @return array{outcome: array{userId: string, status: string, reason: string}, rows: int, bytes: int, files: int}
	 */
	private function deliverTo(array $job, string $userId): array {
		$none = ['rows' => 0, 'bytes' => 0, 'files' => 0];
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return ['outcome' => ['userId' => $userId, 'status' => ReportMailDelivery::FAILED, 'reason' => 'The recipient does not exist.']] + $none;
		}

		try {
			$extracted = $this->asUser(user: $user, operation: fn (): array => $this->extract(job: $job));
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: a mailed report could not be read for its recipient', ['userId' => $userId, 'exception' => $e->getMessage()]);
			return ['outcome' => ['userId' => $userId, 'status' => ReportMailDelivery::FAILED, 'reason' => $e->getMessage()]] + $none;
		}

		$outcome = $this->delivery->deliverFiles(job: $job, userId: $userId, files: $this->attachmentsOf(job: $job, files: $extracted));

		return [
			'outcome' => $outcome,
			'rows'    => array_sum(array_map(static fn (array $file): int => (int)($file['rows'] ?? 0), $extracted)),
			'bytes'   => array_sum(array_map(static fn (array $file): int => strlen((string)($file['contents'] ?? '')), $extracted)),
			'files'   => count($extracted),
		];
	}//end deliverTo()

	/**
	 * Run an operation as a user, restoring whoever acted before.
	 *
	 * @param IUser    $user      The user to act as.
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returns.
	 */
	private function asUser(IUser $user, callable $operation): mixed {
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($user);
		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end asUser()

	/**
	 * Every source schema of the job, extracted and formatted in full.
	 *
	 * @param array<string, mixed> $job The export job.
	 *
	 * @return list<array<string, mixed>> The files.
	 */
	private function extract(array $job): array {
		$files = [];
		foreach ((array)($job['sourceSchemas'] ?? []) as $slug) {
			$files[] = $this->data->extractSchema(job: $job, schemaSlug: (string)$slug, watermarkFrom: null, rowCap: null);
		}

		return $files;
	}//end extract()

	/**
	 * The mail attachments of the extracted files: `<job>-<schema>.<format>`,
	 * with `.gz` when compressed.
	 *
	 * @param array<string, mixed>        $job   The export job.
	 * @param list<array<string, mixed>> $files The extracted files.
	 *
	 * @return list<array{contents: string, filename: string, mimeType: string}>
	 */
	private function attachmentsOf(array $job, array $files): array {
		$format = (string)($job['format'] ?? 'csv');
		if (isset(self::MIME_TYPES[$format]) === false) {
			$format = 'csv';
		}

		$base = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', strtolower((string)($job['name'] ?? 'report'))), '-');
		$attachments = [];
		if ($base === '') {
			$base = 'report';
		}

		foreach ($files as $file) {
			$name = $base.'-'.(string)($file['schema'] ?? 'data').'.'.$format;
			$mime = self::MIME_TYPES[$format];
			if (($file['compression_used'] ?? 'none') === 'gzip') {
				$name .= '.gz';
				$mime = 'application/gzip';
			}

			$attachments[] = ['contents' => (string)($file['contents'] ?? ''), 'filename' => $name, 'mimeType' => $mime];
		}

		return $attachments;
	}//end attachmentsOf()

	/**
	 * The user ids of one recipient entry; an unknown user or group gives none.
	 *
	 * @param array<string, mixed> $recipient `{type: user|group, id}`.
	 *
	 * @return list<string>
	 */
	private function idsOf(array $recipient): array {
		$id = trim((string)($recipient['id'] ?? ''));
		if ($id === '') {
			return [];
		}

		if (($recipient['type'] ?? 'user') !== 'group') {
			return [$id];
		}

		$group = $this->groupManager->get($id);
		if ($group === null) {
			return [];
		}

		return array_map(static fn (IUser $member): string => $member->getUID(), array_values($group->getUsers()));
	}//end idsOf()

	/**
	 * The run status for the number of mails and of failed ones.
	 *
	 * @param int $total  Mails attempted.
	 * @param int $failed Mails that failed.
	 *
	 * @return string `succeeded`, `partial` or `failed`.
	 */
	private function statusOf(int $total, int $failed): string {
		if ($total === 0 || $failed === $total) {
			return 'failed';
		}

		if ($failed === 0) {
			return 'succeeded';
		}

		return 'partial';
	}//end statusOf()

	/**
	 * The run's error message: each failed recipient with the reason, or null.
	 *
	 * @param list<array{userId: string, status: string, reason: string}> $failed The failed sends.
	 *
	 * @return string|null
	 */
	private function reasonOf(array $failed): ?string {
		if ($failed === []) {
			return null;
		}

		return implode('; ', array_map(static fn (array $sent): string => $sent['userId'].': '.$sent['reason'], $failed));
	}//end reasonOf()

	/**
	 * Tell the job's owner which mails failed and why.
	 *
	 * @param array<string, mixed>                                         $job    The export job.
	 * @param list<array{userId: string, status: string, reason: string}> $failed The failed sends.
	 *
	 * @return void
	 */
	private function notifyOwner(array $job, array $failed): void {
		$owner = trim((string)($job['createdBy'] ?? ''));
		if ($owner === '') {
			return;
		}

		try {
			$this->notifications->sendNotification(
				userId: $owner,
				subject: self::SUBJECT_FAILED,
				parameters: [
					'title'  => (string)($job['name'] ?? ''),
					'failed' => count($failed),
					'error'  => (string)$this->reasonOf(failed: $failed),
				],
				objectType: 'exportJob',
				objectId: (string)($job['id'] ?? $job['uuid'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: the owner of a mailed report could not be told it failed', ['exception' => $e->getMessage()]);
		}
	}//end notifyOwner()
}//end class
