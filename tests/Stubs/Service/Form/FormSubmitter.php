<?php

/**
 * Test stub for OpenRegister's FormSubmitter.
 *
 * Mirrors the constructor of OCA\OpenRegister\Service\Form\FormSubmitter so the
 * website form suite can build a submission without the openregister app.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCP\IUser;

/**
 * Who submits: the subject and the idempotency scope.
 */
final class FormSubmitter {

	/**
	 * Constructor.
	 *
	 * @param IUser|null  $subject The submitting user.
	 * @param string      $scope   The idempotency scope.
	 * @param string|null $draftId The draft being completed.
	 * @param object|null $owner   The draft owner.
	 */
	public function __construct(
		public ?IUser $subject = null,
		public string $scope = '',
		public ?string $draftId = null,
		public ?object $owner = null,
	) {
	}//end __construct()
}//end class
