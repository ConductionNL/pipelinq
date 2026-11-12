<?php

/**
 * Pipelinq ExportActionResponse.
 *
 * The shared error handling of the export job and run endpoints.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs an export endpoint's action and answers its result, 404 / 403 / 422 from
 * the service's OCS exceptions, or a logged 500 with a generic message.
 *
 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
 */
final class ExportActionResponse {
	/**
	 * Constructor.
	 *
	 * @param IL10N           $l10n   The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run the action and answer its result or its failure.
	 *
	 * @param callable $action The action, returning the response data.
	 * @param string   $label  The log label (Controller::action).
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
	 */
	public function respond(callable $action, string $label): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->unexpectedFailure(failure: $e, label: $label);
		}
	}//end respond()

	/**
	 * Log an unexpected failure and answer 500 with a generic message.
	 *
	 * @param Throwable $failure The failure.
	 * @param string    $label   The log label (Controller::action).
	 *
	 * @return JSONResponse The 500 response.
	 */
	private function unexpectedFailure(Throwable $failure, string $label): JSONResponse {
		$this->logger->error($label . ' failed', ['exception' => $failure->getMessage()]);
		return new JSONResponse(
			['error' => $this->l10n->t('An unexpected error occurred')],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end unexpectedFailure()
}//end class
