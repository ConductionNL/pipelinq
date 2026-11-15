<?php

/**
 * Pipelinq ShortPlainText.
 *
 * Text without tags, cut to 500 characters.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * Strips tags and surrounding space and cuts the text to 497 characters plus
 * an ellipsis when it is longer than 500.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ShortPlainText {

	/**
	 * A plain-text summary, capped so a whole article body cannot become one.
	 *
	 * @param string $value The description or summary.
	 *
	 * @return string
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function shorten(string $value): string {
		$text = trim(strip_tags($value));
		if (mb_strlen($text, 'UTF-8') <= 500) {
			return $text;
		}

		return (mb_substr($text, 0, 497, 'UTF-8') . '...');
	}//end shorten()
}//end trait
