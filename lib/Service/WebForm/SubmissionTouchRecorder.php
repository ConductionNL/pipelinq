<?php

/**
 * Records the campaign values a website form submit carried as a touchpoint.
 *
 * The website embed passes the campaign parameters of the page it sits on
 * (`utmCampaign`, `utmSource`, `utmMedium`, `utmContent`, `utmTerm`, or their
 * `utm_*` spelling) and the page address (`pageUrl`). They describe the visit
 * only: they are read here, trimmed and capped, and never reach the form's
 * destination object unless the form itself maps them, so they cannot set a
 * server-owned field such as `status` or `source`.
 *
 * When the submit's email address matches a contact, the touchpoint names that
 * contact and TouchpointService::recordSubmission() updates the contact's and
 * its client's first and last touch. Without a match the touchpoint keeps the
 * values on its own; no contact is created here.
 *
 * Recording is best effort: the submit is already stored when this runs, and
 * a failure here is logged without the visitor's data, never thrown.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebForm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-website-enquiry-form-keeps-campaign-values-req-moc-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebForm;

use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\TouchpointService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a website form submit's campaign values into a touchpoint.
 *
 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-website-enquiry-form-keeps-campaign-values-req-moc-003
 */
class SubmissionTouchRecorder {

	/**
	 * Longest campaign value kept, in characters (design D3).
	 *
	 * @var int
	 */
	public const MAX_UTM_LENGTH = 100;

	/**
	 * Longest page address kept, in characters: the schema's `landingPage` limit.
	 *
	 * @var int
	 */
	public const MAX_PAGE_LENGTH = 2048;

	/**
	 * The campaign values a touchpoint keeps, by the field names a submit may carry them under.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const UTM_FIELDS = [
		'campaign' => ['utmCampaign', 'utm_campaign'],
		'source' => ['utmSource', 'utm_source'],
		'medium' => ['utmMedium', 'utm_medium'],
		'content' => ['utmContent', 'utm_content'],
		'term' => ['utmTerm', 'utm_term'],
	];

	/**
	 * The fields a submit carries the visitor's email address in, in order of preference.
	 *
	 * @var array<int, string>
	 */
	private const EMAIL_FIELDS = ['contactEmail', 'email'];

	/**
	 * Constructor.
	 *
	 * @param TouchpointService $touchpoints Appends the touchpoint and updates the touches.
	 * @param CampaignService   $campaigns   Resolves a campaign by its campaign value.
	 * @param ListObjectStore   $store       Finds the contact by email address.
	 * @param LoggerInterface   $logger      Logger. Submitted personal data MUST NOT be written here.
	 *
	 * @spec exclude constructor wiring only; it makes no decision a requirement can describe
	 */
	public function __construct(
		private readonly TouchpointService $touchpoints,
		private readonly CampaignService $campaigns,
		private readonly ListObjectStore $store,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record one stored submit as a touchpoint, updating a matched contact's touches.
	 *
	 * @param array<string, mixed> $form           The published form, for its id.
	 * @param array<string, mixed> $payload        The raw submitted fields.
	 * @param string|null          $idempotencyKey The submit's Idempotency-Key: a repeat records nothing.
	 *
	 * @return array<string, mixed>|null The saved touchpoint, or null when nothing was recorded.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-website-enquiry-form-keeps-campaign-values-req-moc-003
	 */
	public function record(array $form, array $payload, ?string $idempotencyKey = null): ?array {
		try {
			return $this->recordOrThrow(form: $form, payload: $payload, idempotencyKey: $idempotencyKey);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: website form submit stored, its campaign touch was not recorded.',
				['form' => (string)($form['id'] ?? ''), 'reason' => $e->getMessage()]
			);
			return null;
		}

	}//end record()

	/**
	 * The campaign values of a submit: the five keys a touchpoint's `utm` holds, trimmed and capped.
	 *
	 * The camelCase field wins over its `utm_*` spelling. A value that is not
	 * text (a list, a nested shape) is dropped, not cast.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return array<string, string> Only the keys that carry a value.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-website-enquiry-form-keeps-campaign-values-req-moc-003
	 */
	public function utmOf(array $payload): array {
		$utm = [];
		foreach (self::UTM_FIELDS as $key => $names) {
			foreach ($names as $name) {
				$value = $this->text(raw: ($payload[$name] ?? null), max: self::MAX_UTM_LENGTH);
				if ($value !== '') {
					$utm[$key] = $value;
					break;
				}
			}
		}

		return $utm;

	}//end utmOf()

