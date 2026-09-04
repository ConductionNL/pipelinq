<?php

/**
 * Pipelinq RegisterResolverService.
 *
 * Single resolution point for the OpenRegister register id configured for the
 * Pipelinq app. Replaces the scattered
 * `$appConfig->getValueString(APP_ID, 'register', '')` call sites with one
 * request-scoped, memoised abstraction (OR-side `register-resolver-service`
 * spec).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pipelinq-or-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Resolves the configured OpenRegister register id for the Pipelinq app.
 *
 * Pipelinq stores a single register id under the app-config key `register`.
 * Every backend service that needs to read or write OpenRegister objects must
 * obtain that id through this service rather than reading the app-config key
 * directly, so the resolution rule lives in exactly one place.
 *
 * The `$logicalName` argument names the *consumer domain* (e.g. `queue`,
 * `contact`) for readability and future per-domain routing. It is never used to
 * construct an app-config key, so it cannot be abused to read an arbitrary
 * config value. All logical names currently resolve to the same instance-scoped
 * `register` id, preserving the prior behaviour exactly.
 *
 * @spec openspec/changes/pipelinq-or-register-resolver/tasks.md#task-1.1
 */
class RegisterResolverService {
	/**
	 * The app-config key holding the register id.
	 *
	 * @var string
	 */
	private const REGISTER_CONFIG_KEY = 'register';

	/**
	 * Request-scoped memoisation of resolved register ids, keyed by logical name.
	 *
	 * Empty until first resolution. Cleared explicitly via {@see flush()}.
	 *
	 * @var array<string, string>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The Nextcloud app config (tenant-scoped).
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister register id for a consumer domain.
	 *
	 * The result is memoised per logical name for the lifetime of the request.
	 * Every logical name currently maps to the same instance-scoped `register`
	 * app-config value, so behaviour is identical to a direct read of that key.
	 *
	 * @param string $logicalName The consumer domain (e.g. `queue`, `contact`).
	 *                            Caches resolution per domain; it does NOT
	 *                            influence which app-config key is read and so
	 *                            cannot be abused to read an arbitrary value.
	 *
	 * @return string The configured register id, or an empty string when the
	 *                app has not been configured with a register yet.
	 *
	 * @spec openspec/changes/pipelinq-or-register-resolver/tasks.md#task-1.1
	 */
	public function resolve(string $logicalName = 'default'): string {
		if (array_key_exists($logicalName, $this->cache) === true) {
			return $this->cache[$logicalName];
		}

		$registerId = $this->appConfig->getValueString(
			Application::APP_ID,
			self::REGISTER_CONFIG_KEY,
			''
		);

		$this->cache[$logicalName] = $registerId;

		return $registerId;
	}//end resolve()

	/*
	 * NO flush() HERE.
	 *
	 * It cleared the memo below and was documented as "primarily for tests".
	 * It had no production caller in any of the five consumers
	 * (ContactVcardService, ContactVcardWriterService, Customer360SummaryService,
	 * DefaultSkillService) — which is exactly the shape gate-57
	 * exists to catch: implemented, unit-tested by calling the class directly,
	 * never reached from a live path. The memo is request-scoped, so nothing
	 * outlives the request that would need clearing.
	 */
}//end class
