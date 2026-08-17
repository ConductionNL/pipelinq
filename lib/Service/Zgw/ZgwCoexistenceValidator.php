<?php

/**
 * Pipelinq ZgwCoexistenceValidator.
 *
 * Encodes the REQ-ZGW-008 rule that exactly one of (StUF endpoint,
 * ZgwEndpoint) may be the active write path per gemeente at any time:
 * `validateWritePath()` raises DoubleWritePathException when both backends
 * are configured to write on the same gemeente.
 *
 * NOT CURRENTLY ENFORCED ANYWHERE, and the previous wording of this docblock
 * ("Called before any createZaak flow ... so the pipeline blocks before
 * either external call is issued") described enforcement that does not
 * exist. Two independent reasons, both true at HEAD:
 *
 *   1. `validateWritePath()` has zero callers (hydra gate-6, orphan-auth).
 *      So does `ZrcClient::createZaak()` — the "createZaak flow" this class
 *      claimed to protect is itself unwired, so there is no call site to
 *      insert the check into.
 *   2. Even if it were called it could not fire. The `stufEndpoint` schema
 *      was relocated to procest by the archived
 *      `2026-06-23-pipelinq-stuf-zkn-removal` change, so
 *      `activeStufWriters()` can only ever return `[]` and the
 *      both-backends-active branch is unreachable.
 *
 * The class is retained deliberately (that removal change kept it on
 * purpose), but it must not be read as a live guard. Wiring it as-is would
 * install a check that is structurally incapable of rejecting anything —
 * a green gate protecting nothing. Restoring real enforcement requires the
 * StUF schema to be reachable from pipelinq again AND a write path to guard;
 * the natural hook at that point is OpenRegister's pre-persist, stoppable
 * `ObjectCreatingEvent`/`ObjectUpdatingEvent` on the endpoint schemas, since
 * ZGW endpoints are OpenRegister-object-managed (ADR-022) rather than
 * written through a pipelinq route. Tracked in pipelinq#764 ("Decision
 * needed: consume-or-remove the 7 dormant capabilities behind gate-6 and
 * gate-57"), which carries the full per-finding evidence.
 *
 * The check is otherwise intentionally tolerant: a missing StUF schema
 * reduces to "ZGW only", and a ZgwEndpoint with `actief=false` or
 * `readOnly=true` is excluded from the write-path count.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-06-14-zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Log\LoggerInterface;

/**
 * Coexistence validator (write-path conflict detector).
 */
class ZgwCoexistenceValidator {
	/**
	 * StUF endpoint schema slug introduced by `stuf-zkn-bg-adapter`.
	 */
	public const STUF_ENDPOINT_SCHEMA = 'stufEndpoint';

	/**
	 * Constructor.
	 *
	 * @param ZgwRegisterAccess $registers Register facade.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private ZgwRegisterAccess $registers,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate that at most one write path is active for a gemeente.
	 *
	 * @param string $municipalityCode CBS 4-digit gemeente code.
	 *
	 * @return void
	 *
	 * @throws DoubleWritePathException When both ZGW + StUF are write-enabled.
	 *
	 * @orphan-auth exclude guards against two write paths being active for one gemeente at once. It
	 * throws rather than returning, so wiring it changes behaviour on a live
	 * ZGW/StUF coexistence path and is a decision, not a refactor. Pending in
	 * pipelinq#764.
	 */
	public function validateWritePath(string $municipalityCode): void {
		if ($municipalityCode === '') {
			return;
		}

		$zgwWriters = $this->activeZgwWriters(municipalityCode: $municipalityCode);
		$stufWriters = $this->activeStufWriters(municipalityCode: $municipalityCode);

		if ($zgwWriters !== [] && $stufWriters !== []) {
			$conflicting = array_values(array_unique(array_merge($zgwWriters, $stufWriters)));
			$msg = sprintf(
				'ZGW: dubbele schrijfpad-conflict voor gemeente "%s" — schakel een van de volgende endpoints uit: %s',
				$municipalityCode,
				implode(', ', $conflicting)
			);
			$this->logger->warning(
				'ZGW: double-write conflict detected',
				[
					'gemeente' => $municipalityCode,
					'conflicting' => $conflicting,
				]
			);
			throw new DoubleWritePathException($msg, $conflicting);
		}
	}//end validateWritePath()

	/**
	 * IDs of all active ZGW write endpoints for a gemeente.
	 *
	 * @param string $municipalityCode CBS code.
	 *
	 * @return array<int, string>
	 */
	private function activeZgwWriters(string $municipalityCode): array {
		$rows = $this->registers->findAll(
			ZgwRegisterAccess::SCHEMA_ENDPOINT,
			['municipalityCode' => $municipalityCode]
		);
		$ids = [];
		foreach ($rows as $row) {
			$actief = (bool)($row['active'] ?? false);
			$readOnly = (bool)($row['readOnly'] ?? false);
			if ($actief === false || $readOnly === true) {
				continue;
			}

			$id = (string)($row['id'] ?? ($row['@self']['slug'] ?? ''));
			if ($id !== '') {
				$ids[] = 'zgw:' . $id;
			}
		}

		return $ids;
	}//end activeZgwWriters()

	/**
	 * IDs of all active StUF write endpoints for a gemeente.
	 *
	 * @param string $municipalityCode CBS code.
	 *
	 * @return array<int, string>
	 */
	private function activeStufWriters(string $municipalityCode): array {
		$rows = $this->registers->findAll(
			self::STUF_ENDPOINT_SCHEMA,
			['municipalityCode' => $municipalityCode]
		);
		$ids = [];
		foreach ($rows as $row) {
			$write = (string)($row['write'] ?? ($row['richting'] ?? ''));
			if ($write !== 'on' && $write !== 'true' && $write !== '1') {
				continue;
			}

			$id = (string)($row['id'] ?? ($row['@self']['slug'] ?? ''));
			if ($id !== '') {
				$ids[] = 'stuf:' . $id;
			}
		}

		return $ids;
	}//end activeStufWriters()
}//end class
