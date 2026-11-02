<?php

/**
 * Pipelinq StaffProfileController.
 *
 * The work line of a colleague: their function and department, as their
 * Nextcloud profile states them (headline and organisation). A staff resource
 * linked to a Nextcloud user shows it under its name.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git_id>
 * @link https://pipelinq.nl
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Profile\IProfileManager;
use OCP\Share\IManager as IShareManager;

/**
 * Reads a colleague's function and department from their profile.
 *
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
class StaffProfileController extends Controller {
	/**
	 * The answered fields and the profile properties they come from.
	 */
	private const FIELDS = [
		'function'   => IAccountManager::PROPERTY_HEADLINE,
		'department' => IAccountManager::PROPERTY_ORGANISATION,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserManager $userManager Finds the account.
	 * @param IAccountManager $accountManager Reads its profile properties.
	 * @param IUserSession $userSession The visiting user.
	 * @param IProfileManager $profileManager Profile and per-field visibility.
	 * @param IShareManager $shareManager Whether the visitor may discover the account.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserManager $userManager,
		private readonly IAccountManager $accountManager,
		private readonly IUserSession $userSession,
		private readonly IProfileManager $profileManager,
		private readonly IShareManager $shareManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The colleague's function (profile headline) and department (profile
	 * organisation), as the colleague's profile shows them to the visitor.
	 *
	 * The account is answered as unknown when it is disabled, its profile is
	 * off, or the visitor may not find it under the instance's user search
	 * settings, the same rules as the Nextcloud profile page. A field is only
	 * returned when the profile shows it to the visitor and its scope is not
	 * private; otherwise it reads as empty. Every unknown answer is throttled,
	 * as the Nextcloud profile page throttles a lookup of an unknown account.
	 *
	 * @param string $userId The colleague's Nextcloud user id.
	 *
	 * @return JSONResponse {function, department}, or 404 for an unknown user.
	 *
	 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
	 */
	#[NoAdminRequired]
	#[BruteForceProtection(action: 'staffProfile')]
	public function show(string $userId): JSONResponse {
		$visitor = $this->userSession->getUser();
		if ($visitor === null) {
			return $this->notFound();
		}

		$user = $this->userManager->get($userId);
		if ($user === null || $this->mayView(target: $user, visitor: $visitor) === false) {
			return $this->notFound();
		}

		return new JSONResponse(data: $this->visibleFields(target: $user, visitor: $visitor));
	}//end show()

	/**
	 * Whether the visitor may see the target's profile at all.
	 *
	 * @param IUser $target The colleague.
	 * @param IUser $visitor The signed-in user.
	 *
	 * @return bool True when the target is enabled, has a profile and is discoverable.
	 */
	private function mayView(IUser $target, IUser $visitor): bool {
		if ($target->isEnabled() === false || $this->profileManager->isProfileEnabled(user: $target) === false) {
			return false;
		}

		if ($target->getUID() === $visitor->getUID()) {
			return true;
		}

		return $this->shareManager->currentUserCanEnumerateTargetUser(currentUser: $visitor, targetUser: $target);
	}//end mayView()

	/**
	 * The answer for an account the visitor may not see, throttled.
	 *
	 * @return JSONResponse The 404.
	 */
	private function notFound(): JSONResponse {
		$response = new JSONResponse(data: ['message' => 'User not found'], statusCode: Http::STATUS_NOT_FOUND);
		$response->throttle();
		return $response;
	}//end notFound()

	/**
	 * Each field as the target's profile shows it to the visitor: its public
	 * value when the profile shows that field to the visitor, else ''.
	 *
	 * @param IUser $target The colleague.
	 * @param IUser $visitor The signed-in user.
	 *
	 * @return array<string, string> The value of each field.
	 */
	private function visibleFields(IUser $target, IUser $visitor): array {
		$account = $this->accountManager->getAccount(user: $target);
		$fields  = [];
		foreach (self::FIELDS as $field => $name) {
			$fields[$field] = '';
			if ($this->profileManager->isProfileFieldVisible(profileField: $name, targetUser: $target, visitingUser: $visitor) === true) {
				$fields[$field] = $this->publicValue(account: $account, name: $name);
			}
		}

		return $fields;
	}//end visibleFields()

	/**
	 * A profile property's value when its scope is not private, else ''.
	 *
	 * @param IAccount $account The account.
	 * @param string $name The property name.
	 *
	 * @return string The value, or ''.
	 */
	private function publicValue(IAccount $account, string $name): string {
		try {
			$property = $account->getProperty(property: $name);
		} catch (PropertyDoesNotExistException) {
			return '';
		}

		if ($property->getScope() === IAccountManager::SCOPE_PRIVATE) {
			return '';
		}

		return trim($property->getValue());
	}//end publicValue()
}//end class
