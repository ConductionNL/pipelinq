<?php

/**
 * Pipelinq Base64UrlDecode.
 *
 * Strict base64url decoding.
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
 * Decodes base64url text, answering '' for input that is not valid base64.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait Base64UrlDecode {

	/**
	 * Base64url-decode.
	 *
	 * @param string $data The encoded string.
	 *
	 * @return string The decoded data (empty on failure).
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}//end base64UrlDecode()
}//end trait
