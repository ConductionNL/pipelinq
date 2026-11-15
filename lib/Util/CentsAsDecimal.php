<?php

/**
 * Pipelinq CentsAsDecimal.
 *
 * An amount in cents as a decimal string.
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
 * Formats integer cents as a signed decimal with two fraction digits, e.g.
 * -1050 as -10.50.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait CentsAsDecimal {

	/**
	 * Render an integer-cent amount as a `nn.nn` decimal string.
	 *
	 * @param int $cents Amount in integer cents.
	 *
	 * @return string
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function centsToDecimal(int $cents): string {
		$sign = '';
		$absolute = $cents;
		if ($cents < 0) {
			$sign = '-';
			$absolute = -$cents;
		}

		$major = intdiv($absolute, 100);
		$minor = ($absolute % 100);
		return sprintf('%s%d.%02d', $sign, $major, $minor);
	}//end centsToDecimal()
}//end trait
