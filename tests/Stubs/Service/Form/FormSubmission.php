<?php

/**
 * Test stub for OpenRegister's FormSubmission.
 *
 * Mirrors the constructor and public properties of
 * OCA\OpenRegister\Service\Form\FormSubmission.
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
 * The writes, the answers and who submits.
 */
final class FormSubmission {

	/**
	 * The destination writes.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $writes;

	/**
	 * The answers.
	 *
	 * @var array<string, mixed>
	 */
	public array $payload;

	/**
	 * The submitting user.
	 *
	 * @var IUser|null
	 */
	public ?IUser $subject;

	/**
	 * The idempotency scope.
	 *
	 * @var string
	 */
	public string $scope;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $writes    The destination writes.
	 * @param array<string, mixed>             $payload   The answers.
	 * @param FormSubmitter                    $submitter Who submits.
	 */
	public function __construct(array $writes, array $payload, FormSubmitter $submitter=new FormSubmitter()) {
		$this->writes  = $writes;
		$this->payload = $payload;
		$this->subject = $submitter->subject;
		$this->scope   = $submitter->scope;
	}//end __construct()
}//end class
