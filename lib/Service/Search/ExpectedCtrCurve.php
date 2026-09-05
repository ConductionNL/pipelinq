<?php

/**
 * Pipelinq ExpectedCtrCurve.
 *
 * What click-through rate a result at a given position normally earns. The
 * striking-distance derivation needs this to tell a query that is under-
 * performing its position from one that is simply low: a query at position
 * twelve with a two percent click-through is doing fine, and one at position
 * eight with the same rate is not.
 *
 * THE NUMBERS AND WHERE THEY COME FROM. This is a deliberately CONSERVATIVE
 * organic desktop-and-mobile blend, rounded to the nearest tenth of a percent,
 * of the click-through curves published by Advanced Web Ranking, Sistrix and
 * Backlinko between 2023 and 2025. It is a curve, not a measurement of this
 * tenant: a branded query at position one earns far more than 28 percent and a
 * commercial one far less. That is exactly why it lives in one table with a
 * comment rather than as a literal inside a comparison. A tenant that
 * disagrees changes {@see CURVE} and nothing else.
 *
 * Search Console reports FRACTIONAL positions (an average over the window), so
 * the curve is interpolated between two integers rather than truncated to one.
 * Truncating would make a query at 10.9 be judged against position ten and
 * flag half a page of results that are doing what position eleven does.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Search
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-striking-distance-queries-are-queries-one-push-from-page-one
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

/**
 * Expected click-through by search position.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-striking-distance-queries-are-queries-one-push-from-page-one
 */
final class ExpectedCtrCurve {

	/**
	 * Click-through rate by integer position, as a fraction between 0 and 1.
	 *
	 * @var array<int, float>
	 */
	public const CURVE = [
		1 => 0.280,
		2 => 0.150,
		3 => 0.110,
		4 => 0.080,
		5 => 0.060,
		6 => 0.050,
		7 => 0.040,
		8 => 0.034,
		9 => 0.029,
		10 => 0.025,
		11 => 0.021,
		12 => 0.018,
		13 => 0.016,
		14 => 0.014,
		15 => 0.013,
		16 => 0.012,
		17 => 0.011,
		18 => 0.010,
		19 => 0.009,
		20 => 0.008,
	];

	/**
	 * The last position the curve names. Beyond it the curve is flat: the
	 * difference between position 31 and position 47 is not a difference
	 * anybody can act on.
	 *
	 * @var int
	 */
	public const TAIL_POSITION = 20;

	/**
	 * The rate used at and beyond {@see TAIL_POSITION}.
	 *
	 * @var float
	 */
	public const TAIL_RATE = 0.008;

	/**
	 * The expected click-through at a position, interpolated between the two
	 * integers around it.
	 *
	 * @param float $position The average position Search Console reported.
	 *
	 * @return float A fraction between 0 and 1.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-striking-distance-queries-are-queries-one-push-from-page-one
	 */
	public static function at(float $position): float {
		if ($position <= 1.0) {
			return self::CURVE[1];
		}

		if ($position >= (float)self::TAIL_POSITION) {
			return self::TAIL_RATE;
		}

		$lower = (int)floor($position);
		$upper = ($lower + 1);
		$fraction = ($position - (float)$lower);
		$low = (self::CURVE[$lower] ?? self::TAIL_RATE);
		$high = (self::CURVE[$upper] ?? self::TAIL_RATE);

		return ($low + (($high - $low) * $fraction));
	}//end at()
}//end class
