<template>
	<div class="kcc-werkplek">
		<!-- Header -->
		<div class="kcc-werkplek__header">
			<h2>{{ t('pipelinq', 'KCC Werkplek') }}</h2>
			<div class="kcc-werkplek__actions">
				<NcButton type="primary" @click="startNewContact('telefoon')">
					{{ t('pipelinq', 'New phone contact') }}
				</NcButton>
				<NcButton @click="startNewContact('balie')">
					{{ t('pipelinq', 'Walk-in contact') }}
				</NcButton>
				<NcButton @click="searchOpen = true">
					{{ t('pipelinq', 'Search client') }}
				</NcButton>
			</div>

			<!-- Agent stats -->
			<div v-if="agentStats" class="kcc-werkplek__stats">
				<span>{{ t('pipelinq', 'Today: {count} contacts', { count: agentStats.todayCount }) }}</span>
			</div>
		</div>

		<div class="kcc-werkplek__panels">
			<!-- Left: Identification/Search Panel -->
			<div class="kcc-werkplek__panel kcc-werkplek__panel--left">
				<h3>{{ t('pipelinq', 'Client Identification') }}</h3>
				<div class="identification-form">
					<div class="search-field">
						<label>{{ t('pipelinq', 'Search by name, BSN, KVK, or phone') }}</label>
						<input
							ref="searchInput"
							v-model="searchQuery"
							type="text"
							:placeholder="t('pipelinq', 'Enter search term...')"
							@keydown.enter="performSearch">
						<NcButton @click="performSearch">
							{{ t('pipelinq', 'Search') }}
						</NcButton>
					</div>

					<NcLoadingIcon v-if="searching" />

					<div v-if="searchResults.length > 0" class="search-results">
						<div
							v-for="result in searchResults"
							:key="result.id"
							class="search-result"
							tabindex="0"
							@click="selectClient(result)"
							@keydown.enter="selectClient(result)">
							<div class="search-result__name">
								{{ result.name || '-' }}
							</div>
							<div class="search-result__meta">
								<span v-if="result.type" class="result-type">{{ result.type }}</span>
								<span v-if="result.email">{{ result.email }}</span>
								<span v-if="result.phone">{{ result.phone }}</span>
							</div>
						</div>
					</div>

					<div v-if="searchPerformed && searchResults.length === 0" class="search-empty">
						<p>{{ t('pipelinq', 'No matching clients found') }}</p>
						<NcButton @click="createNewClient">
							{{ t('pipelinq', 'Create new client') }}
						</NcButton>
					</div>
				</div>

				<!-- Recent contacts -->
				<div v-if="recentContacts.length > 0" class="recent-contacts">
					<h4>{{ t('pipelinq', 'Recent contacts') }}</h4>
					<div
						v-for="contact in recentContacts"
						:key="contact.id"
						class="recent-contact-item"
						@click="selectClient(contact)">
						<span class="recent-contact-name">{{ contact.name || '-' }}</span>
						<span class="recent-contact-time">{{ contact.lastContact || '' }}</span>
					</div>
				</div>
			</div>

			<!-- Center: Client Context / Klantbeeld -->
			<div class="kcc-werkplek__panel kcc-werkplek__panel--center">
				<div v-if="!selectedClient" class="panel-empty">
					<p>{{ t('pipelinq', 'Select or identify a client to view their profile') }}</p>
				</div>
				<div v-else class="client-context">
					<div class="client-context__header">
						<h3>{{ selectedClient.name }}</h3>
						<span class="client-type-badge">
							{{ selectedClient.type === 'organization' ? t('pipelinq', 'Organization') : t('pipelinq', 'Person') }}
						</span>
					</div>
					<div class="client-context__info">
						<div v-if="selectedClient.email" class="context-field">
							<label>{{ t('pipelinq', 'Email') }}</label>
							<span>{{ selectedClient.email }}</span>
						</div>
						<div v-if="selectedClient.phone" class="context-field">
							<label>{{ t('pipelinq', 'Phone') }}</label>
							<span>{{ selectedClient.phone }}</span>
						</div>
						<div v-if="selectedClient.address" class="context-field">
							<label>{{ t('pipelinq', 'Address') }}</label>
							<span>{{ selectedClient.address }}</span>
						</div>
					</div>

					<!-- Open cases/requests -->
					<div class="client-context__cases">
						<h4>{{ t('pipelinq', 'Open items') }}</h4>
						<div v-if="clientRequests.length === 0" class="section-empty">
							{{ t('pipelinq', 'No open items') }}
						</div>
						<div
							v-for="req in clientRequests"
							:key="req.id"
							class="case-item"
							@click="$router.push({ name: 'RequestDetail', params: { id: req.id } })">
							<span class="case-item__title">{{ req.title || '-' }}</span>
							<span class="case-item__status">{{ req.status || '-' }}</span>
						</div>
					</div>

					<NcButton @click="$router.push({ name: 'ClientDetail', params: { id: selectedClient.id } })">
						{{ t('pipelinq', 'View full client profile') }}
					</NcButton>
				</div>
			</div>

			<!-- Right: Contact Registration Panel -->
			<div class="kcc-werkplek__panel kcc-werkplek__panel--right">
				<div v-if="!activeContact" class="panel-empty">
					<p>{{ t('pipelinq', 'Start a new contact to begin registration') }}</p>
				</div>
				<div v-else class="contact-registration">
					<div class="contact-registration__header">
						<h3>{{ t('pipelinq', 'Contact Registration') }}</h3>
						<!-- Contact timer -->
						<div class="contact-timer" :class="timerClass">
							{{ timerDisplay }}
						</div>
					</div>

					<form class="contact-form" @submit.prevent="submitContact">
						<div class="form-field">
							<label>{{ t('pipelinq', 'Channel') }}</label>
							<select v-model="contactForm.channel" required>
								<option value="telefoon">
									{{ t('pipelinq', 'Phone') }}
								</option>
								<option value="email">
									{{ t('pipelinq', 'Email') }}
								</option>
								<option value="balie">
									{{ t('pipelinq', 'Walk-in') }}
								</option>
								<option value="chat">
									{{ t('pipelinq', 'Chat') }}
								</option>
							</select>
						</div>

						<div class="form-field">
							<label>{{ t('pipelinq', 'Subject') }}</label>
							<input
								v-model="contactForm.subject"
								type="text"
								required
								:placeholder="t('pipelinq', 'What is the contact about?')">
						</div>

						<div class="form-field">
							<label>{{ t('pipelinq', 'Notes') }}</label>
							<textarea
								v-model="contactForm.notes"
								rows="4"
								:placeholder="t('pipelinq', 'Contact details and notes...')" />
						</div>

						<div class="form-field">
							<label>{{ t('pipelinq', 'Outcome') }}</label>
							<select v-model="contactForm.outcome">
								<option value="afgehandeld">
									{{ t('pipelinq', 'Resolved') }}
								</option>
								<option value="doorverwezen">
									{{ t('pipelinq', 'Referred') }}
								</option>
								<option value="terugbelverzoek">
									{{ t('pipelinq', 'Callback requested') }}
								</option>
								<option value="niet_bereikbaar">
									{{ t('pipelinq', 'Not reachable') }}
								</option>
							</select>
						</div>

						<div class="form-actions">
							<NcButton type="primary" native-type="submit">
								{{ t('pipelinq', 'Complete contact') }}
							</NcButton>
							<NcButton @click="cancelContact">
								{{ t('pipelinq', 'Cancel') }}
							</NcButton>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'KccWerkplek',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			searchQuery: '',
			searchResults: [],
			searchPerformed: false,
			searching: false,
			selectedClient: null,
			clientRequests: [],
			recentContacts: [],
			activeContact: false,
			contactForm: {
				channel: 'telefoon',
				subject: '',
				notes: '',
				outcome: 'afgehandeld',
			},
			timerSeconds: 0,
			timerInterval: null,
			agentStats: { todayCount: 0 },
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},

		timerDisplay() {
			const min = Math.floor(this.timerSeconds / 60)
			const sec = this.timerSeconds % 60
			return `${String(min).padStart(2, '0')}:${String(sec).padStart(2, '0')}`
		},

		timerClass() {
			if (this.timerSeconds >= 300) return 'timer--danger'
			if (this.timerSeconds >= 240) return 'timer--warning'
			return ''
		},
	},
	mounted() {
		this.loadRecentContacts()
	},
	beforeDestroy() {
		this.stopTimer()
	},
	methods: {
		async performSearch() {
			if (!this.searchQuery.trim()) return
			this.searching = true
			this.searchPerformed = true

			try {
				const results = await this.objectStore.fetchCollection('client', {
					_search: this.searchQuery.trim(),
					_limit: 10,
				})
				this.searchResults = results || []
			} catch (err) {
				console.error('Client search failed:', err)
				this.searchResults = []
			} finally {
				this.searching = false
			}
		},

		async selectClient(client) {
			this.selectedClient = client
			this.searchResults = []
			this.searchQuery = ''
			this.searchPerformed = false

			// Load related requests
			try {
				const requests = await this.objectStore.fetchCollection('request', {
					client: client.id,
					_limit: 20,
				})
				this.clientRequests = (requests || []).filter(r => !['completed', 'rejected'].includes(r.status))
			} catch {
				this.clientRequests = []
			}
		},

		async loadRecentContacts() {
			try {
				const clients = await this.objectStore.fetchCollection('client', {
					_limit: 5,
					_order: { updated: 'desc' },
				})
				this.recentContacts = clients || []
			} catch {
				this.recentContacts = []
			}
		},

		startNewContact(channel) {
			this.activeContact = true
			this.contactForm = {
				channel,
				subject: '',
				notes: '',
				outcome: 'afgehandeld',
			}
			this.timerSeconds = 0
			this.startTimer()

			// Focus the search input for client identification
			this.$nextTick(() => {
				if (this.$refs.searchInput) {
					this.$refs.searchInput.focus()
				}
			})
		},

		startTimer() {
			this.stopTimer()
			this.timerInterval = setInterval(() => {
				this.timerSeconds++
			}, 1000)
		},

		stopTimer() {
			if (this.timerInterval) {
				clearInterval(this.timerInterval)
				this.timerInterval = null
			}
		},

		async submitContact() {
			this.stopTimer()

			try {
				// Create the contact moment as a request with channel metadata
				const requestData = {
					title: this.contactForm.subject,
					status: this.contactForm.outcome === 'afgehandeld' ? 'completed' : 'new',
					channel: this.contactForm.channel,
					description: this.contactForm.notes,
					client: this.selectedClient?.id || '',
					assignee: OC.currentUser,
				}

				await this.objectStore.saveObject('request', requestData)

				showSuccess(t('pipelinq', 'Contact registered successfully'))
				this.agentStats.todayCount++
				this.resetContact()
			} catch (err) {
				showError(t('pipelinq', 'Failed to register contact'))
				console.error('Contact registration failed:', err)
			}
		},

		cancelContact() {
			this.stopTimer()
			this.resetContact()
		},

		resetContact() {
			this.activeContact = false
			this.contactForm = {
				channel: 'telefoon',
				subject: '',
				notes: '',
				outcome: 'afgehandeld',
			}
			this.timerSeconds = 0
		},

		createNewClient() {
			this.$router.push({ name: 'ClientDetail', params: { id: 'new' } })
		},
	},
}
</script>

