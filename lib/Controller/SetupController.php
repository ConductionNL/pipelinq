<?php

/**
 * Pipelinq first-time setup contract (ADR-042).
 *
 * Backs the abstract CnSetupWizard. Pipelinq's only required choice is the
 * reporting currency (consumed by the commercial dashboard's currency
 * formatting); register mapping and ingest are optional. Reports per-step
 * completion, persists config, and runs any optional server-side actions.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Settings\AdminSettings;
use OCA\Pipelinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * First-time setup status + actions for the abstract setup wizard.
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class SetupController extends Controller {
	/**
	 * Setup contract version; matches manifest.setup.version.
	 *
	 * @var int
	 */
	private const SETUP_VERSION = 1;

	/**
	 * App-config key recording that the optional demo-data step has been dealt
	 * with — either seeded or explicitly skipped. See `status()` for why an
	 * optional step MUST be satisfiable.
	 *
	 * @var string
	 */
	private const DEMO_DATA_DECIDED_KEY = 'demo_data_decided';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App-config reader/writer.
	 * @param SettingsService $settingsService Register/schema + default-data provisioning.
	 * @param DemoSeedService $demoSeedService Optional demo-data seeding (same write path as occ).
	 * @param IAppManager $appManager App installed/enabled lookup for integration detection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly SettingsService $settingsService,
		private readonly DemoSeedService $demoSeedService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Report per-step setup status for the wizard.
	 *
	 * ⚠️ THE RESPONSE MUST CARRY AN ENTRY FOR EVERY `manifest.setup.steps[].id`.
	 *
	 * `useSetupStatus` (nextcloud-vue) builds its unmet lists by walking the
	 * MANIFEST's step list and looking each id up in the `steps` map returned
	 * here. A manifest step this endpoint does not mention resolves to
	 * `{}` → `done: false` → it counts as unmet FOREVER, because no action any
	 * operator can take will ever make this endpoint report it.
	 *
	 * `CnAppRoot` then auto-opens `CnSetupWizard` as a full `modal-mask` over
	 * the shell whenever `requiredUnmet` is empty but `optionalUnmet` is not,
	 * and its dismissal is persisted in **localStorage** only. So a permanently
	 * unmet optional step means: every operator, on every fresh browser
	 * profile, in perpetuity, gets the app covered by the setup wizard —
	 * including every Playwright test, each of which runs in a fresh context.
	 *
	 * This endpoint used to report 4 ids while the manifest declared 7. The
	 * three it omitted (`welcome`, `demo-data`, `done`) were unmet forever, and
	 * that is what covered the app: verified in a real browser against a fully
	 * provisioned instance that was, at the same moment, returning
	 * `completed: true` from this very method.
	 *
	 * `welcome` and `done` are prose (`info` / `summary`); there is nothing for
	 * a server to complete, so they are reported done unconditionally. Newer
	 * nextcloud-vue also filters non-actionable steps out of the unmet lists,
	 * but reporting them keeps this contract true for any consumer version.
	 *
	 * @return DataResponse `{ version, completed, steps: { <id>: { done } } }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		// Currency is the single REQUIRED step — once set the app is usable.
		$currencyDone = $this->config(key: 'currency') !== '';

		// The register/schema id is written by the InitializeSettings repair step
		// on install (loadSettings). A non-empty `register` key means provisioning
		// has run; we surface it so the optional "Provision data" step shows done.
		$registerDone = $this->config(key: 'register') !== '';

		// Demo data is an optional run-action, so "done" means the operator has
		// DEALT WITH it — not that demo objects exist. `seedDemoData()` records
		// the marker when it runs, and `occ pipelinq:demo:seed --remove` leaving
		// the marker in place is correct: the decision was still made.
		$demoDataDone = $this->config(key: self::DEMO_DATA_DECIDED_KEY) !== '';

		// Organisation step done once the operator has named the organisation.
		$organisationDone = $this->config(key: 'receipt_company_name') !== '';

		// Integrations step done once either optional integration URL is set, or
		// when neither integration app is installed (nothing to configure).
		$shillinqUrl = $this->config(key: 'shillinq_app_url');
		$xwikiUrl = $this->config(key: 'xwiki_direct_url');
		$hasShillinq = $this->appManager->isInstalled('shillinq');
		// Resolved through FleetAppId: the app is `integriq` on development and
		// `openconnector` on beta/main, and asking for the wrong name reports
		// "not installed" rather than erroring — which would silently mark the
		// integrations step done when the app is in fact present.
		$hasXwiki = FleetAppId::isInstalled($this->appManager, 'integriq');
		$integrationsDone = ($shillinqUrl !== '' || $xwikiUrl !== '' || ($hasShillinq === false && $hasXwiki === false));

		if ($currencyDone === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		return new DataResponse(
			[
				'version' => self::SETUP_VERSION,
				'completed' => $currencyDone,
				'steps' => [
					'welcome' => ['done' => true],
					'currency' => ['done' => $currencyDone],
					'provision' => ['done' => $registerDone],
					'demo-data' => ['done' => $demoDataDone],
					'organisation' => ['done' => $organisationDone],
					'integrations' => ['done' => $integrationsDone],
					'done' => ['done' => true],
				],
			]
		);
	}//end status()

	/**
	 * Record that the operator has dealt with the optional demo-data step.
	 *
	 * Exposed as its own action (`skip-demo-data`) so "no thanks" is a decision
	 * the server can remember. Without it the only way to satisfy the step
	 * would be to actually seed demo data, which is wrong on a production
	 * install — and an unsatisfiable optional step covers the app with the
	 * setup wizard on every fresh browser profile, forever.
	 *
	 * @return DataResponse `{ success, message }`.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	private function skipDemoData(): DataResponse {
		$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

		return new DataResponse(
			['success' => true, 'message' => 'Demo data skipped. You can seed it later with `occ pipelinq:demo:seed`.']
		);
	}//end skipDemoData()

	/**
	 * Persist app-config values from a `choice` / `config-fields` step.
	 *
	 * @return DataResponse `{ success }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveConfig(): DataResponse {
		foreach ($this->request->getParams() as $key => $value) {
			if ($key === '_route') {
				continue;
			}

			$stored = json_encode($value);
			if (is_scalar($value) === true) {
				$stored = (string)$value;
			}

			$this->appConfig->setValueString(Application::APP_ID, (string)$key, $stored);
		}

		return new DataResponse(['success' => true]);
	}//end saveConfig()

	/**
	 * Run a privileged server-side setup action (pipelinq has no required action).
	 *
	 * @param string $actionId The action id.
	 *
	 * @return DataResponse `{ success, message }`.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function runAction(string $actionId): DataResponse {
		if ($actionId === 'provision-register') {
			return $this->provisionRegister();
		}

		if ($actionId === 'seed-demo-data') {
			return $this->seedDemoData();
		}

		if ($actionId === 'skip-demo-data') {
			return $this->skipDemoData();
		}

		return new DataResponse(
			['success' => false, 'message' => 'Unknown setup action: ' . $actionId],
			Http::STATUS_NOT_FOUND,
		);
	}//end runAction()

	/**
	 * Import the pipelinq register + schemas and (re)create the default
	 * pipelines, skills, lead sources and request channels.
	 *
	 * This mirrors the InitializeSettings repair step that runs on install, but
	 * is invokable on demand from the wizard so an admin who only enabled
	 * OpenRegister AFTER pipelinq (when the install-time repair skipped
	 * provisioning) can complete setup without a CLI repair run. Idempotent —
	 * loadSettings/createDefault* are no-ops when the data already exists.
	 *
	 * @return DataResponse `{ success, message }`.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	private function provisionRegister(): DataResponse {
		if ($this->appManager->isInstalled('openregister') === false) {
			return new DataResponse(
				[
					'success' => false,
					'message' => 'OpenRegister is not installed — install and enable it, then run this step.',
				],
				Http::STATUS_PRECONDITION_FAILED,
			);
		}

		try {
			$result = $this->settingsService->loadSettings(force: false);
			$registerCount = count($result['registers'] ?? []);
			$schemaCount = count($result['schemas'] ?? []);

			$this->settingsService->createDefaultPipelines();
			$this->settingsService->createDefaultSkills();

			$message = sprintf(
				'Provisioned %d register(s) and %d schema(s); default pipelines and skills are ready.',
				$registerCount,
				$schemaCount,
			);

			return new DataResponse(['success' => true, 'message' => $message]);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq setup provisioning failed', ['exception' => $e->getMessage()]);
			return new DataResponse(
				['success' => false, 'message' => 'Provisioning failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end provisionRegister()

	/**
	 * Seed the optional demo dataset (ADR-042 optional action `seed-demo-data`).
	 *
	 * Invokes the same DemoSeedService the `occ pipelinq:demo:seed` command
	 * uses (one write path). Idempotent — re-running creates no duplicates.
	 * Skipping this step never blocks setup completion (the wizard treats it
	 * as optional; only the currency step is required).
	 *
	 * @return DataResponse `{ success, message }`.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 */
	private function seedDemoData(): DataResponse {
		try {
			$result = $this->demoSeedService->seed();

			if ($result['success'] === false) {
				return new DataResponse(
					['success' => false, 'message' => (string)($result['message'] ?? 'Demo seed failed.')],
					Http::STATUS_PRECONDITION_FAILED,
				);
			}

			// Record the decision so `status()` can report the step done. See
			// DEMO_DATA_DECIDED_KEY — an optional step the server can never
			// report done covers the whole app with the setup wizard.
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'seeded');

			$created = array_sum($result['created']);
			$skipped = array_sum($result['skipped']);
			$message = sprintf(
				'Seeded %d demo object(s) (%d already present). Remove them any time with `occ pipelinq:demo:seed --remove`.',
				$created,
				$skipped,
			);

			return new DataResponse(['success' => true, 'message' => $message]);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq demo seed failed', ['exception' => $e->getMessage()]);
			return new DataResponse(
				['success' => false, 'message' => 'Demo seed failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try
	}//end seedDemoData()

	/**
	 * Read a pipelinq app-config string value.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The value, or '' when unset.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end config()
}//end class
