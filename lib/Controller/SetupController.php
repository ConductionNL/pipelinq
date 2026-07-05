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
class SetupController extends Controller
{
    /**
     * Setup contract version; matches manifest.setup.version.
     *
     * @var int
     */
    private const SETUP_VERSION = 1;

    /**
     * Constructor.
     *
     * @param string          $appName         The app id.
     * @param IRequest        $request         The request.
     * @param IAppConfig      $appConfig       App-config reader/writer.
     * @param SettingsService $settingsService Register/schema + default-data provisioning.
     * @param IAppManager     $appManager      App installed/enabled lookup for integration detection.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $appConfig,
        private readonly SettingsService $settingsService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Report per-step setup status for the wizard.
     *
     * @return DataResponse `{ version, completed, steps: { <id>: { done } } }`.
     *
     * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function status(): DataResponse
    {
        // Currency is the single REQUIRED step — once set the app is usable.
        $currencyDone = $this->config(key: 'currency') !== '';

        // The register/schema id is written by the InitializeSettings repair step
        // on install (loadSettings). A non-empty `register` key means provisioning
        // has run; we surface it so the optional "Provision data" step shows done.
        $registerDone = $this->config(key: 'register') !== '';

        // Organisation step done once the operator has named the organisation.
        $organisationDone = $this->config(key: 'receipt_company_name') !== '';

        // Integrations step done once either optional integration URL is set, or
        // when neither integration app is installed (nothing to configure).
        $shillinqUrl      = $this->config(key: 'shillinq_app_url');
        $xwikiUrl         = $this->config(key: 'xwiki_direct_url');
        $hasShillinq      = $this->appManager->isInstalled('shillinq');
        $hasXwiki         = $this->appManager->isInstalled('openconnector');
        $integrationsDone = ($shillinqUrl !== '' || $xwikiUrl !== '' || ($hasShillinq === false && $hasXwiki === false));

        if ($currencyDone === true) {
            $this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string) self::SETUP_VERSION);
        }

        return new DataResponse(
            [
                'version'   => self::SETUP_VERSION,
                'completed' => $currencyDone,
                'steps'     => [
                    'currency'     => ['done' => $currencyDone],
                    'provision'    => ['done' => $registerDone],
                    'organisation' => ['done' => $organisationDone],
                    'integrations' => ['done' => $integrationsDone],
                ],
            ]
        );
    }//end status()

    /**
     * Persist app-config values from a `choice` / `config-fields` step.
     *
     * @return DataResponse `{ success }`.
     *
     * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
     */
    #[AuthorizedAdminSetting(AdminSettings::class)]
    public function saveConfig(): DataResponse
    {
        foreach ($this->request->getParams() as $key => $value) {
            if ($key === '_route') {
                continue;
            }

            $stored = json_encode($value);
            if (is_scalar($value) === true) {
                $stored = (string) $value;
            }

            $this->appConfig->setValueString(Application::APP_ID, (string) $key, $stored);
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
    public function runAction(string $actionId): DataResponse
    {
        if ($actionId === 'provision-register') {
            return $this->provisionRegister();
        }

        return new DataResponse(
            ['success' => false, 'message' => 'Unknown setup action: '.$actionId],
            Http::STATUS_NOT_FOUND,
        );
    }//end runAction()

    /**
     * Import the pipelinq register + schemas and (re)create the default
     * pipelines, queues, skills, lead sources and request channels.
     *
     * This mirrors the InitializeSettings repair step that runs on install, but
     * is invokable on demand from the wizard so an admin who only enabled
     * OpenRegister AFTER pipelinq (when the install-time repair skipped
     * provisioning) can complete setup without a CLI repair run. Idempotent —
     * loadSettings/createDefault* are no-ops when the data already exists.
     *
     * @return DataResponse `{ success, message }`.
     *
     * @spec openspec/changes/pipelinq-setup-wizard-complete/specs/first-time-setup/spec.md
     */
    private function provisionRegister(): DataResponse
    {
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
            $result        = $this->settingsService->loadSettings(force: false);
            $registerCount = count($result['registers'] ?? []);
            $schemaCount   = count($result['schemas'] ?? []);

            $this->settingsService->createDefaultPipelines();
            $this->settingsService->createDefaultQueues();
            $this->settingsService->createDefaultSkills();

            $message = sprintf(
                'Provisioned %d register(s) and %d schema(s); default pipelines, queues and skills are ready.',
                $registerCount,
                $schemaCount,
            );

            return new DataResponse(['success' => true, 'message' => $message]);
        } catch (\Throwable $e) {
            $this->logger->error('Pipelinq setup provisioning failed', ['exception' => $e->getMessage()]);
            return new DataResponse(
                ['success' => false, 'message' => 'Provisioning failed: '.$e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }//end try
    }//end provisionRegister()

    /**
     * Read a pipelinq app-config string value.
     *
     * @param string $key The config key.
     *
     * @return string The value, or '' when unset.
     */
    private function config(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end config()
}//end class
