// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Thin client for the social publishing endpoints.
 *
 * Kept apart from the components the same way `articlesApi.js` is: the paths
 * live in one place and every view shares them.
 *
 * ONE OF THESE CALLS DOES NOT GO TO PIPELINQ. `startBrokerConnection()` posts
 * to OpenRegister's own connect endpoint, with the user's own session, and
 * that is deliberate: the authorization code and the token that follow it
 * belong to the broker and must never pass through Pipelinq. Pipelinq answers
 * WHAT to connect; the browser and the broker do the connecting.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Every connected account, plus each network's readiness.
 *
 * @return {Promise<{data: Array<object>, readiness: object}>} The envelope.
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-network-with-no-filing-says-so-instead-of-failing-quietly
 */
export async function fetchAccounts() {
	const { data } = await axios.get(
		generateUrl('/apps/pipelinq/api/social-accounts'),
	)
	return { data: data?.data || [], readiness: data?.readiness || {} }
}

/**
 * The parameters the broker's connect flow needs for one account.
 *
 * @param {string} accountId The account.
 * @return {Promise<object>} The connect parameters.
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
export async function fetchConnectParameters(accountId) {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/social-accounts/${accountId}/connect`),
	)
	return data?.connect || null
}

/**
 * Start the connection at OpenRegister and answer where to send the browser.
 *
 * @param {object} connect The parameters `fetchConnectParameters()` answered with.
 * @return {Promise<string>} The authorization URL to navigate to.
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
export async function startBrokerConnection(connect) {
	const { data } = await axios.post(
		generateUrl('/apps/openregister/api/credentials/oauth2/start'),
		connect,
	)
	return data?.authorizationUrl || ''
}

/**
 * Record the credential a completed connection produced.
 *
 * @param {string} accountId The account.
 * @param {string} credentialRef The credential id, or an empty string to let
 *   the server resolve the newest one this user just connected.
 * @return {Promise<object>} The updated account.
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
export async function attachCredential(accountId, credentialRef = '') {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/social-accounts/${accountId}/attach`),
		{ credentialRef },
	)
	return data?.account || null
}

/**
 * End a connection, keeping the publications that already went out.
 *
 * @param {string} accountId The account.
 * @return {Promise<object>} The updated account.
 * @spec openspec/changes/social-publishing/specs/social-accounts/spec.md#requirement-a-connected-account-stores-a-reference-never-a-token
 */
export async function revokeAccount(accountId) {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/social-accounts/${accountId}/revoke`),
	)
	return data?.account || null
}

/**
 * Every social post, optionally by status.
 *
 * @param {string} status A status to filter on, or an empty string.
 * @return {Promise<Array<object>>} The posts.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export async function fetchPosts(status = '') {
	const query = status ? `?status=${encodeURIComponent(status)}` : ''
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/social-posts${query}`),
	)
	return data?.data || []
}

/**
 * One post.
 *
 * @param {string} postId The post.
 * @return {Promise<object>} The post.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
export async function fetchPost(postId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/social-posts/${postId}`),
	)
	return data?.post || null
}

/**
 * Write a draft.
 *
 * @param {object} payload The post fields.
 * @return {Promise<object>} The created post.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
export async function createPost(payload) {
	const { data } = await axios.post(
		generateUrl('/apps/pipelinq/api/social-posts'),
		payload,
	)
	return data?.post || null
}

/**
 * Edit a draft.
 *
 * @param {string} postId The post.
 * @param {object} payload The post fields.
 * @return {Promise<object>} The updated post.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
export async function updatePost(postId, payload) {
	const { data } = await axios.patch(
		generateUrl(`/apps/pipelinq/api/social-posts/${postId}`),
		payload,
	)
	return data?.post || null
}

/**
 * Move a post: submit for approval, approve or reject.
 *
 * @param {string} postId The post.
 * @param {string} action One of `submit`, `approve`, `reject`.
 * @param {string} note The decider's words, when there are any.
 * @return {Promise<object>} The updated post.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
 */
export async function movePost(postId, action, note = '') {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/social-posts/${postId}/${action}`),
		{ note },
	)
	return data?.post || null
}

/**
 * What happened per account for one post.
 *
 * @param {string} postId The post.
 * @return {Promise<Array<object>>} The publications.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
export async function fetchPublications(postId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/social-posts/${postId}/publications`),
	)
	return data?.data || []
}

/**
 * Try one failed publication again.
 *
 * @param {string} publicationId The publication.
 * @return {Promise<object>} The updated publication.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
 */
export async function retryPublication(publicationId) {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/social-publications/${publicationId}/retry`),
	)
	return data?.publication || null
}

/**
 * The prepared text and composer link for a share a colleague has to post.
 *
 * @param {string} publicationId The publication.
 * @return {Promise<object>} The share bundle.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */
export async function fetchShare(publicationId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/social-publications/${publicationId}/share`),
	)
	return data?.share || null
}

/**
 * Record that the owner posted it themselves.
 *
 * @param {string} publicationId The publication.
 * @param {string} url Where it landed, when they know.
 * @return {Promise<object>} The updated publication.
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */
export async function confirmShare(publicationId, url = '') {
	const { data } = await axios.post(
		generateUrl(
			`/apps/pipelinq/api/social-publications/${publicationId}/confirm-share`,
		),
		{ url },
	)
	return data?.publication || null
}

/**
 * Publications ranked by engagement rate.
 *
 * @param {string} network A network to limit the ranking to, or an empty string.
 * @return {Promise<Array<object>>} The ranked rows.
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
 */
export async function fetchPerformance(network = '') {
	const query = network ? `?network=${encodeURIComponent(network)}` : ''
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/social-performance${query}`),
	)
	return data?.data || []
}
