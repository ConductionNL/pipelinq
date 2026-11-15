<?php

/**
 * The state of the last full export, for the page that starts one.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
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
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Remembers the last full export: queued, ready (with the archive's name and
 * file id) or failed, with when and for whom. One record for the instance,
 * kept in the app config; it holds no data from the archive.
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */
class FullExportState {

	/**
	 * The app config key of the record.
	 *
	 * @var string
	 */
	public const KEY = 'full_export_last';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig   $config The app config.
	 * @param ITimeFactory $time   The clock.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * An export was asked for.
	 *
	 * @param string $userId The administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function queued(string $userId): void {
		$this->write(record: ['state' => 'queued', 'userId' => $userId]);
	}//end queued()

	/**
	 * The archive is in the administrator's Files.
	 *
	 * @param string     $userId The administrator.
	 * @param string     $name   The archive's file name.
	 * @param string|int $fileId The archive's file id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function ready(string $userId, string $name, string|int $fileId): void {
		$this->write(record: ['state' => 'ready', 'userId' => $userId, 'name' => $name, 'fileId' => (string) $fileId]);
	}//end ready()

	/**
	 * The archive could not be built.
	 *
	 * @param string $userId The administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function failed(string $userId): void {
		$this->write(record: ['state' => 'failed', 'userId' => $userId]);
	}//end failed()

	/**
	 * The last record, or `{state: none}` when no export ran.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
	 */
	public function read(): array {
		$record = json_decode($this->config->getValueString(AppIdentity::APP_ID, self::KEY, ''), true);
		if (is_array($record) === false || in_array(($record['state'] ?? null), ['queued', 'ready', 'failed'], true) === false) {
			return ['state' => 'none'];
		}

		return array_map('strval', array_filter($record, static fn (mixed $value): bool => is_scalar($value)));
	}//end read()

	/**
	 * Store a record with the moment it was made.
	 *
	 * @param array<string, string> $record The record.
	 *
	 * @return void
	 */
	private function write(array $record): void {
		$record['at'] = $this->time->getDateTime()->format(DATE_ATOM);
		$this->config->setValueString(AppIdentity::APP_ID, self::KEY, (string) json_encode($record));
	}//end write()
}//end class
