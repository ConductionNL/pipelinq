<?php

/**
 * Pipelinq PosCartController.
 *
 * The till asks the server to price the open sale it shows.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Service\PosCart\PosCartRepricer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;

/**
 * Prices an open sale: its lines, VAT per rate and total.
 *
 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
 */
class PosCartController extends Controller {
	/**
	 * The HTTP status per refusal.
	 */
	private const STATUS = [
		OCSNotFoundException::class   => Http::STATUS_NOT_FOUND,
		OCSForbiddenException::class  => Http::STATUS_FORBIDDEN,
		OCSBadRequestException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request  The request.
	 * @param PosCartRepricer $repricer Prices the sale.
	 *
	 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
	 */
	public function __construct(
		IRequest $request,
		private readonly PosCartRepricer $repricer,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Price the open sale and return it with its lines.
	 *
	 * The repricer checks that the signed-in user may change the sale (its
	 * cashier, the POS group or an admin) before it writes anything.
	 *
	 * @param string $id The sale's id.
	 *
	 * @return JSONResponse The sale and its lines, or the refusal.
	 *
	 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function recalculate(string $id): JSONResponse {
		try {
			return new JSONResponse($this->repricer->reprice(transactionId: $id));
		} catch (OCSNotFoundException | OCSForbiddenException | OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], self::STATUS[$e::class]);
		}
	}//end recalculate()
}//end class
