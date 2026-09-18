<?php

/**
 * Pipelinq SurveyResponseService.
 *
 * What happens when somebody follows the link in their invitation.
 *
 * THE TOKEN IS SINGLE USE, AND THE SECOND USE IS REFUSED RATHER THAN IGNORED.
 * A token that silently accepted a second submission would let one person
 * answer twice, or let a forwarded link answer for somebody else, and both
 * land in the aggregate as ordinary responses nobody can pick apart.
 *
 * AN EXPIRED TOKEN OPENS A CLOSED PAGE, NOT AN ERROR. The respondent did
 * nothing wrong; the invitation ran out. Telling them that is the difference
 * between a closed survey and a broken link.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Open and answer a survey by its per-invitation token.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
class SurveyResponseService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SurveyDispatchService $dispatchService Reads and writes invitations.
	 * @param DetractorFollowUpService $followUp Classifies and closes the loop.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly SurveyDispatchService $dispatchService,
		private readonly DetractorFollowUpService $followUp,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What a token may do right now.
	 *
	 * Pure, so every branch is testable without a store: the four answers are
	 * `open`, `closed` (already answered), `expired` and `unknown`.
	 *
	 * @param array<string, mixed>|null $invitation The invitation, or null when
	 *   no invitation carries the token.
	 * @param DateTimeImmutable|null $now The moment to judge at.
	 *
	 * @return string One of open, closed, expired, unknown.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function stateOf(?array $invitation, ?DateTimeImmutable $now = null): string {
		if ($invitation === null) {
			return 'unknown';
		}

		$now = ($now ?? new DateTimeImmutable());
		$status = (string)($invitation['status'] ?? '');

		if ($status === 'responded') {
			return 'closed';
		}

		if ($status === 'expired') {
			return 'expired';
		}

		$expiresAt = trim((string)($invitation['expiresAt'] ?? ''));
		if ($expiresAt !== '') {
			try {
				if (new DateTimeImmutable($expiresAt) < $now) {
					return 'expired';
				}
			} catch (Throwable $e) {
				$this->logger->debug(
					'SurveyResponseService: an invitation carries an unusable expiry',
					['expiresAt' => $expiresAt]
				);
			}
		}

		if ($status === 'sent' || $status === 'scheduled') {
			return 'open';
		}

		// suppressed and failed: nothing was ever delivered under this token,
		// so it is not a link anybody legitimately holds.
		return 'unknown';
	}//end stateOf()

	/**
	 * The invitation a token belongs to, or null.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed>|null The invitation.
	 */
	public function invitationFor(string $token): ?array {
		$token = trim($token);
		if ($token === '') {
			return null;
		}

		foreach ($this->dispatchService->read(filters: ['token' => $token]) as $invitation) {
			// Compared again in PHP: a filter is a query hint, and a token
			// matched loosely is a token matched for somebody else.
			if (hash_equals((string)($invitation['token'] ?? ''), $token) === true) {
				return $invitation;
			}
		}

		return null;
	}//end invitationFor()

	/**
	 * The survey behind a token, when it may still be answered.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed> `status` plus either the survey or `state`.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function show(string $token): array {
		$invitation = $this->invitationFor(token: $token);
		$state = $this->stateOf(invitation: $invitation);

		if ($state !== 'open') {
			return ['status' => ($state === 'unknown' ? 404 : 410), 'state' => $state];
		}

		return [
			'status' => 200,
			'state' => 'open',
			'survey' => $this->survey(surveyRef: (string)($invitation['surveyRef'] ?? '')),
		];
	}//end show()

	/**
	 * Score a set of answers against a survey's questions.
	 *
	 * The NPS answer and the mean rating are lifted out so the aggregate does
	 * not have to read every answer of every response to draw one number.
	 *
	 * @param array<string, mixed> $survey The survey.
	 * @param array<string, mixed> $answers The answers, keyed by question key.
	 *
	 * @return array{npsScore: int|null, averageRating: float|null, verbatim: string}
	 *   The scored answers.
	 */
	public function score(array $survey, array $answers): array {
		$nps = null;
		$ratings = [];
		$verbatim = '';

		foreach (($survey['questions'] ?? []) as $question) {
			$key = (string)($question['key'] ?? '');
			$kind = (string)($question['kind'] ?? '');
			$answer = ($answers[$key] ?? null);

			if ($kind === 'nps' && is_numeric($answer) === true) {
				$nps = (int)$answer;
				continue;
			}

			if ($kind === 'rating' && is_numeric($answer) === true) {
				$ratings[] = (float)$answer;
				continue;
			}

			if ($kind === 'text' && trim((string)$answer) !== '' && $verbatim === '') {
				$verbatim = trim((string)$answer);
			}
		}

		return [
			'npsScore' => $nps,
			'averageRating' => ($ratings === [] ? null : round((array_sum($ratings) / count($ratings)), 2)),
			'verbatim' => $verbatim,
		];
	}//end score()

	/**
	 * Accept an answer against a token.
	 *
	 * @param string $token The token.
	 * @param array<string, mixed> $answers The answers.
	 * @param bool $optOut Whether the respondent asked never to be asked again.
	 *
	 * @return array<string, mixed> `status` plus either the response or `state`.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function submit(string $token, array $answers, bool $optOut = false): array {
		$invitation = $this->invitationFor(token: $token);
		$state = $this->stateOf(invitation: $invitation);

		if ($state !== 'open') {
			return ['status' => ($state === 'unknown' ? 404 : 410), 'state' => $state];
		}

		$now = new DateTimeImmutable();
		$survey = $this->survey(surveyRef: (string)($invitation['surveyRef'] ?? ''));
		$scored = $this->score(survey: $survey, answers: $answers);

		$response = array_merge(
			[
				'surveyRef' => (string)($invitation['surveyRef'] ?? ''),
				'invitationRef' => (string)($invitation['id'] ?? $invitation['uuid'] ?? ''),
				'clientRef' => (string)($invitation['clientRef'] ?? ''),
				'contactRef' => (string)($invitation['contactRef'] ?? ''),
				'linkedEntityType' => (string)($invitation['linkedEntityType'] ?? ''),
				'linkedEntityId' => (string)($invitation['linkedEntityId'] ?? ''),
				'answers' => $answers,
				'submittedAt' => $now->format(DateTimeInterface::ATOM),
			],
			$scored
		);

		$responseId = $this->writeResponse(response: $response);
		if ($responseId === '') {
			return ['status' => 500, 'error' => 'The response could not be saved.'];
		}

		$response['id'] = $responseId;

		// The invitation is closed FIRST, so a second submission arriving
		// while the follow-up is still being raised finds the token spent.
		$invitation['status'] = 'responded';
		$invitation['respondedAt'] = $now->format(DateTimeInterface::ATOM);
		$invitation['responseRef'] = $responseId;
		$this->dispatchService->write(
			invitation: $invitation,
			uuid: (string)($invitation['id'] ?? $invitation['uuid'] ?? ''),
		);

		if ($optOut === true) {
			$this->recordOptOut(contactRef: (string)($invitation['contactRef'] ?? ''));
		}

		$response = $this->followUp->process(
			response: $response,
			client: $this->client(clientId: (string)($invitation['clientRef'] ?? '')),
		);

		return ['status' => 201, 'response' => $response];
	}//end submit()

	/**
	 * Record that a contact never wants a satisfaction survey again.
	 *
	 * @param string $contactRef The contact uid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function recordOptOut(string $contactRef): void {
		$contactRef = trim($contactRef);
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($contactRef === '' || $register === '' || $schema === '') {
			return;
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'contactsUid' => $contactRef],
					'limit' => 10,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyResponseService: the opt-out could not be recorded',
				['exception' => $e->getMessage()]
			);

			return;
		}

		foreach ($rows as $row) {
			$data = ($row instanceof \JsonSerializable ? $row->jsonSerialize() : $row);
			if (is_array($data) === false) {
				continue;
			}

			$data['surveyOptOut'] = true;

			try {
				$this->objectService->saveObject(
					object: $data,
					register: $register,
					schema: $schema,
					uuid: (string)($data['id'] ?? $data['uuid'] ?? ''),
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'SurveyResponseService: the opt-out could not be saved',
					['exception' => $e->getMessage()]
				);
			}
		}
	}//end recordOptOut()

	/**
	 * Read one survey.
	 *
	 * @param string $surveyRef The survey's uuid.
	 *
	 * @return array<string, mixed> The survey, or [].
	 */
	private function survey(string $surveyRef): array {
		if (trim($surveyRef) === '') {
			return [];
		}

		try {
			// RBAC off: this is the PUBLIC path, where the token is the
			// authorisation and there is no session to check anything against.
			$entity = $this->objectService->find(id: $surveyRef, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyResponseService: the survey could not be read',
				['survey' => $surveyRef, 'exception' => $e->getMessage()]
			);

			return [];
		}

		if ($entity === null) {
			return [];
		}

		$data = $entity->jsonSerialize();

		return (is_array($data) === true ? $data : []);
	}//end survey()

	/**
	 * Read one client.
	 *
	 * @param string $clientId The client's uuid.
	 *
	 * @return array<string, mixed> The client, or [].
	 */
	private function client(string $clientId): array {
		if (trim($clientId) === '') {
			return [];
		}

		try {
			$entity = $this->objectService->find(id: $clientId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return [];
		}

		if ($entity === null) {
			return [];
		}

		$data = $entity->jsonSerialize();

		return (is_array($data) === true ? $data : []);
	}//end client()

	/**
	 * Write the response.
	 *
	 * @param array<string, mixed> $response The response.
	 *
	 * @return string The response's uuid, or '' when it could not be written.
	 */
	private function writeResponse(array $response): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyResponse_schema', '');
		if ($register === '' || $schema === '') {
			return '';
		}

		try {
			$saved = $this->objectService->saveObject(
				object: $response,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
			);

			return (string)($saved->getUuid() ?? '');
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyResponseService: the response could not be saved',
				['exception' => $e->getMessage()]
			);

			return '';
		}
	}//end writeResponse()
}//end class
