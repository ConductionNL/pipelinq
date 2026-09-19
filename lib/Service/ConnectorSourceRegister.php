<?php

/**
 * Pipelinq ConnectorSourceRegister.
 *
 * Answers one question for every part of this app that reads an Integriq
 * `Source` object: which register slug does that register answer to on THIS
 * instance, and if none, say so out loud.
 *
 * Register slugs live in `openregister_registers`, and Integriq ships a repair
 * step that renames its own from `openconnector` to `integriq`. The step runs
 * per instance, so both slugs are live across the estate at once and a literal
 * is wrong on half of it. Pipelinq had that literal in five places.
 *
 * The old-slug half is the quiet half. OpenRegister finds no register, matches
 * no rows, and returns an empty result that is byte for byte what a healthy
 * empty register returns. No exception, no 404, no log line. Measured on the
 * local dev instance, which is fully migrated: `openregister_registers` holds
 * buildiq, dossiq, integriq, learniq and stackiq, and NO `openconnector` — so
 * every one of those five reads returned nothing there and reported success.
 *
 * Three of the five call sites carried a comment saying the register slug was
 * "frozen even where the app id moved". That was the belief this class
 * replaces with a lookup.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * The slug Integriq's Source register answers to here, or an announced absence.
 *
 * One collaborator rather than a resolver injected five times, because all five
 * sites ask the same question of the same register and each one used to answer
 * it with the same literal. A shared answer also means the skip is logged in one
 * shape, so an operator reading the log can tell the five apart by the
 * `operation` field instead of by which warning text happened to be written.
 *
 * @spec exclude infrastructure seam with no feature requirement of its own; it is
 *   exercised through the five reads that call it, and pinned by
 *   tests/Unit/Support/RegisterSlugPinTest.php
 */
class ConnectorSourceRegister {

	/**
	 * The canonical register slug, which is not necessarily the one to read with.
	 *
	 * This is the name asked ABOUT. The name to READ with comes back from
	 * {@see RegisterSlugResolverInterface}, and on an unmigrated instance it is
	 * still `openconnector`.
	 *
	 * @var string
	 */
	public const CANONICAL_REGISTER = 'integriq';

	/**
	 * The `Source` schema within that register.
	 *
	 * Schema slugs were not touched by the rename, so this one is a literal and
	 * stays a literal.
	 *
	 * @var string
	 */
	public const SOURCE_SCHEMA = 'source';

	/**
	 * Constructor.
	 *
	 * @param RegisterSlugResolverInterface $slugResolver OpenRegister's published slug probe.
	 * @param LoggerInterface               $logger       PSR logger (secret-free diagnostics).
	 */
	public function __construct(
		private readonly RegisterSlugResolverInterface $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The slug to read Integriq's Source objects with, or null, logged.
	 *
	 * Returns null rather than a fallback slug on purpose. `?->slug ?? 'integriq'`
	 * would put the defect straight back: the read succeeds, matches nothing, and
	 * the caller records "no source" for a register that is simply not there.
	 *
	 * The warning is emitted here rather than left to the caller so that the
	 * absence cannot be dropped by a caller that only checks for null and
	 * returns. Five call sites, five chances to forget.
	 *
	 * @param string $operation The calling operation, named in the log line.
	 * @param string $sourceId  The source being looked for, for the log line.
	 *
	 * @return string|null The register slug to read with, or null when the
	 *                     register is not on this instance under any known slug.
	 *
	 * @spec exclude infrastructure seam with no feature requirement of its own; the five
	 *   reads that call it carry the requirements, and its own behaviour is pinned by
	 *   tests/Unit/Service/ConnectorRegisterResolutionTest.php
	 */
	public function slugOrNull(string $operation, string $sourceId=''): ?string {
		$resolution = $this->slugResolver->resolve(canonical: self::CANONICAL_REGISTER);
		if ($resolution->isResolved() === true) {
			return $resolution->slug;
		}

		$this->logger->warning(
			'Pipelinq: the connector source register is not on this instance under any of its known slugs '
			. '(' . implode(', ', $resolution->candidates) . ') — the read was skipped rather than run against '
			. 'a slug nothing answers to, which would have returned zero rows and read as "no source".',
			['operation' => $operation, 'sourceId' => $sourceId]
		);

		return null;
	}//end slugOrNull()
}//end class
