<?php

/**
 * Pipelinq MessageTariff.
 *
 * What a message is priced on: its template category and destination country.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The template category and the ISO 3166-1 alpha-2 destination country that
 * price a message; a Dutch utility message unless said otherwise.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class MessageTariff {
	/**
	 * Constructor.
	 *
	 * @param string $category Template category.
	 * @param string $country  ISO 3166-1 alpha-2.
	 */
	public function __construct(
		public readonly string $category='utility',
		public readonly string $country='NL',
	) {
	}//end __construct()
}//end class
