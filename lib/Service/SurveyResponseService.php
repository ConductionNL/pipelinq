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
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\SurveyResponseServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Open and answer a survey by its per-invitation token.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
class SurveyResponseService implements SurveyResponseServiceInterface {
	/**
	 * App-config key of the satisfaction survey schema.
	 *
	 * @var string
	 */
	private const SURVEY_SCHEMA_KEY = 'survey_schema';

	/**
	 * App-config key of the client schema.
	 *
	 * @var string
	 */
	private const CLIENT_SCHEMA_KEY = 'client_schema';

	/**
	 * The survey fields the public page shows.
	 *
	 * @var array<int, string>
	 */
	private const SURVEY_PAGE_FIELDS = ['id', 'title', 'description', 'questions'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SurveyDispatchService $dispatchService Reads and writes invitations.
	 * @param DetractorFollowUpService $followUp Classifies and closes the loop.
	 * @param SurveyOptOutService $optOut Writes the contact's standing opt-out.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly SurveyDispatchService $dispatchService,
		private readonly DetractorFollowUpService $followUp,
		private readonly SurveyOptOutService $optOut,
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
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function stateOf(?array $invitation, ?DateTimeImmutable $now = null): string {
		if ($invitation === null) {
			return 'unknown';
		}

		$now = ($now ?? new DateTimeImmutable());

		return $this->stateAt(invitation: $invitation, now: $now);
	}//end stateOf()

	/**
	 * The state of a known invitation at a moment.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param DateTimeImmutable $now The reference time.
	 *
	 * @return string open, closed, expired or unknown.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	private function stateAt(array $invitation, DateTimeImmutable $now): string {
		$status = (string)($invitation['status'] ?? '');

		if ($status === 'responded') {
			return 'closed';
		}

		if ($status === 'expired') {
			return 'expired';
		}

		if ($this->pastExpiry(invitation: $invitation, now: $now) === true) {
			return 'expired';
		}

		return $this->liveState(status: $status);
	}//end stateAt()

	/**
	 * Whether the invitation carries a readable expiry before the moment.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param DateTimeImmutable $now The reference time.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	private function pastExpiry(array $invitation, DateTimeImmutable $now): bool {
		$expiresAt = trim((string)($invitation['expiresAt'] ?? ''));
		if ($expiresAt !== '') {
			try {
				if (new DateTimeImmutable($expiresAt) < $now) {
					return true;
				}
			} catch (Throwable $e) {
				$this->logger->debug('SurveyResponseService: an invitation carries an unusable expiry', ['expiresAt' => $expiresAt]);
			}
		}

		return false;
	}//end pastExpiry()

	/**
	 * The state of an unexpired invitation by its status.
	 *
	 * @param string $status The invitation status.
	 *
	 * @return string open or unknown.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	private function liveState(string $status): string {
		if ($status === 'sent' || $status === 'scheduled') {
			return 'open';
		}

		// Suppressed and failed: nothing was ever delivered under this token,
		// so it is not a link anybody legitimately holds.
		return 'unknown';
	}//end liveState()

	/**
	 * The HTTP status a refused token answers with.
	 *
	 * A token nobody ever held is a 404: saying "gone" about it would
	 * confirm that it once existed. A token that was delivered and has since
	 * expired or been used is a 410, which tells the holder the link is dead
	 * rather than mistyped.
	 *
	 * @param string $state The state stateOf() returned.
	 *
	 * @return int 404 for an unknown token, 410 for a spent one.
	 */
	private function refusalStatus(string $state): int {
		if ($state === 'unknown') {
			return 404;
		}

		return 410;
	}//end refusalStatus()

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
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function show(string $token): array {
		$invitation = $this->invitationFor(token: $token);
		$state = $this->stateOf(invitation: $invitation);

		if ($state !== 'open') {
			return ['status' => $this->refusalStatus(state: $state), 'state' => $state];
		}

		return [
			'status' => 200,
			'state' => 'open',
			'survey' => array_intersect_key(
				$this->readPublic(uuid: (string)($invitation['surveyRef'] ?? ''), schemaKey: self::SURVEY_SCHEMA_KEY),
				array_flip(self::SURVEY_PAGE_FIELDS)
			),
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
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function score(array $survey, array $answers): array {
		$parts = ['nps' => null, 'ratings' => [], 'verbatim' => ''];

		foreach (($survey['questions'] ?? []) as $question) {
			$parts = $this->scoreAnswer(parts: $parts, question: $question, answers: $answers);
		}

		return [
			'npsScore' => $parts['nps'],
			'averageRating' => $this->averageRating(ratings: $parts['ratings']),
			'verbatim' => $parts['verbatim'],
		];
	}//end score()

	/**
	 * Add one question's answer to the score parts.
	 *
	 * @param array{nps: int|null, ratings: list<float>, verbatim: string} $parts The parts so far.
	 * @param mixed $question The question.
	 * @param array<string, mixed> $answers The answers, keyed by question key.
	 *
	 * @return array{nps: int|null, ratings: list<float>, verbatim: string}
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	private function scoreAnswer(array $parts, $question, array $answers): array {
		$key = (string)($question['key'] ?? '');
		$kind = (string)($question['kind'] ?? '');
		$answer = ($answers[$key] ?? null);

		if ($kind === 'nps' && is_numeric($answer) === true) {
			$parts['nps'] = (int)$answer;
			return $parts;
		}

		if ($kind === 'rating' && is_numeric($answer) === true) {
			$parts['ratings'][] = (float)$answer;
			return $parts;
		}

		return $this->withVerbatim(parts: $parts, kind: $kind, answer: $answer);
	}//end scoreAnswer()

	/**
	 * Take the answer as the verbatim when none was found yet.
	 *
	 * @param array{nps: int|null, ratings: list<float>, verbatim: string} $parts The parts so far.
	 * @param string $kind The question kind.
	 * @param mixed $answer The answer.
	 *
	 * @return array{nps: int|null, ratings: list<float>, verbatim: string}
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	private function withVerbatim(array $parts, string $kind, $answer): array {
		if ($parts['verbatim'] === '') {
			$parts['verbatim'] = $this->verbatimOf(kind: $kind, answer: $answer);
		}

		return $parts;
	}//end withVerbatim()

	/**
	 * The average rating, rounded to two decimals.
	 *
	 * @param list<float> $ratings The ratings given.
	 *
	 * @return float|null Null, not 0, when nobody rated.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	private function averageRating(array $ratings): float|null {
		// Null, not 0: nobody rated is not the same as everybody rated zero.
		$averageRating = null;
		if ($ratings !== []) {
			$averageRating = round((array_sum($ratings) / count($ratings)), 2);
		}

		return $averageRating;
	}//end averageRating()

	/**
	 * The verbatim a single answer contributes, or '' when it contributes none.
	 *
	 * @param string $kind The question's kind.
	 * @param mixed $answer The answer given.
	 *
	 * @return string The trimmed text, or ''.
	 */
	private function verbatimOf(string $kind, mixed $answer): string {
		if ($kind !== 'text') {
			return '';
		}

		return trim((string)$answer);
	}//end verbatimOf()

	/**
	 * Accept an answer against a token.
	 *
	 * @param string $token The token.
	 * @param array<string, mixed> $answers The answers.
	 * @param bool|null $optOut Whether the respondent asked never to be asked
	 *   again. Null when the form carried no answer to that question; only an
	 *   explicit true records the opt-out.
	 *
	 * @return array<string, mixed> `status` plus either the response or `state`.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function submit(string $token, array $answers, ?bool $optOut = null): array {
		$invitation = $this->invitationFor(token: $token);
		$state = $this->stateOf(invitation: $invitation);

		if ($state !== 'open') {
			return ['status' => $this->refusalStatus(state: $state), 'state' => $state];
		}

		$now = new DateTimeImmutable();
		$survey = $this->readPublic(uuid: (string)($invitation['surveyRef'] ?? ''), schemaKey: self::SURVEY_SCHEMA_KEY);
		$scored = $this->score(survey: $survey, answers: $answers);

		$response = array_merge(
			[
				'surveyRef' => (string)($invitation['surveyRef'] ?? ''),
				'invitationRef' => $this->uuidOf(row: $invitation),
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
			uuid: $this->uuidOf(row: $invitation),
		);

		if ($optOut === true) {
			$this->optOut->record(contactRef: (string)($invitation['contactRef'] ?? ''));
		}

		$response = $this->followUp->process(
			response: $response,
			client: $this->readPublic(uuid: (string)($invitation['clientRef'] ?? ''), schemaKey: self::CLIENT_SCHEMA_KEY),
		);

		return ['status' => 201, 'response' => $response];
	}//end submit()

	/**
	 * Read one object on the public path, only from pipelinq's register and
	 * the schema the app config names for it, under OpenRegister's RBAC.
	 *
	 * An id that names an object of any other register or schema reads as
	 * nothing, so an invitation can only point at a survey or a client.
	 *
	 * @param string $uuid      The object's uuid.
	 * @param string $schemaKey The app-config key of the schema it must belong to.
	 *
	 * @return array<string, mixed> The object, or [].
	 */
	private function readPublic(string $uuid, string $schemaKey): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if (trim($uuid) === '' || $register === '' || $schema === '') {
			return [];
		}

		$entity = $this->findPublic(uuid: $uuid, register: $register, schema: $schema, schemaKey: $schemaKey);
		if ($this->outOfScope(entity: $entity, register: $register, schema: $schema) === true) {
			return [];
		}

		return $this->entityData(entity: $entity);
	}//end readPublic()

	/**
	 * Find an object on the public path, or null when it cannot be read.
	 *
	 * @param string $uuid The object uuid.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $schemaKey The app-config key of the schema, for the log.
	 *
	 * @return mixed
	 */
	private function findPublic(string $uuid, string $register, string $schema, string $schemaKey) {
		try {
			return $this->objectService->find(id: $uuid, register: $register, schema: $schema, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyResponseService: an object on the public path could not be read',
				['schemaKey' => $schemaKey, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);

			return null;
		}
	}//end findPublic()

	/**
	 * Whether the entity is missing or lives outside the expected register and schema.
	 *
	 * @param mixed $entity The found entity, or null.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return bool
	 */
	private function outOfScope($entity, string $register, string $schema): bool {
		return $entity === null
			|| (string)$entity->getRegister() !== $register
			|| (string)$entity->getSchema() !== $schema;
	}//end outOfScope()

	/**
	 * The entity's serialised data, or an empty array when it does not serialise to one.
	 *
	 * @param mixed $entity The entity.
	 *
	 * @return array<string, mixed>
	 */
	private function entityData($entity): array {
		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end entityData()

	/**
	 * The uuid a stored row carries, under either of the two names it uses.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function uuidOf(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? '');
	}//end uuidOf()

	/**
	 * Write the response.
	 *
	 * @param array<string, mixed> $response The response.
	 *
	 * @return string The response's uuid, or '' when it could not be written.
	 */
	private function writeResponse(array $response): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'surveyResponse_schema', '');
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
