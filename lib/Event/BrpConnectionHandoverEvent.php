<?php

/**
 * Pipelinq BrpConnectionHandoverEvent.
 *
 * Carries pipelinq's BRP connection settings and secrets to integriq once, on
 * upgrade. integriq fills the empty fields of its `brp-haalcentraal` source,
 * mints keepiq secrets for the secret parts, and answers per part whether it
 * took it, kept a value it already had, or failed. pipelinq clears its own keys
 * only when every part is answered and none failed.
 *
 * The event carries secret material: a listener MUST NOT log or persist it
 * outside keepiq.
 *
 * @category Event
 * @package  OCA\Pipelinq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use InvalidArgumentException;
use OCP\EventDispatcher\Event;

/**
 * The BRP connection handed to integriq, and integriq's answer per part.
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */
class BrpConnectionHandoverEvent extends Event {

	/**
	 * integriq took the part: it filled the field or minted and verified the keepiq secret.
	 */
	public const TAKEN = 'taken';

	/**
	 * integriq already held a value for the part and kept it; the handed value is not used.
	 */
	public const KEPT = 'kept';

	/**
	 * integriq could not take the part; pipelinq keeps its keys.
	 */
	public const FAILED = 'failed';

	/**
	 * The plain settings: the base URL, OAuth endpoint and client id.
	 */
	public const SETTING_PARTS = ['baseUrl', 'oauthEndpoint', 'clientId'];

	/**
	 * The secret parts: the client secret and the PEM certificate, key and CA bundle.
	 */
	public const SECRET_PARTS = ['clientSecret', 'certificate', 'privateKey', 'caBundle'];

	/**
	 * The answers so far, by part.
	 *
	 * @var array<string, array{outcome: string, detail: string}>
	 */
	private array $answers = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $settings The plain settings to hand over, by part.
	 * @param array<string, string> $secrets  The secret material to hand over, by part.
	 *
	 * @throws InvalidArgumentException When a part is not a known setting or secret.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function __construct(
		private readonly array $settings,
		private readonly array $secrets,
	) {
		parent::__construct();
		self::assertKnownParts(parts: array_keys($settings), known: self::SETTING_PARTS);
		self::assertKnownParts(parts: array_keys($secrets), known: self::SECRET_PARTS);
	}//end __construct()

	/**
	 * The plain settings to hand over, by part.
	 *
	 * @return array<string, string> The settings.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function getSettings(): array {
		return $this->settings;
	}//end getSettings()

	/**
	 * The secret material to hand over, by part. Never log it.
	 *
	 * @return array<string, string> The secrets.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function getSecrets(): array {
		return $this->secrets;
	}//end getSecrets()

	/**
	 * Every part handed over, settings first.
	 *
	 * @return list<string> The part names.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function getParts(): array {
		return array_merge(array_keys($this->settings), array_keys($this->secrets));
	}//end getParts()

	/**
	 * Record integriq's answer for one part. A later answer for the same part replaces it.
	 *
	 * @param string $part    The part answered.
	 * @param string $outcome One of TAKEN, KEPT or FAILED.
	 * @param string $detail  Why, without any secret material.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the part was not handed over or the outcome is unknown.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function answer(string $part, string $outcome, string $detail=''): void {
		if (in_array($part, $this->getParts(), true) === false) {
			throw new InvalidArgumentException('Part '.$part.' was not handed over');
		}

		if (in_array($outcome, [self::TAKEN, self::KEPT, self::FAILED], true) === false) {
			throw new InvalidArgumentException('Unknown outcome '.$outcome);
		}

		$this->answers[$part] = ['outcome' => $outcome, 'detail' => $detail];
	}//end answer()

	/**
	 * The answer for one part, or null when integriq gave none.
	 *
	 * @param string $part The part.
	 *
	 * @return array{outcome: string, detail: string}|null The answer.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function getAnswer(string $part): ?array {
		return $this->answers[$part] ?? null;
	}//end getAnswer()

	/**
	 * Whether integriq answered every part and took or kept each one.
	 *
	 * @return bool True when pipelinq may clear its keys.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function isConfirmed(): bool {
		$parts = $this->getParts();
		if ($parts === []) {
			return false;
		}

		foreach ($parts as $part) {
			$outcome = ($this->answers[$part]['outcome'] ?? null);
			if ($outcome !== self::TAKEN && $outcome !== self::KEPT) {
				return false;
			}
		}

		return true;
	}//end isConfirmed()

	/**
	 * What a dump of the event shows: the parts and answers, never the secret material.
	 *
	 * @return array<string, mixed> The safe view.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function __debugInfo(): array {
		return ['parts' => $this->getParts(), 'answers' => $this->answers];
	}//end __debugInfo()

	/**
	 * Refuse a part that is not one of the known ones.
	 *
	 * @param list<string> $parts The parts given.
	 * @param list<string> $known The parts allowed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a part is unknown.
	 */
	private static function assertKnownParts(array $parts, array $known): void {
		foreach ($parts as $part) {
			if (in_array($part, $known, true) === false) {
				throw new InvalidArgumentException('Unknown BRP connection part '.$part);
			}
		}
	}//end assertKnownParts()
}//end class
