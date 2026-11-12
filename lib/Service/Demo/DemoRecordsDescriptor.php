<?php

/**
 * Pipelinq DemoRecordsDescriptor.
 *
 * Reads the example records descriptor, lib/Settings/pipelinq_example_register.json.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Demo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * The example records descriptor as stored, and as it is imported: with its
 * user fields pointed at existing users.
 *
 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
 */
class DemoRecordsDescriptor {
	/**
	 * App-relative path to the example records descriptor.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/pipelinq_example_register.json';

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager Resolves this app's path and version.
	 * @param DemoUserFields  $userFields Points user fields at existing users.
	 * @param LoggerInterface $logger     Records a missing or invalid descriptor.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly DemoUserFields $userFields,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The descriptor, or null (logged) when it is missing or unreadable.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function load(): ?array {
		$path = $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
		if (is_readable($path) === false) {
			$this->logger->error('Pipelinq example records descriptor missing', ['path' => $path]);
			return null;
		}

		$data = json_decode((string) file_get_contents($path), true);
		if (is_array($data) === false || is_array($data['components']['objects'] ?? null) === false) {
			$this->logger->error('Pipelinq example records descriptor invalid', ['path' => $path]);
			return null;
		}

		return $data;
	}//end load()

	/**
	 * The descriptor with its user fields pointed at existing users, or null
	 * (logged) when it is missing or unreadable.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function forImport(): ?array {
		$data = $this->load();
		if ($data === null) {
			return null;
		}

		$data['components']['objects'] = $this->userFields->withRealUsers(objects: $data['components']['objects']);
		return $data;
	}//end forImport()

	/**
	 * This app's version, which versions the import.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-10-07-example-data-out-of-the-register/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	public function appVersion(): string {
		return $this->appManager->getAppVersion(Application::APP_ID);
	}//end appVersion()
}//end class
