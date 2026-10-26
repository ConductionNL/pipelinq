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
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContactSyncSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The user's contacts sync settings.
 *
 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request     The request.
	 * @param ContactSyncSettingsService $settings    The settings and the run.
	 * @param IUserSession               $userSession The user session.
	 * @param ObjectOwnerAccessPolicy    $policy      Who may write the CRM to Contacts.
	 * @param IL10N                      $l10n        Translations.
	 */
	public function __construct(
		IRequest $request,
		private ContactSyncSettingsService $settings,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The signed-in user's contacts sync status.
	 *
	 * @return JSONResponse The status.
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->settings->status(userId: $user->getUID()));
	}//end show()

	/**
	 * Turn the signed-in user's write-back on or off.
	 *
	 * @return JSONResponse The status after the change.
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$writeBack = $this->request->getParam('writeBack');
		if (is_bool($writeBack) === false) {
			return new JSONResponse(['error' => $this->l10n->t('writeBack must be true or false')], Http::STATUS_BAD_REQUEST);
		}

		$this->settings->saveWriteBack(userId: $user->getUID(), writeBack: $writeBack);

		return new JSONResponse($this->settings->status(userId: $user->getUID()));
	}//end update()

	/**
	 * Write every linked client and contact person back to Nextcloud Contacts now.
	 *
	 * @return JSONResponse The run: when, how many cards, which failed.
	 *
	 * @spec openspec/changes/contacts-sync-settings/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function run(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		// Writing the CRM into addressbooks is a CRM capability, as the
		// single-record write-back is.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Forbidden')], Http::STATUS_FORBIDDEN);
		}

		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		return new JSONResponse($this->settings->run(userId: $user->getUID(), now: $now));
	}//end run()
}//end class
