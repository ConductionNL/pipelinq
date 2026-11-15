<?php

/**
 * Pipelinq ActiveLoyaltyProgrammes.
 *
 * Reads the active loyalty programmes for the loyalty background jobs.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The active loyalty programmes of the configured register, as OpenRegister
 * returns them (arrays or entities).
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
 */
final class ActiveLoyaltyProgrammes {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig The app configuration.
	 * @param ContainerInterface $container The DI container.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The rows of the active programmes; none without a register or programme
	 * schema, or when the lookup fails.
	 *
	 * @return array<int|string, mixed> The rows.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-005
	 */
	public function rows(): array {
		$filters = $this->programmeFilters();
		if ($filters === null) {
			return [];
		}

		return $this->findRows(filters: $filters);
	}//end rows()

	/**
	 * The filters of the active programmes of the configured register.
	 *
	 * @return array<string, string>|null The filters, or null without a register or programme schema.
	 */
	private function programmeFilters(): ?array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'loyaltyProgramme_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['status' => 'active', 'register' => $register, 'schema' => $schema];
	}//end programmeFilters()

	/**
	 * Look the programmes up in OpenRegister.
	 *
	 * @param array<string, string> $filters The filters.
	 *
	 * @return array<int|string, mixed> The rows; none when the lookup fails.
	 */
	private function findRows(array $filters): array {
		try {
			$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')->findAll(
				config: ['filters' => $filters, 'limit' => 500]
			);
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($rows) === false) {
			return [];
		}

		return $rows;
	}//end findRows()
}//end class
