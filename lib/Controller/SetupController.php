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
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

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
     * @param string     $appName   The app id.
     * @param IRequest   $request   The request.
     * @param IAppConfig $appConfig App-config reader/writer.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IAppConfig $appConfig,
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
        $currencyDone = $this->config(key: 'currency') !== '';

        if ($currencyDone === true) {
            $this->appConfig->setValueString(Application::APP_ID, 'setup_completed_version', (string) self::SETUP_VERSION);
        }

        return new DataResponse(
            [
                'version'   => self::SETUP_VERSION,
                'completed' => $currencyDone,
                'steps'     => [
                    'currency' => ['done' => $currencyDone],
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

            if (is_scalar($value) === true) {
                $stored = (string) $value;
            } else {
                $stored = json_encode($value);
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
        return new DataResponse(
            ['success' => false, 'message' => 'Unknown setup action: '.$actionId],
            Http::STATUS_NOT_FOUND,
        );
    }//end runAction()

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
