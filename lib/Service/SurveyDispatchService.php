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
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Match dispatch rules, run the guards, and write the invitation.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
 */
class SurveyDispatchService {
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
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function rules(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RULES_KEY, '[]');

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
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function matchingRules(string $entityType, string $status, string $channel = ''): array {
		$matching = [];

		foreach ($this->rules() as $rule) {
			if (($rule['enabled'] ?? false) !== true) {
				continue;
			}

			$trigger = ($rule['trigger'] ?? []);
			if (is_array($trigger) === false) {
				continue;
			}

			if (trim((string)($trigger['entityType'] ?? '')) !== $entityType) {
				continue;
			}

			if (trim((string)($trigger['statusEquals'] ?? '')) !== $status) {
				continue;
			}

			$channelFilter = trim((string)($trigger['channelEquals'] ?? ''));
			if ($channelFilter !== '' && $channelFilter !== $channel) {
				continue;
			}

			$matching[] = $rule;
		}

		return $matching;
	}//end matchingRules()

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
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function suppressionReason(
		array $rule,
		array $contact,
		array $recentInvitations = [],
		?DateTimeImmutable $now = null,
	): ?string {
		$now = ($now ?? new DateTimeImmutable());

		if (trim((string)($contact['contactsUid'] ?? $contact['id'] ?? '')) === '') {
			return 'no-channel-address';
		}

		if (($contact['surveyOptOut'] ?? false) === true) {
			return 'opt-out';
		}

		$cooldownDays = (int)($rule['cooldownDays'] ?? self::DEFAULT_COOLDOWN_DAYS);
		if ($cooldownDays > 0) {
			$since = $now->modify("-{$cooldownDays} days");
			foreach ($recentInvitations as $invitation) {
				// Counted ACROSS surveys: somebody asked about a permit last
				// week is not fair game today because it is a different
				// questionnaire.
				$sentAt = trim((string)($invitation['sentAt'] ?? ''));
				if ($sentAt === '') {
					continue;
				}

				try {
					if (new DateTimeImmutable($sentAt) >= $since) {
						return 'cooldown';
					}
				} catch (Throwable $e) {
					continue;
				}
			}
		}

		$channel = trim((string)($rule['channel'] ?? 'email'));
		if ($this->addressFor(contact: $contact, channel: $channel) === '') {
			return 'no-channel-address';
		}

		return null;
	}//end suppressionReason()

	/**
	 * The address a channel would deliver to, or '' when there is none.
	 *
	 * @param array<string, mixed> $contact The recipient.
	 * @param string $channel The channel.
	 *
	 * @return string The address.
	 */
	public function addressFor(array $contact, string $channel): string {
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
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function buildInvitation(
		array $rule,
		array $contact,
		array $entity,
		array $recentInvitations = [],
		?DateTimeImmutable $now = null,
	): array {
		$now = ($now ?? new DateTimeImmutable());
		$delayMinutes = max(0, (int)($rule['delayMinutes'] ?? 0));
		$expiryDays = max(1, (int)($rule['expiryDays'] ?? self::DEFAULT_EXPIRY_DAYS));

		$invitation = [
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
			'scheduledFor' => $now->modify("+{$delayMinutes} minutes")->format(DateTimeInterface::ATOM),
			'expiresAt' => $now->modify("+{$expiryDays} days")->format(DateTimeInterface::ATOM),
		];

		$reason = $this->suppressionReason(
			rule: $rule,
			contact: $contact,
			recentInvitations: $recentInvitations,
			now: $now,
		);

		if ($reason !== null) {
			$invitation['status'] = 'suppressed';
			$invitation['suppressionReason'] = $reason;

			return $invitation;
		}

		$invitation['status'] = 'scheduled';

		return $invitation;
	}//end buildInvitation()

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
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function onInteractionCompleted(string $entityType, array $entity, array $contact): array {
		$rules = $this->matchingRules(
			entityType: $entityType,
			status: trim((string)($entity['status'] ?? '')),
			channel: trim((string)($entity['channel'] ?? '')),
		);

		if ($rules === []) {
			return [];
		}

		$recent = $this->invitationsForContact(
			contactRef: trim((string)($contact['contactsUid'] ?? $contact['id'] ?? ''))
		);

		$written = [];
		foreach ($rules as $rule) {
			$invitation = $this->buildInvitation(
				rule: $rule,
				contact: $contact,
				entity: $entity,
				recentInvitations: $recent,
			);

			if ($this->write(invitation: $invitation) === true) {
				$written[] = $invitation;
			}
		}

		return $written;
	}//end onInteractionCompleted()

	/**
	 * Every invitation already sent to one contact, whatever the survey.
	 *
	 * @param string $contactRef The contact uid.
	 *
	 * @return array<int, array<string, mixed>> The invitations.
	 */
	public function invitationsForContact(string $contactRef): array {
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
	 */
	public function read(array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyInvitation_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('SurveyDispatchService: the survey surface is not configured');

			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyDispatchService: the invitation read failed',
				['exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}//end read()

	/**
	 * Persist one invitation.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param string|null $uuid Its uuid, for an update.
	 *
	 * @return bool True when the write landed.
	 */
	public function write(array $invitation, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyInvitation_schema', '');
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
	 * The response rate of a set of invitations.
	 *
	 * Suppressed and failed invitations are excluded from the DENOMINATOR and
	 * reported as counts of their own: a survey nobody was sent has no
	 * response rate, and hiding that inside the percentage is how a throttle
	 * looks like disinterest.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 *
	 * @return array{delivered: int, responded: int, rate: float, suppressed: int, failed: int}
	 *   The figures.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function responseRate(array $invitations): array {
		$counts = ['sent' => 0, 'responded' => 0, 'suppressed' => 0, 'failed' => 0];

		foreach ($invitations as $invitation) {
			$status = (string)($invitation['status'] ?? '');
			if (isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		$delivered = ($counts['sent'] + $counts['responded']);

		$rate = 0.0;
		if ($delivered !== 0) {
			$rate = round((($counts['responded'] / $delivered) * 100), 1);
		}

		return [
			'delivered' => $delivered,
			'responded' => $counts['responded'],
			'rate' => $rate,
			'suppressed' => $counts['suppressed'],
			'failed' => $counts['failed'],
		];
	}//end responseRate()
}//end class
