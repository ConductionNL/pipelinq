<?php

/**
 * Pipelinq ReadsTypedRequestParameters.
 *
 * Reads controller action parameters from the request with the type checks
 * Nextcloud's dispatcher applies to typed action arguments.
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
 * Reads controller action parameters from `$this->request`.
 *
 * A missing or null value takes the default (IRequest::getParam). A value of
 * another type refuses the whole request, as the dispatcher answers a typed
 * action argument it cannot pass with a bare 400.
 */
trait ReadsTypedRequestParameters {
	/**
	 * Read the parameters a spec names, in the spec's order.
	 *
	 * @param array<string, array{0: string, 1: mixed}> $spec Name => [type, default]; type is 'string', '?string' or 'array'.
	 *
	 * @return array<string, mixed>|null The values, or null when one has the wrong type.
	 *
	 * @spec exclude request-parameter plumbing shared by controller actions, no behavioural spec surface.
	 */
	private function typedRequestParameters(array $spec): ?array {
		$values = [];
		foreach ($spec as $name => [$type, $default]) {
			$value = $this->request->getParam($name, $default);
			if ($this->hasParameterType(value: $value, type: $type) === false) {
				return null;
			}

			$values[$name] = $value;
		}

		return $values;
	}//end typedRequestParameters()

	/**
	 * Whether a request value fits a declared parameter type.
	 *
	 * @param mixed  $value The request value.
	 * @param string $type  'string', '?string' or 'array'.
	 *
	 * @return bool
	 */
	private function hasParameterType(mixed $value, string $type): bool {
		if ($type === 'array') {
			return is_array($value);
		}

		if ($value === null) {
			return $type === '?string';
		}

		return is_string($value);
	}//end hasParameterType()
}//end trait
