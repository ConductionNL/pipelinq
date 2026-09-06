<?php

/**
 * Test stub for OCA\OpenRegister\Service\Credential\CredentialRelinkRequiredException.
 *
 * IT EXTENDS THE ACCESS EXCEPTION, EXACTLY AS THE REAL ONE DOES, AND THAT IS
 * THE WHOLE POINT OF THIS FILE. `SocialBrokerGateway::classify()` has to ask
 * about this type FIRST, because asking about its parent first would answer
 * true for both and turn every dead grant into a permission refusal. A stub
 * that did not extend the parent would let a wrong catch order pass its own
 * test.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * The grant behind a credential is gone.
 */
class CredentialRelinkRequiredException extends CredentialAccessDeniedException {
}//end class
