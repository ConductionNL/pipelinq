<?php

/**
 * Pipelinq SurveyDispatchService.
 *
 * Decides whether a completed interaction earns a satisfaction survey, and
 * writes the invitation that says so either way.
 *
 * THE GUARD CHAIN IS ORDERED, AND EVERY REFUSAL IS PERSISTED. A dispatch that
 * decided not to send is a fact: without it a low response rate is a mystery,
 * and "we never asked" looks exactly like "they never answered". So a
 * suppressed invitation is written with a machine-readable reason rather than
 * dropped.
 *
 * THE INTERACTION IS NEVER BLOCKED. A contact moment closing is the user's
 * work; a survey invitation is ours. Anything that fails here fails on its own
 * and leaves the interaction's save alone.
 *
 * THE COOLDOWN IS PER CONTACT, ACROSS SURVEYS. Somebody who was asked about a
 * permit last week must not be asked about a complaint today merely because it
 * is a different questionnaire. Survey fatigue is counted per person, not per
 * survey.
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
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\SurveyDispatchServiceInterface;
use OCA\Pipelinq\Service\Support\JsonSerializableRows;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Match dispatch rules, run the guards, and write the invitation.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
 */
class SurveyDispatchService implements SurveyDispatchServiceInterface {
	use JsonSerializableRows;

	/**
	 * The app-config key holding the dispatch rules, as a JSON array.
	 *
	 * @var string
	 */
	public const RULES_KEY = 'survey_dispatch_rules';

	/**
	 * The default cooldown, in days, when a rule does not set one.
	 *
	 * @var int
	 */
	public const DEFAULT_COOLDOWN_DAYS = 30;

	/**
	 * The default token lifetime, in days.
	 *
	 * @var int
	 */
	public const DEFAULT_EXPIRY_DAYS = 30;

	/**
	 * Upper bound on the invitations read while judging a cooldown.
	 *
	 * @var int
	 */
	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the rules and schema ids.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ISecureRandom $random Source of the per-invitation token.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured dispatch rules.
	 *
	 * @return array<int, array<string, mixed>> The rules, or [] when unset or unreadable.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function rules(): array {
		$raw = $this->appConfig->getValueString(AppIdentity::APP_ID, self::RULES_KEY, '[]');

		try {
			$rules = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyDispatchService: the dispatch rules are not valid JSON; no survey will be sent',
				['exception' => $e->getMessage()]
			);

			return [];
		}

		if (is_array($rules) === false) {
			return [];
		}

