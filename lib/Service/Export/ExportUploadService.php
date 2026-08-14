<?php

/**
 * Pipelinq ExportUploadService.
 *
 * Uploads formatted export files to a destination through the type-specific
 * sink adapter, resolving the remote path from the destination's path template
 * + naming convention, fetching credentials from the referenced OpenConnector
 * source (ADR-005 — credentials never live on the destination object), and
 * retrying transient failures with exponential backoff. Produces the per-file
 * manifest with upload status and destination acknowledgement.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Destination upload with path resolution, retries and manifest building.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */
class ExportUploadService extends AbstractExportService {
	/**
	 * Backoff delays (seconds) between the 5 upload attempts.
	 *
	 * @var array<int, int>
	 */
	public const BACKOFF_SECONDS = [1, 2, 4, 8, 16];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app config.
	 * @param ExportSinkRegistry $sinks The sink adapter registry.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		private ExportSinkRegistry $sinks,
		private LoggerInterface $logger,
	) {
		parent::__construct(container: $container, appConfig: $appConfig);
	}//end __construct()

	/**
	 * Whether the upload sleeps between retries.
	 *
	 * Disabled in tests (and test runs) so retry behaviour can be asserted
	 * without real wall-clock delays.
	 *
	 * @var boolean
	 */
	private bool $sleepBetweenRetries = true;

	/**
	 * Toggle the inter-retry sleep (tests set this false).
	 *
	 * @param bool $sleep Whether to sleep between retries.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) — a single on/off test seam.
	 */
	public function setSleepBetweenRetries(bool $sleep): void {
		$this->sleepBetweenRetries = $sleep;
	}//end setSleepBetweenRetries()

	/**
	 * Upload a set of formatted files to a destination.
	 *
	 * @param array<string, mixed> $destination The destination configuration.
	 * @param array<int, array<string, mixed>> $files The file descriptors from ExportDataService.
	 * @param array<string, mixed> $context Path-template substitutions (run_id, timestamp, partition).
	 *
	 * @return array<string, mixed> UploadResult: status (all_succeeded|partial|all_failed),
	 *                              manifest, byte_count, file_count, destination_ack, error_message.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
	 */
	public function uploadFiles(array $destination, array $files, array $context = []): array {
		$type = (string)($destination['type'] ?? '');
		if ($this->sinks->supports(type: $type) === false) {
			return $this->failAll(files: $files, error: "Unsupported destination type '{$type}'.");
		}

		$sink = $this->sinks->get(type: $type);
		$credentials = $this->resolveCredentials(destination: $destination);

		$manifest = [];
		$byteCount = 0;
		$lastAck = null;
		$failures = [];
		$succeeded = 0;

		foreach ($files as $file) {
			$remotePath = $this->resolvePath(destination: $destination, file: $file, context: $context);
			$entry = [
				'path' => $remotePath,
				'size_bytes' => (int)($file['size_bytes'] ?? 0),
				'rows_in_file' => (int)($file['rows'] ?? 0),
				'sha256' => (string)($file['sha256'] ?? ''),
				'compression_used' => (string)($file['compression_used'] ?? 'none'),
			];

			try {
				$ack = $this->uploadWithRetry(
					sink: $sink,
					credentials: $credentials,
					destination: $destination,
					remotePath: $remotePath,
					contents: (string)($file['contents'] ?? '')
				);

				$entry['upload_status'] = 'success';
				$entry['destination_ack'] = $ack;
				$byteCount += $entry['size_bytes'];
				$lastAck = $ack;
				$succeeded++;
			} catch (\Throwable $e) {
				$entry['upload_status'] = 'failed';
				$entry['error'] = $e->getMessage();
				$failures[] = $remotePath . ': ' . $e->getMessage();
			}//end try

			$manifest[] = $entry;
		}//end foreach

		return $this->buildResult(
			manifest: $manifest,
			total: count($files),
			succeeded: $succeeded,
			failures: $failures,
			byteCount: $byteCount,
			lastAck: $lastAck
		);
	}//end uploadFiles()

	/**
	 * Attempt one upload with exponential backoff (5 attempts).
	 *
	 * @param object $sink The sink adapter.
	 * @param array<string, mixed> $credentials The resolved credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 * @param string $remotePath The resolved remote path.
	 * @param string $contents The file bytes.
	 *
	 * @return string The destination acknowledgement.
	 *
	 * @throws \RuntimeException When all attempts fail.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-02
	 */
	private function uploadWithRetry(
		object $sink,
		array $credentials,
		array $destination,
		string $remotePath,
		string $contents,
	): string {
		$attempts = count(self::BACKOFF_SECONDS);
		$lastError = 'unknown error';

		for ($attempt = 0; $attempt < $attempts; $attempt++) {
			try {
				return $sink->upload($credentials, $destination, $remotePath, $contents);
			} catch (\Throwable $e) {
				$lastError = $e->getMessage();
				$this->logger->warning(
					'Pipelinq: export upload attempt failed',
					['path' => $remotePath, 'attempt' => ($attempt + 1), 'error' => $lastError]
				);

				$isLast = ($attempt === ($attempts - 1));
				if ($isLast === false && $this->sleepBetweenRetries === true) {
					sleep(self::BACKOFF_SECONDS[$attempt]);
				}
			}//end try
		}//end for

		throw new RuntimeException("Upload failed after {$attempts} attempts: {$lastError}");
	}//end uploadWithRetry()

	/**
	 * Resolve the remote path for a file from the path template + naming pattern.
	 *
	 * Substitutes {schema}, {partition}, {run_id}, {timestamp} placeholders.
	 *
	 * @param array<string, mixed> $destination The destination configuration.
	 * @param array<string, mixed> $file The file descriptor.
	 * @param array<string, mixed> $context The substitution context.
	 *
	 * @return string The resolved remote path.
	 *
	 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008-01
	 */
	public function resolvePath(array $destination, array $file, array $context): string {
		$schema = (string)($file['schema'] ?? 'data');
		$runId = (string)($context['run_id'] ?? '');
		$timestamp = (string)($context['timestamp'] ?? gmdate('Ymd\THis\Z'));
		$partition = (string)($context['partition'] ?? '');

		$replacements = [
			'{schema}' => $schema,
			'{partition}' => $partition,
			'{run_id}' => $runId,
			'{timestamp}' => $timestamp,
		];

		$template = (string)($destination['pathTemplate'] ?? '');
		$base = strtr($template, $replacements);

		$naming = (string)($destination['namingConvention'] ?? '');
		if ($naming === '') {
			$naming = '{schema}_{run_id}_{timestamp}';
		}

		$filename = strtr($naming, $replacements);

		// Collapse a double slash from a trailing-slash template + filename.
		return rtrim($base, '/') . '/' . ltrim($filename, '/');
	}//end resolvePath()

	/**
	 * Resolve credentials for the destination's OpenConnector source.
	 *
	 * Never returns the credentials to a client — they are consumed only inside
	 * the sink adapter. When OC is unavailable an empty map is returned and the
	 * upload fails closed.
	 *
	 * @param array<string, mixed> $destination The destination configuration.
	 *
	 * @return array<string, mixed> The resolved credentials (empty when unavailable).
	 */
	private function resolveCredentials(array $destination): array {
		$sourceId = (string)($destination['connectorSourceId'] ?? '');
		if ($sourceId === '') {
			return [];
		}

		try {
			$sourceService = $this->container->get('OCA\OpenConnector\Service\SourceService');
		} catch (\Throwable $e) {
			return [];
		}

		try {
			if (method_exists($sourceService, 'getCredentials') === true) {
				$credentials = $sourceService->getCredentials($sourceId);
				if (is_array($credentials) === true) {
					return $credentials;
				}

				return [];
			}

			if (method_exists($sourceService, 'find') === true) {
				$source = $sourceService->find($sourceId);
				return $this->toArray(object: $source);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to resolve export credentials',
				['sourceId' => $sourceId, 'error' => $e->getMessage()]
			);
		}//end try

		return [];
	}//end resolveCredentials()

	/**
	 * Build the UploadResult envelope from per-file outcomes.
	 *
	 * @param array<int, array<string, mixed>> $manifest The per-file manifest.
	 * @param int $total Total files attempted.
	 * @param int $succeeded Count of successful uploads.
	 * @param array<int, string> $failures The failure descriptions.
	 * @param int $byteCount Total bytes uploaded.
	 * @param string|null $lastAck The last successful ack.
	 *
	 * @return array<string, mixed> The UploadResult.
	 */
	private function buildResult(
		array $manifest,
		int $total,
		int $succeeded,
		array $failures,
		int $byteCount,
		?string $lastAck,
	): array {
		$status = 'partial';
		if ($total === 0 || $succeeded === $total) {
			$status = 'all_succeeded';
		}

		if ($total > 0 && $succeeded === 0) {
			$status = 'all_failed';
		}

		$error = null;
		if ($failures !== []) {
			$error = implode('; ', $failures);
		}

		return [
			'status' => $status,
			'manifest' => $manifest,
			'file_count' => $succeeded,
			'byte_count' => $byteCount,
			'destination_ack' => $lastAck,
			'error_message' => $error,
		];
	}//end buildResult()

	/**
	 * Build an all-failed result without attempting any upload.
	 *
	 * @param array<int, array<string, mixed>> $files The file descriptors.
	 * @param string $error The reason.
	 *
	 * @return array<string, mixed> The all-failed UploadResult.
	 */
	private function failAll(array $files, string $error): array {
		$manifest = [];
		foreach ($files as $file) {
			$manifest[] = [
				'path' => (string)($file['schema'] ?? ''),
				'size_bytes' => (int)($file['size_bytes'] ?? 0),
				'upload_status' => 'failed',
				'error' => $error,
			];
		}

		return [
			'status' => 'all_failed',
			'manifest' => $manifest,
			'file_count' => 0,
			'byte_count' => 0,
			'destination_ack' => null,
			'error_message' => $error,
		];
	}//end failAll()
}//end class
