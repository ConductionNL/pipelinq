<?php

/**
 * Pipelinq ConfigFragmentValue.
 *
 * One value of a register fragment together with the dot-path it sits at.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file-format-compliance
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A fragment value and its dot-path (the root is '').
 *
 * The path decides whether a list is unioned or replaced when the fragment
 * is merged onto the monolith configuration (ADR-037).
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file-format-compliance
 */
final class ConfigFragmentValue {
	/**
	 * Constructor.
	 *
	 * @param mixed  $value The fragment value at this path.
	 * @param string $path  The dot-path of the value; the root is ''.
	 */
	public function __construct(
		public readonly mixed $value,
		public readonly string $path,
	) {
	}//end __construct()

	/**
	 * The value under one key of this (array) value, at its child path.
	 *
	 * @param int|string $key The key of the child value.
	 *
	 * @return self The child value.
	 *
	 * @spec openspec/specs/openregister-integration/spec.md#requirement-register-configuration-file-format-compliance
	 */
	public function child(int|string $key): self {
		$childPath = $this->path . '.' . $key;
		if ($this->path === '') {
			$childPath = (string)$key;
		}

		return new self(value: $this->value[$key], path: $childPath);
	}//end child()
}//end class
