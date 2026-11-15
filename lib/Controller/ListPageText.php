<?php

/**
 * Pipelinq ListPageText.
 *
 * The words of one subscriber-facing page {@see ListPublicController} renders.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

/**
 * A page's title, heading, sentence of explanation and pre-escaped action markup.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
 */
final class ListPageText {

	/**
	 * Constructor.
	 *
	 * @param string $title   The document title.
	 * @param string $heading The visible heading.
	 * @param string $body    One sentence of explanation.
	 * @param string $form    Pre-escaped markup for the action, or an empty
	 *                        string for a page with nothing to do.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $heading,
		public readonly string $body,
		public readonly string $form,
	) {
	}//end __construct()
}//end class
