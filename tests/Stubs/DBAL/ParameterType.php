<?php

/**
 * Test stub for Doctrine\DBAL\ParameterType.
 *
 * Provides the constants used by OCP\DB\QueryBuilder\IQueryBuilder so that
 * unit tests can run without the doctrine/dbal package installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 */

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Stub for Doctrine\DBAL\ParameterType.
 */
final class ParameterType {
	public const NULL = 0;
	public const INTEGER = 1;
	public const STRING = 2;
	public const LARGE_OBJECT = 3;
	public const BOOLEAN = 5;
	public const BINARY = 16;
	public const ASCII = 17;

	private function __construct() {
	}
}
