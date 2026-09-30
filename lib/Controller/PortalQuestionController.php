<?php

/**
 * Pipelinq Portal Question Controller
 *
 * The receiving end of the two portal actions of the Woo citizen journey
 * (hydra woo-citizen-journey C4): `askAboutDossier` and `replyToQuestion`.
 * portaliq forwards them server to server with a signed `X-Portal-Subject`
 * assertion (contract v2, A6). The routes are `#[PublicPage]` and
 * `#[NoCSRFRequired]` because the caller is portaliq's backend, not a browser;
 * the verified assertion is the only credential, and a Nextcloud session is
 * never a fallback.
 *
 * Order, as in the fleet reference receiver (petstore PortalActionController):
 * verify (401), audience (403), input (400), ownership (404), act (200/503).
 * All subject identity comes from the verified claims; request parameters only
 * choose the target and the text.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Portal\PortalAssertionVerifier;
use OCA\Pipelinq\Service\DossierQuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Receives portaliq's forwarded question actions.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
class PortalQuestionController extends Controller {
	/**
	 * The portal audiences that may ask and reply (C4: citizen AND client).
	 *
	 * @var array<int, string>
	 */
	public const AUDIENCES = ['citizen', 'client'];

	/**
	 * Brute-force throttler action for rejected assertions and foreign targets.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'pipelinq_portal_question';

	/**
	 * The longest question or reply accepted, in characters.
	 *
	 * @var int
	 */
	private const MAX_TEXT = 5000;

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request   The request.
	 * @param PortalAssertionVerifier $verifier  Verifies portaliq's assertion.
	 * @param DossierQuestionService  $questions Files questions and replies.
	 * @param IThrottler              $throttler Counts rejected calls.
	 * @param LoggerInterface         $logger    Logger.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAssertionVerifier $verifier,
		private readonly DossierQuestionService $questions,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `askAboutDossier`: file a question about the resident's own dossier.
	 *
	 * @return JSONResponse 201 with the ticket id; 400, 401, 403, 404 or 503.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function ask(): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef instanceof JSONResponse) {
			return $subjectRef;
		}

		$collectionId = $this->text(name: 'collectionId');
		$question = $this->text(name: 'question');
		if ($collectionId === '' || $question === '' || mb_strlen($question) > self::MAX_TEXT) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->questions->ask(
				subjectRef: $subjectRef,
				collectionId: $collectionId,
				question: $question,
				title: $this->text(name: 'title')
			);
		} catch (RuntimeException $e) {
			$this->logger->warning('Pipelinq: portal question not saved', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($result === null) {
			$this->registerRejected();
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end ask()

	/**
	 * `replyToQuestion`: add the resident's reply to their own question.
	 *
	 * @return JSONResponse 200 with the replies; 400, 401, 403, 404 or 503.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function reply(): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef instanceof JSONResponse) {
			return $subjectRef;
		}

		$ticketId = $this->text(name: 'ticket');
		$message = $this->text(name: 'message');
		if ($ticketId === '' || $message === '' || mb_strlen($message) > self::MAX_TEXT) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->questions->reply(subjectRef: $subjectRef, ticketId: $ticketId, message: $message);
		} catch (RuntimeException $e) {
			$this->logger->warning('Pipelinq: portal reply not saved', ['reason' => $e->getMessage()]);
			return new JSONResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($result === null) {
			$this->registerRejected();
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end reply()

	/**
	 * The verified subject reference, or the refusal to return.
	 *
	 * @return string|JSONResponse
	 */
	private function subjectRef(): string|JSONResponse {
		$claims = $this->verifier->verify((string)$this->request->getHeader(PortalAssertionVerifier::HEADER));
		if ($claims === null) {
			$this->registerRejected();
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		if (in_array((string)($claims['audience'] ?? ''), self::AUDIENCES, true) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return (string)$claims['sub'];
	}//end subjectRef()

	/**
	 * A trimmed string parameter, or '' when absent or not a string.
	 *
	 * @param string $name The parameter name.
	 *
	 * @return string
	 */
	private function text(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()

	/**
	 * Count a rejected call with the brute-force throttler.
	 *
	 * @return void
	 */
	private function registerRejected(): void {
		try {
			$this->throttler->registerAttempt(action: self::THROTTLE_ACTION, ip: $this->request->getRemoteAddress());
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: registerAttempt failed: '.$e->getMessage());
		}
	}//end registerRejected()
}//end class
