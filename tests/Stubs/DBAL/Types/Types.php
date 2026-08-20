<?php

/**
 * Test stub for Doctrine\DBAL\Types\Types.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Stub for Doctrine\DBAL\Types\Types.
 */
final class Types {
	public const BOOLEAN = 'boolean';
	public const DATETIME_MUTABLE = 'datetime';
	public const TIME_MUTABLE = 'time';
	public const DATE_MUTABLE = 'date';
	public const DATE_IMMUTABLE = 'date_immutable';
	public const DATETIMETZ_MUTABLE = 'datetimetz';
	public const DATETIME_IMMUTABLE = 'datetime_immutable';
	public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
	public const TIME_IMMUTABLE = 'time_immutable';
	public const INTEGER = 'integer';
	public const STRING = 'string';
	public const TEXT = 'text';
	public const FLOAT = 'float';
	public const BIGINT = 'bigint';
	public const SMALLINT = 'smallint';
	public const DECIMAL = 'decimal';
	public const JSON = 'json';
	public const BINARY = 'binary';
	public const BLOB = 'blob';
	public const ARRAY = 'array';
	public const GUID = 'guid';
	public const OBJECT = 'object';

	private function __construct() {
	}
}
