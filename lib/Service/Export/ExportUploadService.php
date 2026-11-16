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
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ExportSinkRegistryInterface;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Service\Support\ExportSourceCredentials;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Destination upload with path resolution, retries and manifest building.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
 */
class ExportUploadService extends AbstractExportService {
	use ExportSourceCredentials;

	/**
	 * Backoff delays (seconds) between the 5 upload attempts.
	 *
	 * @var array<int, int>
	 */
	public const BACKOFF_SECONDS = [1, 2, 4, 8, 16];

	/**
	 * OpenConnector's Source schema slug.
	 *
	 * The register this schema lives in used to be pinned here as
	 * `openconnector`. It is not a constant of the estate: Integriq renames that
	 * register per instance, so the slug to read with is asked for at call time
	 * through {@see ConnectorSourceRegister}. Schema slugs were not renamed, so
	 * this one stays a literal.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	/**
	 * Log line when the connector source behind a destination cannot be read.
	 *
	 * @var string
	 */
	private const CREDENTIALS_FAILURE_MESSAGE = 'Pipelinq: failed to resolve export credentials';

	/**
	 * Legacy write-only credential fields still present on some Source
	 * objects (`configuration.authentication.credentialRef`-based sources
	 * resolve their secret through OpenConnector's own credential broker at
	 * call time and are not extractable here — ADR-005).
	 *
	 * @var array<int, string>
	 */
	private const OPENCONNECTOR_LEGACY_SECRET_FIELDS = ['apikey', 'secret', 'password', 'jwt'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container.
	 * @param IAppConfig $appConfig The app config.
	 * @param ObjectServiceInterface $objectService The published OpenRegister contract.
	 * @param ExportSinkRegistryInterface $sinks The sink adapter registry.
	 * @param ConnectorSourceRegister $connectorRegister Which slug the source register answers to here.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ContainerInterface $container,
		IAppConfig $appConfig,
		ObjectServiceInterface $objectService,
		private ExportSinkRegistryInterface $sinks,
		private ConnectorSourceRegister $connectorRegister,
		private LoggerInterface $logger,
	) {
		parent::__construct(
			container: $container,
			appConfig: $appConfig,
			objectService: $objectService
		);
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
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
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
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
	 */
	public function uploadFiles(array $destination, array $files, array $context = []): array {
		$type = (string)($destination['type'] ?? '');
		if ($this->sinks->supports(type: $type) === false) {
			return $this->failAll(files: $files, error: "Unsupported destination type '{$type}'.");
		}

		$sink = $this->sinks->get(type: $type);
		$credentials = $this->resolveCredentials(destination: $destination);
		$target = new ExportUploadTarget(sink: $sink, credentials: $credentials, destination: $destination);

		$tally = new ExportUploadTally();
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
				$ack = $this->uploadWithRetry(target: $target, remotePath: $remotePath, contents: (string)($file['contents'] ?? ''));
				$tally->uploaded(entry: $entry, ack: $ack);
			} catch (\Throwable $e) {
				$tally->failed(entry: $entry, error: $e->getMessage());
			}
		}

		return $tally->result(total: count($files));
	}//end uploadFiles()

	/**
	 * Attempt one upload with exponential backoff (5 attempts).
	 *
	 * @param ExportUploadTarget $target     The sink, credentials and destination.
	 * @param string             $remotePath The resolved remote path.
	 * @param string             $contents   The file bytes.
	 *
	 * @return string The destination acknowledgement.
	 *
	 * @throws \RuntimeException When all attempts fail.
	 *
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
	 */
	private function uploadWithRetry(ExportUploadTarget $target, string $remotePath, string $contents): string {
		$attempts = count(self::BACKOFF_SECONDS);
		$lastError = 'unknown error';

		for ($attempt = 0; $attempt < $attempts; $attempt++) {
			try {
				return $target->sink->upload($target->credentials, $target->destination, $remotePath, $contents);
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
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#REQ-BIE-008
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
