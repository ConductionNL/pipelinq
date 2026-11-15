<?php

/**
 * Pipelinq PosControllerResponses.
 *
 * The session check and response mapping shared by the POS controllers.
 *
 * @category Util
 * @package  OCA\Pipelinq\Controller\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller\Support;

use OCA\Pipelinq\Controller\PosResponses;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Builds the shared PosResponses and answers the acting user id or a 401. The
 * host class provides IUserSession `$userSession`, IL10N `$l10n` and
 * LoggerInterface `$logger` properties, and runAsUser() its run().
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait PosControllerResponses {

	/**
	 * Require an authenticated user.
	 *
	 * @return string|JSONResponse The acting user UID, or a 401 response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function requireUserId(): string|JSONResponse {
		return $this->posResponses()->userIdOr401(user: $this->userSession->getUser());
	}//end requireUserId()

	/**
	 * Run an action for the authenticated user through the host's run().
	 *
	 * @param callable $action The action; it receives the acting user UID.
	 * @param string   $label  A short label for log context.
	 *
	 * @return JSONResponse The 401, or the action's response.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function runAsUser(callable $action, string $label): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(action: fn (): array => $action($uid), label: $label);
	}//end runAsUser()

	/**
	 * The login check and error mapping shared by the POS controllers.
	 *
	 * @return PosResponses The shared responses.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function posResponses(): PosResponses {
		return new PosResponses(l10n: $this->l10n, logger: $this->logger);
	}//end posResponses()
}//end trait
