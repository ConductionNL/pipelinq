<?php

/**
 * Pipelinq OpenRegisterServiceLookup
 *
 * Looks up the OpenRegister object and configuration services for the settings controllers.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use RuntimeException;

/**
 * The OpenRegister service getters shared by the settings controllers.
 *
 * The using class provides `$container`, `$appManager` and a nullable `$objectService` property.
 */
trait OpenRegisterServiceLookup {

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The OpenRegister service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-4
	 * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-6
	 */
	public function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$this->objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return $this->objectService;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the Configuration service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-3
	 * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-5
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			return $configurationService;
		}

		throw new RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()
}//end trait
