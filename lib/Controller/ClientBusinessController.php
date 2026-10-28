<?php

/**
 * Pipelinq ClientBusinessController.
 *
 * GET /api/clients/{id}/business: what the client does business on, in
 * sentences for the client page's side card.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ClientBusinessReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The client's business for the side card.
 *
 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
 */
class ClientBusinessController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest             $request The request.
	 * @param ClientBusinessReader $reader  The reader.
	 */
	public function __construct(
		IRequest $request,
		private readonly ClientBusinessReader $reader,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The sentences for one client. The client is read with the caller's
	 * rights, so a client the caller may not see answers 403 or 404.
	 *
	 * @param string $id The client UUID.
	 *
	 * @return JSONResponse The sentences, or the refusal.
	 *
	 * @spec openspec/changes/client-side-cards-appointments-business-knowledge/specs/client-detail/spec.md#requirement-the-client-page-says-what-the-client-does-business-on
	 */
	#[NoAdminRequired]
	public function index(string $id): JSONResponse {
		$answer = $this->reader->businessOf(clientId: $id);
		$code   = (int)$answer['status'];
		unset($answer['status']);

		return new JSONResponse(data: $answer, statusCode: $code);
	}//end index()
}//end class
