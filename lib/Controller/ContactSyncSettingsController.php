<?php

/**
 * Pipelinq ContactSyncSettingsController.
 *
 * The signed-in user's own choices for the sync with Nextcloud Contacts.
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
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ContactSyncPreferences;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads and saves the signed-in user's contacts sync preferences. A user only
 * ever reaches their own values: the user id comes from the session, never
 * from the request.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest               $request     The request.
	 * @param ContactSyncPreferences $preferences The preferences.
	 * @param IUserSession           $userSession The user session.
	 * @param IL10N                  $l10n        The localization service.
	 */
	public function __construct(
		IRequest $request,
		private ContactSyncPreferences $preferences,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The signed-in user's preferences and the address books on offer.
	 *
	 * @return JSONResponse The preferences.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->preferences->read(uid: $user->getUID()));
	}//end show()

	/**
	 * Save the signed-in user's preferences.
	 *
	 * @return JSONResponse The saved preferences.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$addressBook = $this->request->getParam('addressBook', '');
		$writeBack   = $this->request->getParam('writeBack', true);

		return new JSONResponse(
			$this->preferences->save(
				uid: $user->getUID(),
				addressBook: is_string($addressBook) === true ? $addressBook : '',
				writeBack: filter_var($writeBack, FILTER_VALIDATE_BOOLEAN)
			)
		);
	}//end update()
}//end class
