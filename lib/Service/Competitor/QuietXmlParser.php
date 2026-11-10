<?php

/**
 * Pipelinq QuietXmlParser.
 *
 * Parses a competitor's feed or sitemap without letting libxml report its errors.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use SimpleXMLElement;
use Throwable;

/**
 * A quiet XML parse: libxml's errors are collected and cleared, never
 * logged, and the caller's libxml error mode is restored.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
 */
final class QuietXmlParser {
	/**
	 * Parse the document as XML with libxml's own errors collected, never
	 * logged: a competitor's feed being slightly invalid is not our incident.
	 *
	 * @param string $document The document.
	 *
	 * @return SimpleXMLElement|null The root, or null.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen
	 */
	public function parse(string $document): ?SimpleXMLElement {
		if (trim($document) === '') {
			return null;
		}

		$previous = libxml_use_internal_errors(true);
		try {
			$xml = simplexml_load_string($document, SimpleXMLElement::class, (LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
		} catch (Throwable) {
			return null;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if ($xml === false) {
			return null;
		}

		return $xml;
	}//end parse()
}//end class
