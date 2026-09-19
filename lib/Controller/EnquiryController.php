<?php

/**
 * Public website enquiry intake endpoint.
 *
 * ⚠️ This class MUST NOT `extends` — nor name in any resolved position — a
 * class from another app. Nextcloud's router `ReflectionClass()`es every file
 * in `lib/Controller/` while MATCHING a route, so an unresolvable parent makes
 * EVERY route in pipelinq return HTTP 500, not just this one. See the same
 * warning on HealthController.
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
 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EnquiryIntakeService;
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
 * Takes a form submission from a public website and stores it as an enquiry.
 */
class EnquiryController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request The current request.
	 * @param EnquiryIntakeService $intake  The intake service.
	 * @param LoggerInterface      $logger  Logger.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		IRequest $request,
		private readonly EnquiryIntakeService $intake,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * POST /api/enquiry — accept one website enquiry, from anyone.
	 *
	 * The rate-limit ceiling is deliberately low. This is a human filling in a
	 * form, so twenty a minute from one source is already far beyond what a
	 * person does and well within what a contact-form spammer attempts.
	 *
	 * @param string $title        Short summary of the enquiry.
	 * @param string $source       Which form this came from. Checked against an allowlist.
	 * @param string $contactName  Name the submitter typed.
	 * @param string $contactEmail Email address the submitter typed.
	 * @param string $contactPhone Phone number the submitter typed.
	 * @param string $organisation Organisation the submitter named.
	 * @param string $message      The submitter's own words.
	 * @param string $pageUrl      Page the form was on.
	 * @param string $locale       Language the visitor was reading in.
	 * @param string $website      Honeypot. A human never fills this in.
	 *
	 * @return JSONResponse `{id}` on 201, or `{error}` on a refusal.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-intake-endpoint-accepts-an-anonymous-submission
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function submit(
		string $title = '',
		string $source = '',
		string $contactName = '',
		string $contactEmail = '',
		string $contactPhone = '',
		string $organisation = '',
		string $message = '',
		string $pageUrl = '',
		string $locale = '',
		string $website = '',
	): JSONResponse {
		try {
			$id = $this->intake->submit(
				payload: [
					'title' => $title,
					'source' => $source,
					'contactName' => $contactName,
					'contactEmail' => $contactEmail,
					'contactPhone' => $contactPhone,
					'organisation' => $organisation,
					'message' => $message,
					'pageUrl' => $pageUrl,
					'locale' => $locale,
					'website' => $website,
				]
			);

			return $this->cors(response: new JSONResponse(['id' => $id], Http::STATUS_CREATED));
		} catch (InvalidArgumentException $e) {
			// A refusal. The message is deliberately the same for every reason,
			// so a caller cannot probe the allowlist or the honeypot's field
			// name by comparing responses.
			return $this->cors(
				response: new JSONResponse(
					['error' => 'This submission could not be accepted.'],
					Http::STATUS_BAD_REQUEST
				)
			);
		} catch (Throwable $e) {
			// This endpoint is #[PublicPage]: it is reachable unauthenticated,
			// so an uncaught throw would hand a framework 500 and a stack trace
			// to an anonymous caller. An unconfigured register reaches here,
			// and so does an OpenRegister that is absent or down.
			$this->logger->error(
				'Pipelinq: website enquiry intake failed.',
				['exception' => $e]
			);

			return $this->cors(
				response: new JSONResponse(
					['error' => 'The enquiry could not be stored right now.'],
					Http::STATUS_SERVICE_UNAVAILABLE
				)
			);
		}//end try
	}//end submit()

	/**
	 * Reflect the request Origin so the submitting page can read the result.
	 *
	 * The form posts `application/x-www-form-urlencoded`, which is a CORS
	 * simple request, so there is no preflight to answer. What is still needed
	 * is the response header, or the browser hands the page an opaque error and
	 * a visitor who submitted successfully is told it failed.
	 *
	 * Reflecting the Origin is safe here and only here: the endpoint is a
	 * public page, it carries no credentials, and no
	 * `Access-Control-Allow-Credentials` is sent, so it cannot be leveraged to
	 * read anything a session could reach. Same posture as OpenRegister's
	 * PublicApiCorsMiddleware.
	 *
	 * @param JSONResponse $response The response to decorate.
	 *
	 * @return JSONResponse The same response, with CORS headers.
	 *
	 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md#requirement-the-intake-endpoint-accepts-an-anonymous-submission
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
