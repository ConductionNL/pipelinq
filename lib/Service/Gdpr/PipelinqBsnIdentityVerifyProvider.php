<?php

/**
 * Pipelinq PipelinqBsnIdentityVerifyProvider.
 *
 * Phase-3 (ADR-047) identity-verify seam binding: pipelinq's NL BSN/BRP/RvIG
 * implementation of OpenRegister's `IdentityVerifyProvider` contract. Registered
 * into OR's `IdentityVerifyRegistry` at pipelinq boot (ADR-019), so OR's generic
 * data-subject-request case engine resolves it via the NL policy pack's
 * `identityVerifyProvider` selector (`pipelinq-bsn-brp`).
 *
 * It REUSES pipelinq's live BSN/BRP surface — `BsnValidationService` (the
 * 11-proef) and `HaalCentraalClient` (the Haal Centraal BRP lookup) — rather
 * than re-implementing identity logic inside the (now retired) AVG case engine.
 * Verification is FAIL-CLOSED: a subject is only reported `verified` when the
 * BSN is formally valid AND a BRP record is positively resolved; a missing
 * identifier, an unconfigured BRP client, or a no-match lookup never returns
 * `verified` (ADR-005 / CWE-863).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Gdpr
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use Psr\Log\LoggerInterface;

/**
 * NL BSN/BRP identity-verify provider bound into OR's DSAR case engine.
 *
 * @psalm-suppress UndefinedClass       OR's Gdpr seam classes are provided at
 *         runtime by the openregister app; static analysis runs without OR.
 * @psalm-suppress MissingDependency    Same — the OR interface is resolved at
 *         boot on a gated install only.
 *
 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
 */
class PipelinqBsnIdentityVerifyProvider implements IdentityVerifyProvider {
	/**
	 * Stable provider id — the single source of truth for the value the NL
	 * policy pack's `identityVerifyProvider` selector must name.
	 *
	 * @var string
	 */
	public const PROVIDER_ID = 'pipelinq-bsn-brp';

	/**
	 * Case payload keys probed for a BSN-shaped subject identifier, in order.
	 *
	 * @var array<int, string>
	 */
	private const SUBJECT_KEYS = [
		'bsn',
		'subjectBsn',
		'subjectIdentifier',
		'subjectId',
		'subject',
		'verzoekerBsn',
	];

	/**
	 * Constructor.
	 *
	 * @param BsnValidationService $bsnValidation The 11-proef / BSN formatter.
	 * @param HaalCentraalClient $brpClient The Haal Centraal BRP lookup.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly BsnValidationService $bsnValidation,
		private readonly HaalCentraalClient $brpClient,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The stable provider id addressed by the NL pack selector.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
	 */
	public function getProviderId(): string {
		return self::PROVIDER_ID;
	}//end getProviderId()

	/**
	 * Verify the data-subject's identity for a DSAR case via BSN + BRP.
	 *
	 * Three-state, fail-closed:
	 * - no BSN in the payload            -> `needs-more` (cannot verify yet)
	 * - BSN present but 11-proef fails   -> `failed`
	 * - BSN valid, BRP unconfigured      -> `needs-more`
	 * - BSN valid, BRP resolves a person -> `verified`
	 * - BSN valid, BRP no-match / error  -> `failed`
	 *
	 * @param string $caseUuid The DSAR case object uuid.
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return IdentityVerifyResult The three-state verification outcome.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OR's IdentityVerifyResult is a value
	 *  object constructed only through its named static factories.
	 *
	 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
	 */
	public function verify(string $caseUuid, array $case): IdentityVerifyResult {
		$bsn = $this->extractBsn(case: $case);
		if ($bsn === null) {
			return IdentityVerifyResult::needsMore(
				providerId: self::PROVIDER_ID,
				message: 'No BSN present on the case; identity cannot be established yet.'
			);
		}

		$validation = $this->bsnValidation->validate(bsnInput: $bsn);
		if ($validation['isFormeelGeldig'] === false) {
			return IdentityVerifyResult::failed(
				providerId: self::PROVIDER_ID,
				message: 'BSN failed the 11-proef (' . $validation['maskedBsn'] . ').'
			);
		}

		if ($this->brpClient->isConfigured() === false) {
			return IdentityVerifyResult::needsMore(
				providerId: self::PROVIDER_ID,
				message: 'BSN is formally valid but the BRP (Haal Centraal) client is not configured; '
					. 'positive identity requires a BRP match.'
			);
		}

		try {
			$persoon = $this->brpClient->lookupPersoon(bsn: $bsn, verzoekIdContext: $caseUuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq identity verify: BRP lookup failed',
				['case' => $caseUuid, 'exception' => $e->getMessage()]
			);
			return IdentityVerifyResult::failed(
				providerId: self::PROVIDER_ID,
				message: 'BRP lookup errored; identity not established (fail-closed).'
			);
		}

		if ($persoon === null || $persoon === []) {
			return IdentityVerifyResult::failed(
				providerId: self::PROVIDER_ID,
				message: 'No BRP record matched the supplied BSN.'
			);
		}

		return IdentityVerifyResult::verified(
			providerId: self::PROVIDER_ID,
			message: 'BSN passed the 11-proef and a BRP record was resolved.'
		);
	}//end verify()

	/**
	 * Extract a BSN-shaped subject identifier from the case payload.
	 *
	 * Probes the known subject keys and returns the first value that is a
	 * 9-digit numeric string (BSN shape). Never logs the raw value.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return string|null The BSN, or null when none is present.
	 */
	private function extractBsn(array $case): ?string {
		foreach (self::SUBJECT_KEYS as $key) {
			$value = ($case[$key] ?? null);
			if (is_string($value) === false && is_int($value) === false) {
				continue;
			}

			$candidate = trim((string)$value);
			if (strlen($candidate) === 9 && ctype_digit($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end extractBsn()
}//end class
