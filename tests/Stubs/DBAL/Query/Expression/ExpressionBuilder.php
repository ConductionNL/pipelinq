<?php

/**
 * Test stub for Doctrine\DBAL\Query\Expression\ExpressionBuilder.
 *
 * Provides the comparison constants OCP\DB\QueryBuilder\IExpressionBuilder
 * reads, so unit tests can mock that interface without doctrine/dbal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

/**
 * Stub for Doctrine\DBAL\Query\Expression\ExpressionBuilder.
 */
final class ExpressionBuilder {
	public const EQ = '=';
	public const NEQ = '<>';
	public const LT = '<';
	public const LTE = '<=';
	public const GT = '>';
	public const GTE = '>=';
}
