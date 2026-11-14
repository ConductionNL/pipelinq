<?php

/**
 * Pipelinq JourneyMailing.
 *
 * One journey mailing to one contact, in progress: the step and the contact,
 * and the template, transport, address and delivery resolved along the way.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * One journey mailing in progress; built and filled by JourneyStepRunner.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
final class JourneyMailing {
	/**
	 * The template to send.
	 *
	 * @var array<string, mixed>
	 */
	public array $template = [];

	/**
	 * The transport to send through.
	 *
	 * @var array<string, mixed>
	 */
	public array $transport = [];

	/**
	 * The contact's address.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * The queued delivery.
	 *
	 * @var array<string, mixed>
	 */
	public array $delivery = [];

	/**
	 * Constructor.
	 *
	 * @param JourneyStep $step      The journey's step.
	 * @param string      $contactId The contact.
	 */
	public function __construct(
		public readonly JourneyStep $step,
		public readonly string $contactId,
	) {
	}//end __construct()
}//end class
