<?php

/**
 * Pipelinq AbstractOpenConnectorSink.
 *
 * Base class for export sink adapters that resolve credentials from an
 * OpenConnector source and transfer a file to an external warehouse. The
 * actual byte transfer is delegated to OpenConnector's CallService (which owns
 * the per-protocol transport and credential handling); this base only shapes
 * the request and the per-destination acknowledgement. External transports are
 * never exercised in unit tests — adapters are mocked behind
 * {@see ExportSinkInterface}.
 *
 * @category Adapter
 * @package  OCA\Pipelinq\Adapter
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

namespace OCA\Pipelinq\Adapter;

use OCA\Pipelinq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shared OpenConnector-backed transfer for the concrete sink adapters.
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
 */
abstract class AbstractOpenConnectorSink implements ExportSinkInterface {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (for the OC CallService).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		protected ContainerInterface $container,
		protected LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The destination type slug this adapter handles.
	 *
	 * @return string The destination type.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	abstract public function getType(): string;

	/**
	 * Build the destination-specific acknowledgement string from a transfer
	 * result. Subclasses surface the field their backend returns (ETag,
	 * load-job id, query id, blob properties, remote path, copy count).
	 *
	 * @param array<string, mixed> $result The transfer result metadata.
	 *
	 * @return string The acknowledgement.
	 */
	abstract protected function acknowledge(array $result): string;

	/**
	 * Test connectivity using the OpenConnector source behind the destination.
	 *
	 * Never throws: a failure to resolve OC, the source, or to reach the
	 * destination is reported as false so the caller records an invalid status.
	 *
	 * @param array<string, mixed> $credentials The resolved OpenConnector credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 *
	 * @return bool True when reachable.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function testConnection(array $credentials, array $destination): bool {
		try {
			return $this->probe(credentials: $credentials, destination: $destination);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: export sink connectivity test failed',
				['type' => $this->getType(), 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end testConnection()

	/**
	 * Upload a single file via the OpenConnector transfer.
	 *
	 * @param array<string, mixed> $credentials The resolved OpenConnector credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 * @param string $remotePath The resolved remote path/object key.
	 * @param string $contents The file bytes to write.
	 *
	 * @return string The destination acknowledgement.
	 *
	 * @throws RuntimeException When the transfer fails.
	 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
	 */
	public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
		$result = $this->transfer(
			credentials: $credentials,
			destination: $destination,
			remotePath: $remotePath,
			contents: $contents
		);

		return $this->acknowledge(result: $result);
	}//end upload()

	/**
	 * Probe the destination for reachability (lightweight, no payload).
	 *
	 * Delegates to OpenConnector's CallService when available; without OC a
	 * probe cannot be performed, so it reports unreachable (fail-closed).
	 *
	 * @param array<string, mixed> $credentials The resolved credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 *
	 * @return bool True when reachable.
	 */
	protected function probe(array $credentials, array $destination): bool {
		$callService = $this->getCallService();
		if ($callService === null) {
			return false;
		}

		// CallService::checkConnection is the OC connectivity probe; a missing
		// method (older OC) degrades to "reachable if the source resolved".
		if (method_exists($callService, 'checkConnection') === true) {
			return (bool)$callService->checkConnection($credentials, $destination);
		}

		return ($credentials !== []);
	}//end probe()

	/**
	 * Transfer the file bytes to the destination via OpenConnector.
	 *
	 * @param array<string, mixed> $credentials The resolved credentials.
	 * @param array<string, mixed> $destination The destination configuration.
	 * @param string $remotePath The remote object key/path.
	 * @param string $contents The bytes to write.
	 *
	 * @return array<string, mixed> The transfer result metadata.
	 *
	 * @throws RuntimeException When OpenConnector is unavailable or the write fails.
	 */
	protected function transfer(array $credentials, array $destination, string $remotePath, string $contents): array {
		$callService = $this->getCallService();
		if ($callService === null) {
			throw new RuntimeException('OpenConnector CallService is not available for export upload.');
		}

		// VERIFIED 2026-09-09 against integriq development: the published
		// CallService surface is call()/callAsync() and there is no put(), under
		// either namespace. Resolving the class correctly does not conjure the
		// method, so this branch is reached on a fully migrated instance — and
		// unlike the name half it fails LOUD, which is why it is left as a
		// throw rather than a silent skip.
		if (method_exists($callService, 'put') === false) {
			throw new RuntimeException('OpenConnector CallService does not support file uploads.');
		}

		$result = $callService->put($credentials, $destination, $remotePath, $contents);
		if (is_array($result) === false) {
			return ['path' => $remotePath];
		}

		return $result;
	}//end transfer()

	/**
	 * Resolve the CallService, or null when the connector app is absent.
	 *
	 * Asked for by CANONICAL app name, never by a literal FQCN: the connector
	 * app renamed its PSR-4 root from `OCA\OpenConnector` to `OCA\Integriq`
	 * with no compatibility alias, and both roots are in the field at once.
	 * A container lookup on the wrong one throws, and a thrown lookup is
	 * indistinguishable from the optional app not being installed — the export
	 * then reports "CallService is not available" for an instance that has it.
	 * {@see FleetAppId::getService()} tries every spelling, newest first, and
	 * returns null only when none of them resolves.
	 *
	 * @return object|null The CallService instance, or null.
	 */
	protected function getCallService(): ?object {
		return FleetAppId::getService(
			container: $this->container,
			canonical: 'integriq',
			relative: 'Service\CallService'
		);
	}//end getCallService()
}//end class
