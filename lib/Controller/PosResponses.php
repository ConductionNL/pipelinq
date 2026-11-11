<?php

/**
 * Pipelinq PosResponses.
 *
 * The login check and the error mapping the POS controllers share.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-cash-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Answers a POS action for its controller: refuses a request without a
 * logged-in user, and maps the POS services' OCS exceptions to HTTP status
 * codes (404 not found, 422 invalid transition / bad input, 403
 * operator-/manager-only, 500 anything else, logged).
 *
 * @spec openspec/specs/pos-cash-management/spec.md
 */
final class PosResponses {
	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   The localization service.
	 * @param LoggerInterface $logger The controller's logger.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The UID of the logged-in user, or a 401 response without one.
	 *
	 * Object-level access is scoped to this app's own schemas inside the
	 * services (an object in another app/register resolves to a 404),
	 * preventing IDOR.
	 *
	 * @param IUser|null $user The session user.
	 *
	 * @return string|JSONResponse The acting user UID, or a 401 response.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function userIdOr401(?IUser $user): string|JSONResponse {
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}//end userIdOr401()

	/**
	 * Build an action's response with the shared error mapping.
	 *
	 * @param callable(): JSONResponse $respond  Runs the action and builds its success response.
	 * @param string                   $logLabel The `Controller::action` label of the failure log.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	public function run(callable $respond, string $logLabel): JSONResponse {
		try {
			return $respond();
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error($logLabel . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end run()
}//end class