	/**
	 * Record, letting a failure reach record()'s catch.
	 *
	 * @param array<string, mixed> $form           The published form.
	 * @param array<string, mixed> $payload        The raw submitted fields.
	 * @param string|null          $idempotencyKey The submit's Idempotency-Key.
	 *
	 * @return array<string, mixed>|null The saved touchpoint, or null.
	 */
	private function recordOrThrow(array $form, array $payload, ?string $idempotencyKey): ?array {
		$formId = trim((string)($form['id'] ?? ''));
		$nonce = '';
		$key = trim((string)$idempotencyKey);
		if ($key !== '') {
			$nonce = 'web-form:' . $formId . ':' . $key;
			if ($this->touchpoints->existsForNonce(nonce: $nonce) === true) {
				return null;
			}
		}

		$utm = $this->utmOf(payload: $payload);
		$row = [
			'kind' => 'submit',
			'channel' => $this->touchpoints->channelForMedium(medium: ($utm['medium'] ?? '')),
			'utm' => $utm,
			'sourceRef' => $formId,
			'occurredAt' => gmdate('Y-m-d\TH:i:sP'),
		];

		$campaignId = $this->campaignIdFor(campaign: ($utm['campaign'] ?? ''));
		if ($campaignId !== '') {
			$row['campaignId'] = $campaignId;
		}

		$contactId = $this->contactIdFor(payload: $payload);
		if ($contactId !== '') {
			$row['contactId'] = $contactId;
		}

		if ($nonce !== '') {
			$row['nonce'] = $nonce;
		}

		return $this->touchpoints->recordSubmission(
			touchpoint: $row,
			landingPage: $this->text(raw: ($payload['pageUrl'] ?? null), max: self::MAX_PAGE_LENGTH)
		);

	}//end recordOrThrow()

	/**
	 * The campaign whose campaign value is this one, or '' when none is.
	 *
	 * @param string $campaign The submitted campaign value.
	 *
	 * @return string The campaign id, or ''.
	 */
	private function campaignIdFor(string $campaign): string {
		if ($campaign === '') {
			return '';
		}

		foreach ($this->campaigns->all(filters: ['utmCampaign' => $campaign]) as $row) {
			if (trim((string)($row['utmCampaign'] ?? '')) === $campaign) {
				return $this->campaigns->idOf(campaign: $row);
			}
		}

		return '';

	}//end campaignIdFor()

	/**
	 * The contact whose email address the submit carries, or '' when none matches.
	 *
	 * Matched lowercased and then as submitted, as the landing page intake
	 * does: OpenRegister's filter has no case-insensitive equality.
	 *
	 * @param array<string, mixed> $payload The raw submitted fields.
	 *
	 * @return string The contact id, or ''.
	 */
	private function contactIdFor(array $payload): string {
		$email = '';
		foreach (self::EMAIL_FIELDS as $field) {
			$email = $this->text(raw: ($payload[$field] ?? null), max: 320);
			if ($email !== '') {
				break;
			}
		}

		if ($email === '') {
			return '';
		}

		foreach (array_unique([strtolower($email), $email]) as $candidate) {
			$rows = $this->store->findAll(schemaSlug: TouchpointService::CONTACT_SLUG, filters: ['email' => $candidate]);
			if ($rows !== []) {
				return $this->store->idOf(payload: $rows[0]);
			}
		}

		return '';

	}//end contactIdFor()

	/**
	 * A submitted value as trimmed text cut to a length, or '' when it is not text.
	 *
	 * @param mixed $raw The raw value.
	 * @param int   $max The longest value kept, in characters.
	 *
	 * @return string The text.
	 */
	private function text(mixed $raw, int $max): string {
		if (is_string($raw) === false) {
			return '';
		}

		return trim(mb_substr(trim($raw), 0, $max));

	}//end text()
}//end class
