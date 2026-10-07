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
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCA\Pipelinq\Service\DemoSeedService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Settings\AdminSettings;
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
	 * App-config key holding the dataset the operator picked.
	 *
	 * The wizard's `choice` step writes it through `POST /api/setup/config`.
	 * Its card Load button posts `{ dataset }` to the `load-demo-data` action,
	 * which stores the same key before it seeds, so both routes land in one
	 * place (`loadAction` on the step, pipelinq-setup-and-forms-review).
	 *
	 * @var string
	 */
	private const DATASET_KEY = 'demo_dataset';

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
	 * @param DemoRegisterImporter $registerImporter The example records descriptor, imported with the seed.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly SettingsService $settingsService,
		private readonly DemoSeedService $demoSeedService,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly DemoRegisterImporter $registerImporter,
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
	 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function status(): DataResponse {
		// Currency is the single REQUIRED step — once set the app is usable.
		$currencyDone = $this->config(key: 'currency') !== '';

		// The example data step is answered once the operator picked a card,
		// "None" included, or once a load ran. Picking a dataset WITHOUT loading
		// it still counts: a nextcloud-vue that predates `loadAction` can only
		// record the pick, and a step no operator can close covers the app with
		// the wizard forever (see above).
		$demoDataDone = ($this->config(key: self::DEMO_DATA_DECIDED_KEY) !== ''
			|| $this->config(key: self::DATASET_KEY) !== '');

		// Organisation step done once the operator has named the organisation.
		$organisationDone = $this->config(key: 'receipt_company_name') !== '';

		if ($currencyDone === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string)self::SETUP_VERSION);
		}

		return new DataResponse(
			[
				'version' => self::SETUP_VERSION,
				'completed' => $currencyDone,
				// The choice step reads its options from here: it declares
				// `optionsSource: datasets` and no options of its own, so a
				// dataset missing from this list is a dataset nobody can pick.
				'datasets' => $this->demoSeedService->listChoices(),
				// Exactly the ids of `manifest.setup.steps`, asserted by
				// SetupControllerStatusContractTest. Provisioning and the
				// integrations are not steps any more: provisioning is an admin
				// settings action and integrations are detected, not asked.
				'steps' => [
					'welcome' => ['done' => true],
					'demo-data' => ['done' => $demoDataDone],
					'currency' => ['done' => $currencyDone],
					'organisation' => ['done' => $organisationDone],
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
		// Skipping IS choosing "None", so both keys are written: an older
		// runbook may read either one.
		$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoSeedService::NONE_DATASET);
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
		// 🔴 THE DATASET IS VALIDATED BEFORE IT IS STORED. Everything else here
		// is written as posted, because a `config-fields` step declares its own
		// keys and this endpoint cannot know them. The dataset is different:
		// the seed step reads it back and acts on it, so an unknown value would
		// surface a step later with no clue why.
		$refusal = $this->refuseUnknownDataset(dataset: $this->request->getParam(self::DATASET_KEY));
		if ($refusal !== null) {
			return $refusal;
		}

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
	 * Refuse a posted dataset id no dataset answers to.
	 *
	 * @param mixed $dataset The posted value, or null when nothing was posted.
	 *
	 * @return DataResponse|null The refusal, or null when the value is absent or known.
	 *
	 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
	 */
	private function refuseUnknownDataset(mixed $dataset): ?DataResponse {
		if ($dataset === null) {
			return null;
		}

		$named = 'that';
		if (is_scalar($dataset) === true) {
			$named = (string)$dataset;
		}

		$known = array_column($this->demoSeedService->listChoices(), 'id');
		if (in_array($named, $known, true) === true) {
			return null;
		}

		return new DataResponse(['success' => false, 'message' => 'No dataset is called "' . $named . '".']);
	}//end refuseUnknownDataset()

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

		// `seed-demo-data` is the id the step used before it asked WHICH
		// dataset, and it still means "seed the one this app builds". Kept so
		// an older manifest, a runbook or a script that posts it keeps working.
		if ($actionId === 'load-demo-data') {
			return $this->loadDataset();
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
	 * Seed the dataset the operator picked: the one posted as `dataset` by
	 * the card's Load button, or the stored pick when nothing is posted.
	 *
	 * Invokes the same DemoSeedService the `occ pipelinq:demo:seed` command
	 * uses (one write path). Idempotent — re-running creates no duplicates.
	 * Skipping this step never blocks setup completion (the wizard treats it
	 * as optional; only the currency step is required).
	 *
	 * @return DataResponse `{ success, message }`.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-req-setup-pip-008-optional-demo-data-seed
	 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
	 */
	private function loadDataset(): DataResponse {
		// The card's Load button names its dataset in the body. An older
		// wizard posts nothing and relies on the choice stored a step earlier.
		$posted = $this->request->getParam('dataset');
		$refusal = $this->refuseUnknownDataset(dataset: $posted);
		if ($refusal !== null) {
			return $refusal;
		}

		// Nothing is stored before the seed succeeds: a failed load must leave
		// the step open for an operator who asked for data and got none.
		$picked = $this->config(key: self::DATASET_KEY);
		if ($posted !== null) {
			$picked = (string)$posted;
		}

		// 🔴 NO SILENT DEFAULT. Seeding here because the operator clicked Run
		// one step early would plant example objects nobody asked for.
		if ($picked === '') {
			return new DataResponse(['success' => false, 'message' => 'Pick a dataset first.']);
		}

		if ($picked === DemoSeedService::NONE_DATASET) {
			$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoSeedService::NONE_DATASET);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'skipped');

			return new DataResponse(['success' => true, 'message' => 'No example data was seeded.']);
		}

		return $this->seedDemoData();

	}//end loadDataset()

	/**
	 * Seed the dataset this app builds.
	 *
	 * @return DataResponse `{ success, message }`.
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
			// Seeding IS choosing the set, so the pick is written too.
			$this->appConfig->setValueString(Application::APP_ID, self::DATASET_KEY, DemoSeedService::DEMO_DATASET);
			$this->appConfig->setValueString(Application::APP_ID, self::DEMO_DATA_DECIDED_KEY, 'seeded');

			// The example records that used to ship inside the register descriptor.
			$example = $this->registerImporter->import();

			$created = (array_sum($result['created']) + $example['imported']);
			$skipped = (array_sum($result['skipped']) + $example['skipped']);
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