<style scoped>
.kcc-werkplek {
	padding: 20px;
	height: 100%;
	display: flex;
	flex-direction: column;
}

.kcc-werkplek__header {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 20px;
}

.kcc-werkplek__header h2 {
	margin: 0;
}

.kcc-werkplek__actions {
	display: flex;
	gap: 8px;
}

.kcc-werkplek__stats {
	margin-left: auto;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

/* Three-panel layout */
.kcc-werkplek__panels {
	display: grid;
	grid-template-columns: 300px 1fr 350px;
	gap: 16px;
	flex: 1;
	min-height: 0;
}

@media (max-width: 1280px) {
	.kcc-werkplek__panels {
		grid-template-columns: 1fr;
	}
}

.kcc-werkplek__panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	overflow-y: auto;
}

.panel-empty {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 200px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

/* Search */
.identification-form h3,
.kcc-werkplek__panel h3 {
	margin: 0 0 12px;
	font-size: 16px;
}

.search-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-bottom: 12px;
}

.search-field label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.search-field input {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.search-results {
	margin-top: 8px;
}

.search-result {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	cursor: pointer;
	transition: background 0.15s;
}

.search-result:hover,
.search-result:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.search-result__name {
	font-weight: 600;
}

.search-result__meta {
	display: flex;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.result-type {
	text-transform: capitalize;
}

.search-empty {
	text-align: center;
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

/* Recent contacts */
.recent-contacts {
	margin-top: 20px;
}

.recent-contacts h4 {
	font-size: 14px;
	margin: 0 0 8px;
}

.recent-contact-item {
	display: flex;
	justify-content: space-between;
	padding: 6px 0;
	cursor: pointer;
	font-size: 13px;
	border-bottom: 1px solid var(--color-border-dark, rgba(0,0,0,0.05));
}

.recent-contact-item:hover {
	color: var(--color-primary);
}

.recent-contact-time {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

/* Client context */
.client-context__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 16px;
}

.client-context__header h3 {
	margin: 0;
}

.client-type-badge {
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-background-dark);
}

.client-context__info {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
	margin-bottom: 16px;
}

.context-field label {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.context-field span {
	font-size: 14px;
}

.client-context__cases h4 {
	font-size: 14px;
	margin: 0 0 8px;
}

.section-empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	padding: 8px 0;
}

.case-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	margin-bottom: 4px;
	cursor: pointer;
}

.case-item:hover {
	background: var(--color-background-hover);
}

.case-item__status {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

/* Contact registration */
.contact-registration__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.contact-timer {
	font-size: 24px;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	padding: 4px 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.timer--warning {
	background: #fef3c7;
	color: #d97706;
}

.timer--danger {
	background: #fee2e2;
	color: var(--color-error);
}

/* Contact form */
.contact-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.form-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-field label {
	font-size: 13px;
	font-weight: 600;
}

.form-field input,
.form-field select,
.form-field textarea {
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.form-field textarea {
	resize: vertical;
	min-height: 80px;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>
