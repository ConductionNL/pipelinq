<?php

/**
 * Pipelinq ContactSyncSettingsController.
 *
 * The contacts section of the user settings: the user's write-back switch,
 * the sync status and a full run.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\ContactSyncSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The user's contacts sync settings.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The request.
	 * @param ContactSyncSettingsService $settings    The settings and the run.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IL10N                      $l10n        Translations.
	 */
	public function __construct(
		IRequest $request,
		private ContactSyncSettingsService $settings,
		private CrmPrivilegeCheck $privilege,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The signed-in user's contacts sync status.
	 *
	 * @return JSONResponse The status.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$uid = $this->privilege->actingUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->settings->status(userId: $uid));
	}//end show()

	/**
	 * Turn the signed-in user's write-back on or off.
	 *
	 * @return JSONResponse The status after the change.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$uid = $this->privilege->actingUserId();
		if ($uid === '') {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$writeBack = $this->request->getParam('writeBack');
		if (is_bool($writeBack) === false) {
			return new JSONResponse(['error' => $this->l10n->t('writeBack must be true or false')], Http::STATUS_BAD_REQUEST);
		}

		$this->settings->saveWriteBack(userId: $uid, writeBack: $writeBack);

		return new JSONResponse($this->settings->status(userId: $uid));
	}//end update()

	/**
	 * Write every linked client and contact person back to Nextcloud Contacts now.
	 *
	 * @return JSONResponse The run: when, how many cards, which failed.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function run(): JSONResponse {
		// Writing the CRM into addressbooks is a CRM capability, as the
		// single-record write-back is.
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		return new JSONResponse($this->settings->run(userId: $uid, now: $now));
	}//end run()
}//end class
