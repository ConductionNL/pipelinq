<?php

/**
 * The receiver of portaliq's forwarded question actions.
 *
 * The verified assertion is the only credential: no assertion answers 401, an
 * audience other than citizen or client answers 403, and the subject always
 * comes from the claims, never from a request parameter.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalQuestionController;
use OCA\Pipelinq\Portal\PortalAssertionVerifier;
use OCA\Pipelinq\Service\DossierQuestionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PortalQuestionController.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
 */
class PortalQuestionControllerTest extends TestCase {

	private const SECRET = 'pipelinq-question-test-secret-0123';
	private const RESIDENT = 'subj-7f3a';

	/** @var DossierQuestionService&MockObject */
	private DossierQuestionService $questions;

	/** @var IThrottler&MockObject */
	private IThrottler $throttler;

	protected function setUp(): void {
		parent::setUp();
		$this->questions = $this->createMock(DossierQuestionService::class);
		$this->throttler = $this->createMock(IThrottler::class);
	}//end setUp()

	/**
	 * A controller for one request.
	 *
	 * @param string               $assertion The X-Portal-Subject header value.
	 * @param array<string, mixed> $params    The forwarded body.
	 *
	 * @return PortalQuestionController
	 */
	private function controller(string $assertion, array $params): PortalQuestionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => ($name === 'X-Portal-Subject') ? $assertion : '');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$request->method('getRemoteAddress')->willReturn('127.0.0.1');

		return new PortalQuestionController(
			request: $request,
			verifier: new PortalAssertionVerifier(config: null, secretOverride: self::SECRET),
			questions: $this->questions,
			throttler: $this->throttler,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * An assertion as portaliq mints it.
	 *
	 * @param string $audience The subject's audience.
	 * @param string $secret   The signing secret.
	 *
	 * @return string
	 */
	private function assertion(string $audience = 'citizen', string $secret = self::SECRET): string {
		$iat = time();
		$claims = ['sub' => self::RESIDENT, 'audience' => $audience, 'organisation' => '', 'trust' => 'substantial', 'jti' => 'jti-1', 'use' => 'assertion', 'iat' => $iat, 'exp' => ($iat + 60), 'iss' => 'portaliq'];
		$h = $this->b64(bytes: (string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
		$c = $this->b64(bytes: (string)json_encode($claims));

		return $h . '.' . $c . '.' . $this->b64(bytes: hash_hmac('sha256', $h . '.' . $c, $secret, true));
	}//end assertion()

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Raw bytes.
	 *
	 * @return string
	 */
	private function b64(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64()

	/**
	 * Scenario: A resident asks about their own dossier (both audiences).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAResidentAsksWithTheSubjectFromTheAssertion(): void {
		foreach (['citizen', 'client'] as $audience) {
			$questions = $this->createMock(DossierQuestionService::class);
			$questions->expects($this->once())->method('ask')
				->with(self::RESIDENT, 'dossier-1', 'Wanneer valt het besluit?', 'Termijn')
				->willReturn(['id' => 't-1', 'status' => 'new']);
			$this->questions = $questions;

			$response = $this->controller(
				assertion: $this->assertion(audience: $audience),
				params: ['collection' => 'dossier-1', 'question' => ' Wanneer valt het besluit? ', 'title' => 'Termijn', 'subjectRef' => 'forged']
			)->ask();

			$this->assertSame(Http::STATUS_CREATED, $response->getStatus(), $audience);
			$this->assertSame(['id' => 't-1', 'status' => 'new'], $response->getData());
		}
	}//end testAResidentAsksWithTheSubjectFromTheAssertion()

	/**
	 * Scenario: A call without a valid assertion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testNoValidAssertionIsUnauthorisedAndCounted(): void {
		$this->questions->expects($this->never())->method('ask');
		$this->questions->expects($this->never())->method('reply');
		$this->throttler->expects($this->exactly(3))->method('registerAttempt');

		$params = ['collection' => 'dossier-1', 'question' => 'Vraag', 'ticket' => 't-1', 'message' => 'Hallo'];
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(assertion: '', params: $params)->ask()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(assertion: $this->assertion(secret: 'another-secret-abcdefghijkl'), params: $params)->ask()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(assertion: 'not.a.jwt', params: $params)->reply()->getStatus());
	}//end testNoValidAssertionIsUnauthorisedAndCounted()

	/**
	 * An audience other than citizen or client may not ask or reply.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAnotherAudienceIsForbidden(): void {
		$this->questions->expects($this->never())->method('ask');
		$params = ['collection' => 'dossier-1', 'question' => 'Vraag', 'ticket' => 't-1', 'message' => 'Hallo'];

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(assertion: $this->assertion(audience: 'supplier'), params: $params)->ask()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(assertion: $this->assertion(audience: 'customer'), params: $params)->reply()->getStatus());
	}//end testAnotherAudienceIsForbidden()

	/**
	 * An empty question or a missing dossier id is a bad request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testIncompleteInputIsABadRequest(): void {
		$this->questions->expects($this->never())->method('ask');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(assertion: $this->assertion(), params: ['collection' => 'dossier-1', 'question' => '   '])->ask()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(assertion: $this->assertion(), params: ['question' => 'Vraag'])->ask()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(assertion: $this->assertion(), params: ['collection' => 'dossier-1', 'question' => str_repeat('a', 5001)])->ask()->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(assertion: $this->assertion(), params: ['ticket' => 't-1'])->reply()->getStatus());
	}//end testIncompleteInputIsABadRequest()

	/**
	 * Scenario: A resident names someone else's dossier (404, counted).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testSomeoneElsesDossierIsNotFound(): void {
		$this->questions->method('ask')->willReturn(null);
		$this->throttler->expects($this->once())->method('registerAttempt');

		$response = $this->controller(assertion: $this->assertion(), params: ['collection' => 'dossier-2', 'question' => 'Vraag'])->ask();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSomeoneElsesDossierIsNotFound()

	/**
	 * Scenario: A resident replies, and a reply to someone else's question is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	public function testAResidentReplies(): void {
		$this->questions->method('reply')->willReturnCallback(
			static fn (string $subjectRef, string $ticketId, string $message): ?array => ($ticketId === 't-1')
				? ['id' => 't-1', 'status' => 'in_progress', 'portalReplies' => [['message' => $message, 'createdAt' => 'now']]]
				: null
		);

		$ok = $this->controller(assertion: $this->assertion(), params: ['ticket' => 't-1', 'message' => 'Dank u'])->reply();
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame('in_progress', $ok->getData()['status']);

		$foreign = $this->controller(assertion: $this->assertion(), params: ['ticket' => 't-2', 'message' => 'Dank u'])->reply();
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
	}//end testAResidentReplies()

	/**
	 * A failed save answers 503 without leaking the reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function testAFailedSaveIsUnavailable(): void {
		$this->questions->method('ask')->willThrowException(new RuntimeException('Failed to persist object.'));

		$response = $this->controller(assertion: $this->assertion(), params: ['collection' => 'dossier-1', 'question' => 'Vraag'])->ask();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'unavailable'], $response->getData());
	}//end testAFailedSaveIsUnavailable()
}//end class
