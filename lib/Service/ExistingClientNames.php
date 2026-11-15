<?php

/**
 * Pipelinq ExistingClientNames.
 *
 * Reads the names of the clients already in the register.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The lower-cased names of the stored clients, so prospects that are already
 * clients can be left out. Empty when OpenRegister or the client schema is
 * not there, or the read fails.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ExistingClientNames {
	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settings   Holds the register and client schema.
	 * @param ContainerInterface $container  The DI container (OpenRegister's ObjectService).
	 * @param IAppManager        $appManager Says whether OpenRegister is installed.
	 * @param LoggerInterface    $logger     The logger.
	 */
	public function __construct(
		private SettingsService $settings,
		private ContainerInterface $container,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The prospects whose trade name is not an existing client's name. Strict
	 * equality only: a substring match would drop prospects on "BV" or "Group".
	 *
	 * @param array<int, array<string, mixed>> $prospects The prospects.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
	 */
	public function exclude(array $prospects): array {
		$clientNames = $this->names();
		if (count($clientNames) === 0) {
			return $prospects;
		}

		return array_values(
			array: array_filter(
				array: $prospects,
				callback: static function (array $prospect) use ($clientNames): bool {
					$tradeName = strtolower(string: trim(string: $prospect['tradeName'] ?? ''));
					return $tradeName === '' || in_array($tradeName, $clientNames, true) === false;
				}
			)
		);
	}//end exclude()

	/**
	 * The lower-cased client names; none when they cannot be read.
	 *
	 * @return array<int, string>
	 */
	private function names(): array {
		try {
			return $this->readNames();
		} catch (\Throwable $e) {
			$this->logger->warning(message: 'Failed to fetch existing clients for exclusion', context: ['error' => $e->getMessage()]);
			return [];
		}
	}//end names()

	/**
	 * Read the client names from OpenRegister, when it is installed and configured.
	 *
	 * @return array<int, string>
	 */
	private function readNames(): array {
		$filters = $this->clientFilters();
		if ($filters === null) {
			return [];
		}

		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$names         = [];
		foreach ($objectService->findAll(['filters' => $filters, 'limit' => 1000]) as $client) {
			$name = $this->clientName(client: $client);
			if ($name !== '') {
				$names[] = strtolower(trim($name));
			}
		}

		return $names;
	}//end readNames()

	/**
	 * The register and schema that hold the clients, or null when either is
	 * not configured or OpenRegister is not installed.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function clientFilters(): ?array {
		$register = $this->settings->getConfigValue(key: 'register');
		$schema   = $this->settings->getConfigValue(key: 'client_schema');
		if ($register === '' || $schema === '' || in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end clientFilters()

	/**
	 * A stored client's name: its name, else its trade name. OpenRegister's
	 * findAll() hands back ObjectEntity instances, not arrays.
	 *
	 * @param mixed $client The stored client.
	 *
	 * @return string
	 */
	private function clientName(mixed $client): string {
		if (is_object($client) === true && method_exists($client, 'jsonSerialize') === true) {
			$client = $client->jsonSerialize();
		}

		$client = (array) $client;
		return (string) ($client['name'] ?? $client['tradeName'] ?? '');
	}//end clientName()
}//end class