		return $rules;
	}//end rules()

	/**
	 * The rules that match a completed interaction.
	 *
	 * A disabled rule matches nothing, and is inert rather than absent, so an
	 * administrator can turn one off without losing what it said.
	 *
	 * @param string $entityType The completed record's type.
	 * @param string $status The status it reached.
	 * @param string $channel The channel it ran over, where the rule filters on one.
	 *
	 * @return array<int, array<string, mixed>> The matching rules.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function matchingRules(string $entityType, string $status, string $channel = ''): array {
		$interaction = new CompletedInteraction(entityType: $entityType, status: $status, channel: $channel);

		return array_values(
			array_filter($this->rules(), fn (array $rule): bool => $this->ruleMatches(rule: $rule, interaction: $interaction))
		);
	}//end matchingRules()

	/**
	 * Whether a rule is enabled and its trigger matches the interaction.
	 *
	 * @param array<string, mixed> $rule The dispatch rule.
	 * @param CompletedInteraction $interaction The completed interaction.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	private function ruleMatches(array $rule, CompletedInteraction $interaction): bool {
		if (($rule['enabled'] ?? false) !== true) {
			return false;
		}

		$trigger = ($rule['trigger'] ?? []);
		if (is_array($trigger) === false) {
			return false;
		}

		return $interaction->matches(trigger: $trigger);
	}//end ruleMatches()

	/**
	 * Why this dispatch must be suppressed, or null to go ahead.
	 *
	 * The order is deliberate and is the order a person would use: is there
	 * somebody to ask, have they asked never to be asked, have we asked them
	 * recently, and can we reach them on this channel.
	 *
	 * @param array<string, mixed> $rule The matching rule.
	 * @param array<string, mixed> $contact The recipient.
	 * @param array<int, array<string, mixed>> $recentInvitations Every invitation
	 *   already sent to this contact, whatever the survey.
	 * @param DateTimeImmutable|null $now The moment to judge at.
	 *
	 * @return string|null The suppression reason, or null when nothing blocks.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function suppressionReason(
		array $rule,
		array $contact,
		array $recentInvitations = [],
		?DateTimeImmutable $now = null,
	): ?string {
		$now = ($now ?? new DateTimeImmutable());

		return $this->contactSuppression(contact: $contact)
			?? $this->cooldownSuppression(recentInvitations: $recentInvitations, since: $this->cooldownStart(rule: $rule, now: $now))
			?? $this->channelSuppression(rule: $rule, contact: $contact);
	}//end suppressionReason()

	/**
	 * Why the contact itself blocks a survey: no address at all, or an opt-out.
	 *
	 * @param array<string, mixed> $contact The contact.
	 *
	 * @return string|null The reason, or null.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	private function contactSuppression(array $contact): string|null {
		if (trim((string)($contact['contactsUid'] ?? $contact['id'] ?? '')) === '') {
			return 'no-channel-address';
		}

		if (($contact['surveyOptOut'] ?? false) === true) {
			return 'opt-out';
		}

		return null;
	}//end contactSuppression()

	/**
	 * The start of the rule's cooldown, or null when the rule has none.
	 *
	 * @param array<string, mixed> $rule The dispatch rule.
	 * @param DateTimeImmutable $now The reference time.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	private function cooldownStart(array $rule, DateTimeImmutable $now): ?DateTimeImmutable {
		$cooldownDays = (int)($rule['cooldownDays'] ?? self::DEFAULT_COOLDOWN_DAYS);
		if ($cooldownDays > 0) {
			return $now->modify("-{$cooldownDays} days");
		}

		return null;
	}//end cooldownStart()

	/**
	 * 'cooldown' when the contact was invited, to any survey, since the cooldown start.
	 *
	 * @param array<int, array<string, mixed>> $recentInvitations The contact's recent invitations.
	 * @param DateTimeImmutable|null $since The cooldown start, or null when the rule has no cooldown.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	private function cooldownSuppression(array $recentInvitations, ?DateTimeImmutable $since): string|null {
		if ($since !== null) {
			foreach ($recentInvitations as $invitation) {
				if ($this->sentSince(invitation: $invitation, since: $since) === true) {
					return 'cooldown';
				}
			}
		}

		return null;
	}//end cooldownSuppression()

	/**
	 * Whether an invitation was sent at or after a moment; false when it has no readable sentAt.
	 *
	 * @param mixed $invitation The invitation.
	 * @param mixed $since The cooldown start.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	private function sentSince($invitation, $since): bool {
		// Counted ACROSS surveys: somebody asked about a permit last
		// week is not fair game today because it is a different
		// questionnaire.
		$sentAt = trim((string)($invitation['sentAt'] ?? ''));
		if ($sentAt === '') {
			return false;
		}

		try {
			if (new DateTimeImmutable($sentAt) >= $since) {
				return true;
			}
		} catch (Throwable $e) {
			return false;
		}

		return false;
	}//end sentSince()

	/**
	 * 'no-channel-address' when the contact has no address on the rule's channel.
	 *
	 * @param array<string, mixed> $rule The dispatch rule.
	 * @param array<string, mixed> $contact The contact.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	private function channelSuppression(array $rule, array $contact): string|null {
		$channel = trim((string)($rule['channel'] ?? 'email'));
		if ($this->addressFor(contact: $contact, channel: $channel) === '') {
			return 'no-channel-address';
		}

		return null;
	}//end channelSuppression()

	/**
	 * The address a channel would deliver to, or '' when there is none.
	 *
	 * @param array<string, mixed> $contact The recipient.
	 * @param string $channel The channel.
	 *
	 * @return string The address.
	 */
	private function addressFor(array $contact, string $channel): string {
		if ($channel === 'email') {
			return trim((string)($contact['email'] ?? ''));
		}

		return trim((string)($contact['phone'] ?? ''));
	}//end addressFor()

	/**
	 * Build the invitation a rule produces for a contact.
	 *
	 * Pure: it decides and returns, and writes nothing. That is what makes the
	 * guard chain testable without a store, and it is what lets the caller
	 * persist a suppressed invitation with the same code path as a scheduled
	 * one.
	 *
	 * @param array<string, mixed> $rule The matching rule.
	 * @param array<string, mixed> $contact The recipient.
	 * @param array<string, mixed> $entity The record that completed.
	 * @param array<int, array<string, mixed>> $recentInvitations This contact's invitations.
	 * @param DateTimeImmutable|null $now The moment to judge at.
	 *
	 * @return array<string, mixed> The invitation to persist.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function buildInvitation(
		array $rule,
		array $contact,
		array $entity,
		array $recentInvitations = [],
		?DateTimeImmutable $now = null,
	): array {
		$occasion = new SurveyInvitationOccasion(rule: $rule, contact: $contact, entity: $entity, now: ($now ?? new DateTimeImmutable()));

		return $this->invitationFor(occasion: $occasion, recentInvitations: $recentInvitations);
	}//end buildInvitation()

	/**
	 * Draft the invitation an occasion produces and mark it scheduled or suppressed.
	 *
	 * @param SurveyInvitationOccasion         $occasion          The rule, recipient, record and moment.
	 * @param array<int, array<string, mixed>> $recentInvitations This contact's invitations.
	 *
	 * @return array<string, mixed> The invitation to persist.
	 */
	private function invitationFor(SurveyInvitationOccasion $occasion, array $recentInvitations): array {
		$invitation = $this->draftInvitation(occasion: $occasion);

		$reason = $this->suppressionReason(
			rule: $occasion->rule,
			contact: $occasion->contact,
			recentInvitations: $recentInvitations,
			now: $occasion->now,
		);

		return $this->withStatus(invitation: $invitation, reason: $reason);
	}//end invitationFor()

	/**
	 * The invitation fields an occasion produces, before its status is decided.
	 *
	 * @param SurveyInvitationOccasion $occasion The rule, recipient, record and moment.
	 *
	 * @return array<string, mixed> The invitation without status.
	 */
	private function draftInvitation(SurveyInvitationOccasion $occasion): array {
		$rule = $occasion->rule;
		$contact = $occasion->contact;
		$entity = $occasion->entity;
		$delayMinutes = max(0, (int)($rule['delayMinutes'] ?? 0));
		$expiryDays = max(1, (int)($rule['expiryDays'] ?? self::DEFAULT_EXPIRY_DAYS));

		return [
			'token' => $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC),
			'surveyRef' => trim((string)($rule['surveyRef'] ?? '')),
			'contactRef' => trim((string)($contact['contactsUid'] ?? $contact['id'] ?? '')),
			'clientRef' => trim((string)($contact['client'] ?? $entity['client'] ?? '')),
			'linkedEntityType' => trim((string)($rule['trigger']['entityType'] ?? '')),
			'linkedEntityId' => trim((string)($entity['id'] ?? $entity['uuid'] ?? '')),
			'channel' => trim((string)($rule['channel'] ?? 'email')),
			'deliveryAddress' => $this->addressFor(
				contact: $contact,
				channel: trim((string)($rule['channel'] ?? 'email')),
			),
			'dispatchRuleId' => trim((string)($rule['id'] ?? '')),
			'scheduledFor' => $occasion->now->modify("+{$delayMinutes} minutes")->format(DateTimeInterface::ATOM),
			'expiresAt' => $occasion->now->modify("+{$expiryDays} days")->format(DateTimeInterface::ATOM),
		];
	}//end draftInvitation()

	/**
	 * The invitation marked suppressed with its reason, or scheduled when there is none.
	 *
	 * @param array<string, mixed> $invitation The drafted invitation.
	 * @param string|null $reason The suppression reason, or null.
	 *
	 * @return array<string, mixed> The invitation to persist.
	 */
	private function withStatus(array $invitation, ?string $reason): array {
		if ($reason !== null) {
			$invitation['status'] = 'suppressed';
			$invitation['suppressionReason'] = $reason;

			return $invitation;
		}

		$invitation['status'] = 'scheduled';

		return $invitation;
	}//end withStatus()

	/**
	 * Create the invitations a completed interaction earns.
	 *
	 * Never throws: an interaction's save must not be blocked or rolled back
	 * by anything that happens here.
	 *
	 * @param string $entityType The completed record's type.
	 * @param array<string, mixed> $entity The record.
	 * @param array<string, mixed> $contact The recipient.
	 *
	 * @return array<int, array<string, mixed>> The invitations written.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function onInteractionCompleted(string $entityType, array $entity, array $contact): array {
		$rules = $this->rulesCompleting(entityType: $entityType, entity: $entity);
		if ($rules === []) {
			return [];
		}

		return $this->writeInvitations(rules: $rules, interaction: ['entity' => $entity, 'contact' => $contact]);
	}//end onInteractionCompleted()

	/**
	 * The rules that match the record's status and channel.
	 *
	 * @param string               $entityType The completed record's type.
	 * @param array<string, mixed> $entity     The record.
	 *
	 * @return array<int, array<string, mixed>> The matching rules.
	 */
	private function rulesCompleting(string $entityType, array $entity): array {
		return $this->matchingRules(
			entityType: $entityType,
			status: trim((string)($entity['status'] ?? '')),
			channel: trim((string)($entity['channel'] ?? '')),
		);
	}//end rulesCompleting()

	/**
	 * Build and write the invitation each matching rule produces for the contact.
	 *
	 * @param array<int, array<string, mixed>> $rules The matching rules.
	 * @param array{entity: array<string, mixed>, contact: array<string, mixed>} $interaction The completed record and the recipient.
	 *
	 * @return array<int, array<string, mixed>> The invitations written.
	 */
	private function writeInvitations(array $rules, array $interaction): array {
		$contact = $interaction['contact'];
		$recent = $this->invitationsForContact(
			contactRef: trim((string)($contact['contactsUid'] ?? $contact['id'] ?? ''))
		);

		$written = [];
		foreach ($rules as $rule) {
			$invitation = $this->buildInvitation(
				rule: $rule,
				contact: $contact,
				entity: $interaction['entity'],
				recentInvitations: $recent,
			);

			if ($this->write(invitation: $invitation) === true) {
				$written[] = $invitation;
			}
		}

		return $written;
	}//end writeInvitations()

	/**
	 * Every invitation already sent to one contact, whatever the survey.
	 *
	 * @param string $contactRef The contact uid.
	 *
	 * @return array<int, array<string, mixed>> The invitations.
	 */
	private function invitationsForContact(string $contactRef): array {
		if (trim($contactRef) === '') {
			return [];
		}

		return $this->read(filters: ['contactRef' => trim($contactRef)]);
	}//end invitationsForContact()

	/**
	 * Read invitations as plain arrays.
	 *
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function read(array $filters): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'surveyInvitation_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('SurveyDispatchService: the survey surface is not configured');

			return [];
		}

		return $this->plainRows(rows: $this->findInvitations(filters: array_merge(['register' => $register, 'schema' => $schema], $filters)));
	}//end read()

	/**
	 * Find invitations matching the filters, or none when the read fails.
	 *
	 * @param array<string, mixed> $filters The register, schema and extra filters.
	 *
	 * @return array<mixed>
	 */
	private function findInvitations(array $filters): array {
		try {
			return $this->objectService->findAll(
				[
					'filters' => $filters,
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('SurveyDispatchService: the invitation read failed', ['exception' => $e->getMessage()]);

			return [];
		}
	}//end findInvitations()

	/**
	 * Persist one invitation.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param string|null $uuid Its uuid, for an update.
	 *
	 * @return bool True when the write landed.
	 */
	public function write(array $invitation, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'surveyInvitation_schema', '');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$this->objectService->saveObject(
				object: $invitation,
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			// Logged and swallowed on purpose: the interaction that triggered
			// this has already been saved, and it must not be rolled back
			// because a survey invitation could not be written.
			$this->logger->error(
				'SurveyDispatchService: the invitation could not be saved',
				['exception' => $e->getMessage()]
			);

			return false;
		}
	}//end write()

	/**
	 * The response rate of a set of invitations, with the same figures per channel.
	 *
	 * Suppressed and failed invitations are excluded from the DENOMINATOR and
	 * reported as counts of their own: a survey nobody was sent has no
	 * response rate, and hiding that inside the percentage is how a throttle
	 * looks like disinterest.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 *
	 * @return array<string, mixed> delivered, responded, rate, suppressed, failed, byChannel.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function responseRate(array $invitations): array {
		$figures = new SurveyInvitationFigures();
		$out     = $figures->rate(invitations: $invitations);
		$out['byChannel'] = $figures->byChannel(invitations: $invitations);

		return $out;
	}//end responseRate()

	/**
	 * The invitations that fell due in the last `$days` days.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 * @param int                              $days        The period; 0 or less keeps all.
	 * @param DateTimeImmutable|null           $now         The clock, for tests.
	 *
	 * @return array<int, array<string, mixed>> The invitations inside the period.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function withinDays(array $invitations, int $days, ?DateTimeImmutable $now = null): array {
		return (new SurveyInvitationFigures())->withinDays(invitations: $invitations, days: $days, now: $now);
	}//end withinDays()
}//end class
