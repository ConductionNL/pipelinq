<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-requests">
		<h1>{{ t('pipelinq', 'My requests') }}</h1>

		<p v-if="error" id="portal-requests-error" role="alert" class="portal-error">
			{{ error }}
		</p>

		<table v-if="rows.length" class="portal-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('pipelinq', 'Number') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Subject') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Status') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Date') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.id">
					<td>{{ row.number }}</td>
					<td>
						<button class="portal-button-link" @click="open(row.id)">
							{{ row.subject }}
						</button>
					</td>
					<td>{{ row.status }}</td>
					<td>{{ row.date }}</td>
				</tr>
			</tbody>
		</table>

		<section v-if="detail" class="portal-detail">
			<h2>{{ detail.subject }}</h2>
			<p>{{ detail.body }}</p>
			<ul>
				<li v-for="(note, i) in detail.notes" :key="i">
					<strong>{{ note.author }}</strong
					>: {{ note.message }}
				</li>
			</ul>
			<form v-if="detail.canReply" @submit.prevent="sendReply">
				<label for="portal-reply">{{ t('pipelinq', 'Your reply') }}</label>
				<textarea id="portal-reply" v-model="replyText" required />
				<button type="submit" class="portal-button-primary">
					{{ t('pipelinq', 'Send reply') }}
				</button>
			</form>
		</section>

		<h2>{{ t('pipelinq', 'Submit a new request') }}</h2>
		<form @submit.prevent="submit">
			<div class="portal-field">
				<label for="portal-subject">{{ t('pipelinq', 'Subject') }}</label>
				<input
					id="portal-subject"
					v-model="form.subject"
					type="text"
					required
					:aria-describedby="error ? 'portal-requests-error' : null"
					:aria-invalid="error ? 'true' : null" />
			</div>
			<div v-if="categories.length" class="portal-field">
				<label for="portal-category">{{ t('pipelinq', 'Category') }}</label>
				<select
					id="portal-category"
					v-model="form.categoryId"
					required
					data-testid="portal-request-category">
					<option value="" disabled>
						{{ t('pipelinq', 'Choose a category') }}
					</option>
					<option v-for="category in categories" :key="category.key" :value="category.key">
						{{ category.name }}
					</option>
				</select>
			</div>
			<div class="portal-field">
				<label for="portal-body">{{ t('pipelinq', 'Message') }}</label>
				<textarea
					id="portal-body"
					v-model="form.body"
					required
					:aria-describedby="error ? 'portal-requests-error' : null"
					:aria-invalid="error ? 'true' : null" />
			</div>
			<p
				v-if="submitMessage"
				role="status"
				aria-live="polite"
				class="portal-success">
				{{ submitMessage }}
			</p>
			<button
				type="submit"
				:disabled="submitting"
				class="portal-button-primary">
				{{ t('pipelinq', 'Submit request') }}
			</button>
		</form>
	</div>
</template>

<script>
import { portalApi } from '../portalApi.js'

export default {
	name: 'PortalRequests',
	data() {
		return {
			rows: [],
			detail: null,
			replyText: '',
			form: { subject: '', body: '', categoryId: '' },
			/** The categories a client may file under; none means the intake asks for none. */
			categories: [],
			submitMessage: '',
			error: '',
			submitting: false,
		}
	},

	/**
	 * Load the client's requests and the categories they may file under.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
	 */
	mounted() {
		this.load()
		this.loadCategories()
	},

	methods: {
		/**
		 * Read the categories a desk opened to clients; none when that fails.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
		 */
		async loadCategories() {
			try {
				const result = await portalApi.requestCategories()
				this.categories = Array.isArray(result?.categories) ? result.categories : []
			} catch {
				this.categories = []
			}
		},

		async load() {
			this.error = ''
			try {
				const result = await portalApi.requests()
				this.rows = result.items || []
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not load requests.')
			}
		},

		async open(id) {
			try {
				this.detail = await portalApi.request(id)
			} catch (e) {
				this.error = e.message
			}
		},

		async sendReply() {
			try {
				this.detail = await portalApi.replyRequest(
					this.detail.id,
					this.replyText,
				)
				this.replyText = ''
				await this.load()
			} catch (e) {
				this.error = e.message
			}
		},

		/**
		 * File the request with the chosen category, then read the list again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-files-a-request-in-the-portal-and-follows-it
		 */
		async submit() {
			this.error = ''
			this.submitMessage = ''
			this.submitting = true
			try {
				const result = await portalApi.submitRequest({
					subject: this.form.subject,
					body: this.form.body,
					categoryId: this.form.categoryId,
					attachments: [],
				})
				this.submitMessage = t(
					'pipelinq',
					'Your request has been submitted ({id}). Expected response: {eta}.',
					{
						id: result.requestId,
						eta: result.estimatedResponseTime,
					},
				)
				this.form.subject = ''
				this.form.body = ''
				this.form.categoryId = ''
				await this.load()
			} catch (e) {
				this.error =
					e.errorCode === 'rateLimited'
						? t(
								'pipelinq',
								'Please wait 60 minutes before submitting another request.',
							)
						: e.message || t('pipelinq', 'Could not submit the request.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>
