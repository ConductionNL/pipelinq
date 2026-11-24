<?php

/**
 * Pipelinq ExportUploadTally.
 *
 * Collects the per-file outcomes of one upload run and turns them into the
 * UploadResult envelope.
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
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

/**
 * The manifest, byte count, last acknowledgement and failures of an upload run.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
 */
final class ExportUploadTally {

	/**
	 * The per-file manifest entries, in upload order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $manifest = [];

	/**
	 * Total bytes uploaded.
	 *
	 * @var integer
	 */
	private int $byteCount = 0;

	/**
	 * The last successful acknowledgement.
	 *
	 * @var string|null
	 */
	private ?string $lastAck = null;

	/**
	 * The failure descriptions.
	 *
	 * @var array<int, string>
	 */
	private array $failures = [];

	/**
	 * Count of successful uploads.
	 *
	 * @var integer
	 */
	private int $succeeded = 0;

	/**
	 * Record a file that uploaded.
	 *
	 * @param array<string, mixed> $entry The manifest entry of the file.
	 * @param string               $ack   The destination acknowledgement.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
	 */
	public function uploaded(array $entry, string $ack): void {
		$entry['upload_status'] = 'success';
		$entry['destination_ack'] = $ack;
		$this->byteCount += $entry['size_bytes'];
		$this->lastAck = $ack;
		$this->succeeded++;
		$this->manifest[] = $entry;
	}//end uploaded()

	/**
	 * Record a file that failed to upload.
	 *
	 * @param array<string, mixed> $entry The manifest entry of the file.
	 * @param string               $error The failure message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
	 */
	public function failed(array $entry, string $error): void {
		$entry['upload_status'] = 'failed';
		$entry['error'] = $error;
		$this->failures[] = $entry['path'] . ': ' . $error;
		$this->manifest[] = $entry;
	}//end failed()

	/**
	 * The UploadResult envelope of the run.
	 *
	 * @param int $total Total files attempted.
	 *
	 * @return array<string, mixed> The UploadResult.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
	 */
	public function result(int $total): array {
		$status = $this->status(total: $total);

		$error = null;
		if ($this->failures !== []) {
			$error = implode('; ', $this->failures);
		}

		return [
			'status' => $status,
			'manifest' => $this->manifest,
			'file_count' => $this->succeeded,
			'byte_count' => $this->byteCount,
			'destination_ack' => $this->lastAck,
			'error_message' => $error,
		];
	}//end result()

	/**
	 * The UploadResult status: all_succeeded, all_failed or partial.
	 *
	 * @param int $total Total files attempted.
	 *
	 * @return string
	 */
	private function status(int $total): string {
		$status = 'partial';
		if ($total === 0 || $this->succeeded === $total) {
			$status = 'all_succeeded';
		}

		if ($total > 0 && $this->succeeded === 0) {
			$status = 'all_failed';
		}

		return $status;
	}//end status()
}//end class
