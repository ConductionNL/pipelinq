<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-delegations">
		<h1>{{ t('pipelinq', 'Shared access') }}</h1>

		<p v-if="error" role="alert" class="portal-error">
			{{ error }}
		</p>

		<table v-if="delegations.length" class="portal-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('pipelinq', 'Colleague') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Scopes') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Valid until') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Action') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="d in delegations" :key="d.id || d.granteeEmail">
					<td>{{ d.granteeEmail }}</td>
					<td>{{ (d.scopes || []).join(', ') }}</td>
					<td>{{ d.validUntil || t('pipelinq', 'No end date') }}</td>
					<td>
						<button class="portal-button-link" @click="revoke(d)">
							{{ t('pipelinq', 'Revoke') }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>

		<h2>{{ t('pipelinq', 'Grant access') }}</h2>
		<form @submit.prevent="grant">
			<div class="portal-field">
				<label for="portal-grantee">{{ t('pipelinq', 'Colleague email') }}</label>
				<!-- autocomplete="off": this collects a COLLEAGUE's address, not the
				     signed-in user's own, so offering their own email would be wrong.
				     WCAG 1.3.5 covers fields about the user; there is no token for
				     "another person's email". -->
				<input id="portal-grantee"
					v-model="form.granteeEmail"
					type="email"
					autocomplete="off"
					required>
			</div>
			<fieldset>
				<legend>{{ t('pipelinq', 'Scopes') }}</legend>
				<label><input v-model="form.scopes" type="checkbox" value="view-invoices"> {{ t('pipelinq', 'View invoices') }}</label>
				<label><input v-model="form.scopes" type="checkbox" value="view-contracts"> {{ t('pipelinq', 'View contracts') }}</label>
				<label><input v-model="form.scopes" type="checkbox" value="submit-requests"> {{ t('pipelinq', 'Submit requests') }}</label>
			</fieldset>
			<div class="portal-field">
				<label for="portal-validuntil">{{ t('pipelinq', 'Valid until') }}</label>
				<input id="portal-validuntil" v-model="form.validUntil" type="date">
			</div>
			<p v-if="message"
				role="status"
				aria-live="polite"
				class="portal-success">
				{{ message }}
			</p>
			<button type="submit" class="portal-button-primary">
				{{ t('pipelinq', 'Grant access') }}
			</button>
		</form>
	</div>
</template>

<script>
import { portalApi } from '../portalApi.js'

export default {
	name: 'PortalDelegations',
	data() {
		return {
			delegations: [],
			form: { granteeEmail: '', scopes: [], validUntil: '' },
			message: '',
			error: '',
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				const result = await portalApi.delegations()
				this.delegations = result.delegations || []
			} catch (e) {
				this.error = e.message
			}
		},
		async grant() {
			this.message = ''
			this.error = ''
			try {
				await portalApi.grantDelegation({
					granteeEmail: this.form.granteeEmail,
					scopes: this.form.scopes,
					validUntil: this.form.validUntil || null,
				})
				this.message = t('pipelinq', 'Access has been granted.')
				this.form = { granteeEmail: '', scopes: [], validUntil: '' }
				await this.load()
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not grant access.')
			}
		},
		async revoke(delegation) {
			try {
				const id = delegation.id || (delegation['@self'] && delegation['@self'].id)
				await portalApi.revokeDelegation(id)
				await this.load()
			} catch (e) {
				this.error = e.message
			}
		},
	},
}
</script>
