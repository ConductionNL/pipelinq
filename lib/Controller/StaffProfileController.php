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
use OCP\Accounts\IAccountManager;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * Reads a colleague's function and department from their profile.
 *
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
class StaffProfileController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserManager $userManager Finds the account.
	 * @param IAccountManager $accountManager Reads its profile properties.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserManager $userManager,
		private readonly IAccountManager $accountManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The colleague's function (profile headline) and department (profile
	 * organisation). Only properties visible to other users on this instance
	 * are returned; a private one reads as empty.
	 *
	 * @param string $userId The colleague's Nextcloud user id.
	 *
	 * @return JSONResponse {function, department}, or 404 for an unknown user.
	 *
	 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $userId): JSONResponse {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'User not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$account = $this->accountManager->getAccount(user: $user);

		return new JSONResponse(
			data: [
				'function'   => $this->visibleValue(account: $account, name: IAccountManager::PROPERTY_HEADLINE),
				'department' => $this->visibleValue(account: $account, name: IAccountManager::PROPERTY_ORGANISATION),
			]
		);
	}//end show()

	/**
	 * A profile property's value when it is visible beyond its owner, else ''.
	 *
	 * @param \OCP\Accounts\IAccount $account The account.
	 * @param string $name The property name.
	 *
	 * @return string The value, or ''.
	 */
	private function visibleValue(\OCP\Accounts\IAccount $account, string $name): string {
		try {
			$property = $account->getProperty(property: $name);
		} catch (PropertyDoesNotExistException) {
			return '';
		}

		if ($property->getScope() === IAccountManager::SCOPE_PRIVATE) {
			return '';
		}

		return trim($property->getValue());
	}//end visibleValue()
}//end class
