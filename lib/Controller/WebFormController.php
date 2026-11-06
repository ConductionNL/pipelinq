<?php

/**
 * Public website form endpoint: a submit creates the object its form names.
 *
 * ⚠️ This class MUST NOT `extends`, nor name in any resolved position, a
 * class from another app. Nextcloud's router `ReflectionClass()`es every file
 * in `lib/Controller/` while MATCHING a route, so an unresolvable parent makes
 * EVERY route in pipelinq return HTTP 500, not just this one. OpenRegister's
 * refusal reaches this class as pipelinq's own WebFormSubmitException.
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Service\WebForm\WebFormSubmitException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes a form submit from a public website and creates its destination.
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */
class WebFormController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request The current request.
	 * @param WebFormIntakeService $intake  The website form intake.
	 * @param LoggerInterface      $logger  Logger.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		IRequest $request,
		private readonly WebFormIntakeService $intake,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST /api/web-forms/{key}/submit: submit one website form, from anyone.
	 *
	 * The rate-limit ceiling is deliberately low: twenty a minute from one
	 * source is far beyond what a person filling in a form does.
	 *
	 * @param string $key The form's key.
	 *
	 * @return JSONResponse 201 `{ id, reference, receivedAt }`, 400/422 `{ error, findings? }`, 503 `{ error }`.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function submit(string $key): JSONResponse {
		$payload = $this->request->getParams();
		unset($payload['key']);

		return $this->answer(key: $key, payload: $payload);

	}//end submit()

	/**
	 * POST /api/enquiry: the address conduction-website already posts to; its `source` is the form's key.
	 *
	 * Kept so the website's three forms keep working without a deploy. The
	 * submit no longer becomes an enquiry: it creates what its form names.
	 *
	 * @return JSONResponse 201 `{ id, reference, receivedAt }`, 400/422 `{ error, findings? }`, 503 `{ error }`.
	 *
	 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function submitBySource(): JSONResponse {
		$payload = $this->request->getParams();
		$source = ($payload['source'] ?? '');
		if (is_string($source) === false) {
			$source = '';
		}

		return $this->answer(key: trim($source), payload: $payload);

	}//end submitBySource()

	/**
	 * Run the submit and turn its outcome into a response.
	 *
	 * @param string               $key     The form's key.
	 * @param array<string, mixed> $payload The submitted fields.
	 *
	 * @return JSONResponse The response, with CORS headers.
	 */
	private function answer(string $key, array $payload): JSONResponse {
		$idempotencyKey = trim($this->request->getHeader('Idempotency-Key'));
		if ($idempotencyKey === '') {
			$idempotencyKey = null;
		}

		try {
			$answer = $this->intake->submitByKey(key: $key, payload: $payload, idempotencyKey: $idempotencyKey);

			return $this->cors(
				response: new JSONResponse(
					[
						'id' => $answer['id'],
						'reference' => $answer['reference'],
						'receivedAt' => $answer['receivedAt'],
					],
					Http::STATUS_CREATED
				)
			);
		} catch (WebFormSubmitException $e) {
			$body = ['error' => $e->getMessage()];
			if ($e->getFindings() !== []) {
				$body['findings'] = $e->getFindings();
			}

			return $this->cors(response: new JSONResponse($body, $e->getStatus()));
		} catch (Throwable $e) {
			// #[PublicPage]: an uncaught throw would hand a stack trace to an anonymous caller.
			$this->logger->error('Pipelinq: website form submit failed.', ['exception' => $e]);

			return $this->cors(
				response: new JSONResponse(['error' => WebFormIntakeService::TRY_LATER], Http::STATUS_SERVICE_UNAVAILABLE)
			);
		}//end try

	}//end answer()

	/**
	 * Reflect the request Origin so the submitting page can read the result.
	 *
	 * Safe here only: a public page, no credentials, and no
	 * `Access-Control-Allow-Credentials`, the same posture as OpenRegister's
	 * PublicApiCorsMiddleware.
	 *
	 * @param JSONResponse $response The response to decorate.
	 *
	 * @return JSONResponse The same response, with CORS headers.
	 */
	private function cors(JSONResponse $response): JSONResponse {
		$origin = $this->request->getHeader('Origin');
		if ($origin === '') {
			return $response;
		}

		$response->addHeader('Access-Control-Allow-Origin', $origin);
		$response->addHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
		$response->addHeader('Vary', 'Origin');

		return $response;

	}//end cors()
}//end class
