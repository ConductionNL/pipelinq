<?php

/**
 * Pipelinq ProspectSources.
 *
 * Fetches prospects from the KVK and, when enabled, from OpenCorporates.
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

use Psr\Log\LoggerInterface;

/**
 * The companies the configured profile finds, keyed by KVK number: the KVK
 * results first, then the OpenCorporates results the KVK did not return.
 * A source that fails is logged and contributes nothing.
 *
 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
 */
class ProspectSources {
	/**
	 * Constructor.
	 *
	 * @param IcpConfigService        $icpConfig The ICP config: API key and source switches.
	 * @param KvkApiClient            $kvkClient The KVK API client.
	 * @param OpenCorporatesApiClient $ocClient  The OpenCorporates client.
	 * @param LoggerInterface         $logger    The logger.
	 */
	public function __construct(
		private IcpConfigService $icpConfig,
		private KvkApiClient $kvkClient,
		private OpenCorporatesApiClient $ocClient,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The prospects both sources find for the criteria.
	 *
	 * @param array<string, mixed> $criteria The ICP criteria.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration
	 */
	public function fetch(array $criteria): array {
		$prospects = $this->fromKvk(criteria: $criteria);
		foreach ($this->fromOpenCorporates(criteria: $criteria) as $result) {
			if (isset($prospects[$result['kvkNumber']]) === false) {
				$prospects[$result['kvkNumber']] = $result;
			}
		}

		return array_values(array: $prospects);
	}//end fetch()

	/**
	 * The KVK results keyed by KVK number; none when the search fails.
	 *
	 * @param array<string, mixed> $criteria The ICP criteria.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function fromKvk(array $criteria): array {
		$prospects = [];
		try {
			foreach ($this->kvkClient->search(apiKey: $this->icpConfig->getKvkApiKey(), criteria: $criteria) as $result) {
				$prospects[$result['kvkNumber']] = $result;
			}
		} catch (\Exception $e) {
			$this->logger->error(message: 'KVK API search failed', context: ['error' => $e->getMessage()]);
		}

		return $prospects;
	}//end fromKvk()

	/**
	 * The OpenCorporates results when that source is enabled; none when it is
	 * off or the search fails.
	 *
	 * @param array<string, mixed> $criteria The ICP criteria.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fromOpenCorporates(array $criteria): array {
		try {
			if (($this->icpConfig->getSettings()['openCorporatesEnabled'] ?? false) === true) {
				return $this->ocClient->search(criteria: $criteria);
			}
		} catch (\Exception $e) {
			$this->logger->warning(message: 'OpenCorporates search failed', context: ['error' => $e->getMessage()]);
		}

		return [];
	}//end fromOpenCorporates()
}//end class
