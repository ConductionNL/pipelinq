import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import ClientList from '../views/clients/ClientList.vue'
import ClientDetail from '../views/clients/ClientDetail.vue'
import RequestList from '../views/requests/RequestList.vue'
import RequestDetail from '../views/requests/RequestDetail.vue'
import ContactList from '../views/contacts/ContactList.vue'
import ContactDetail from '../views/contacts/ContactDetail.vue'
import LeadList from '../views/leads/LeadList.vue'
import LeadDetail from '../views/leads/LeadDetail.vue'
import ProductList from '../views/products/ProductList.vue'
import ProductDetail from '../views/products/ProductDetail.vue'
import PipelineBoard from '../views/pipeline/PipelineBoard.vue'
import MyWork from '../views/MyWork.vue'
import PipelineManager from '../views/settings/PipelineManager.vue'
import AutomationList from '../views/automations/AutomationList.vue'
import AutomationBuilder from '../views/automations/AutomationBuilder.vue'
import AutomationHistory from '../views/automations/AutomationHistory.vue'

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/pipelinq'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		{ path: '/clients', name: 'Clients', component: ClientList },
		{ path: '/clients/:id', name: 'ClientDetail', component: ClientDetail, props: route => ({ clientId: route.params.id }) },
		{ path: '/requests', name: 'Requests', component: RequestList },
		{ path: '/requests/:id', name: 'RequestDetail', component: RequestDetail, props: route => ({ requestId: route.params.id }) },
		{ path: '/contacts', name: 'Contacts', component: ContactList },
		{ path: '/contacts/:id', name: 'ContactDetail', component: ContactDetail, props: route => ({ contactId: route.params.id }) },
		{ path: '/leads', name: 'Leads', component: LeadList },
		{ path: '/leads/:id', name: 'LeadDetail', component: LeadDetail, props: route => ({ leadId: route.params.id }) },
		{ path: '/products', name: 'Products', component: ProductList },
		{ path: '/products/:id', name: 'ProductDetail', component: ProductDetail, props: route => ({ productId: route.params.id }) },
		{ path: '/pipeline', name: 'Pipeline', component: PipelineBoard },
		{ path: '/my-work', name: 'MyWork', component: MyWork },
		{ path: '/pipelines', name: 'Pipelines', component: PipelineManager },
		{ path: '/automations', name: 'Automations', component: AutomationList },
		{ path: '/automations/new', name: 'AutomationNew', component: AutomationBuilder },
		{ path: '/automations/:id', name: 'AutomationDetail', component: AutomationBuilder, props: route => ({ automationId: route.params.id }) },
		{ path: '/automations/:id/history', name: 'AutomationHistory', component: AutomationHistory, props: route => ({ automationId: route.params.id }) },
		{ path: '*', redirect: '/' },
	],
})
