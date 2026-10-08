<?php

/**
 * Detect the Shillinq and XWiki integrations instead of asking for base URLs.
 *
 * An administrator used to type a Shillinq base URL and an XWiki base URL into
 * the setup wizard. Both are knowable from the instance itself: Shillinq is a
 * Nextcloud app on this server, and XWiki is reached through the XWiki
 * Nextcloud app or through OpenRegister's `xwiki` integration, which integriq
 * backs. So pipelinq looks, and nobody is asked.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IURLGenerator;

/**
 * Reports which optional integrations this instance has.
 *
 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md#requirement-req-setup-pip-006-detected-integrations
 */
class IntegrationDetector {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager App installed lookup.
	 * @param IURLGenerator $urlGenerator Builds the Shillinq link on this server.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Whether Shillinq is installed, and where it opens.
	 *
	 * @return array{installed: bool, url: string} `url` is '' when Shillinq is not installed.
	 *
	 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
	 */
	public function shillinq(): array {
		$installed = $this->appManager->isInstalled('shillinq');
		$url = '';
		if ($installed === true) {
			$url = $this->urlGenerator->getAbsoluteURL('/index.php/apps/shillinq/');
		}

		return ['installed' => $installed, 'url' => $url];
	}//end shillinq()

	/**
	 * Whether XWiki is reachable, and through which route.
	 *
	 * The XWiki Nextcloud app wins. Otherwise OpenRegister's `xwiki`
	 * integration is available when OpenRegister and integriq are both
	 * installed: integriq carries the XWiki source and its credentials.
	 *
	 * @return array{available: bool, source: string} `source` is `xwiki-app`, `openregister` or `none`.
	 *
	 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
	 */
	public function xwiki(): array {
		if ($this->appManager->isInstalled('xwiki') === true) {
			return ['available' => true, 'source' => 'xwiki-app'];
		}

		// Resolved through FleetAppId: integriq is still `openconnector` on
		// beta and main, and asking for the wrong id answers "not installed".
		if ($this->appManager->isInstalled('openregister') === true
			&& FleetAppId::isInstalled($this->appManager, 'integriq') === true
		) {
			return ['available' => true, 'source' => 'openregister'];
		}

		return ['available' => false, 'source' => 'none'];
	}//end xwiki()

	/**
	 * Both detections, for the admin settings page.
	 *
	 * @return array{shillinq: array{installed: bool, url: string}, xwiki: array{available: bool, source: string}}
	 *
	 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
	 */
	public function all(): array {
		return ['shillinq' => $this->shillinq(), 'xwiki' => $this->xwiki()];
	}//end all()
}//end class
