<template>
	<NcContent app-name="pipelinq">
		<MainMenu :current-route="currentRoute" @navigate="navigateTo" />
		<NcAppContent>
			<component :is="currentView" v-bind="currentProps" @navigate="navigateTo" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppContent } from '@nextcloud/vue'
import MainMenu from './navigation/MainMenu.vue'
import Dashboard from './views/Dashboard.vue'
import ClientList from './views/clients/ClientList.vue'
import ClientDetail from './views/clients/ClientDetail.vue'
import RequestList from './views/requests/RequestList.vue'
import RequestDetail from './views/requests/RequestDetail.vue'
import ContactList from './views/contacts/ContactList.vue'
import ContactDetail from './views/contacts/ContactDetail.vue'
import LeadList from './views/leads/LeadList.vue'
import LeadDetail from './views/leads/LeadDetail.vue'
import PipelineBoard from './views/pipeline/PipelineBoard.vue'
import MyWork from './views/MyWork.vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		MainMenu,
		Dashboard,
		ClientList,
		ClientDetail,
		RequestList,
		RequestDetail,
		ContactList,
		ContactDetail,
		LeadList,
		LeadDetail,
		PipelineBoard,
		MyWork,
	},
	data() {
		return {
			currentRoute: 'dashboard',
			currentId: null,
			storesReady: false,
		}
	},
	computed: {
		currentView() {
			switch (this.currentRoute) {
			case 'clients':
				return 'ClientList'
			case 'client-detail':
				return 'ClientDetail'
			case 'requests':
				return 'RequestList'
			case 'request-detail':
				return 'RequestDetail'
			case 'contacts':
				return 'ContactList'
			case 'contact-detail':
				return 'ContactDetail'
			case 'leads':
				return 'LeadList'
			case 'lead-detail':
				return 'LeadDetail'
			case 'pipeline':
				return 'PipelineBoard'
			case 'my-work':
				return 'MyWork'
			default:
				return 'Dashboard'
			}
		},
		currentProps() {
			if (this.currentRoute === 'client-detail' && this.currentId) {
				return { clientId: this.currentId }
			}
			if (this.currentRoute === 'request-detail' && this.currentId) {
				return { requestId: this.currentId }
			}
			if (this.currentRoute === 'contact-detail' && this.currentId) {
				return { contactId: this.currentId }
			}
			if (this.currentRoute === 'lead-detail' && this.currentId) {
				return { leadId: this.currentId }
			}
			return {}
		},
	},
	async created() {
		await initializeStores()
		this.storesReady = true
		this._handleHashRoute()
		window.addEventListener('hashchange', this._handleHashRoute)
	},
	beforeDestroy() {
		window.removeEventListener('hashchange', this._handleHashRoute)
	},
	methods: {
		navigateTo(route, id = null) {
			this.currentRoute = route
			this.currentId = id
			if (id) {
				window.location.hash = `#/${route}/${id}`
			} else {
				window.location.hash = `#/${route}`
			}
		},
		_handleHashRoute() {
			const hash = window.location.hash.replace('#/', '')
			const parts = hash.split('/')
			if (parts[0]) {
				this.currentRoute = parts[0]
				this.currentId = parts[1] || null
				if (parts[0] === 'clients' && parts[1]) {
					this.currentRoute = 'client-detail'
				}
				if (parts[0] === 'requests' && parts[1]) {
					this.currentRoute = 'request-detail'
				}
				if (parts[0] === 'contacts' && parts[1]) {
					this.currentRoute = 'contact-detail'
				}
				if (parts[0] === 'leads' && parts[1]) {
					this.currentRoute = 'lead-detail'
				}
			}
		},
	},
}
</script>
