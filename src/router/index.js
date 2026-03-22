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
import KennisbankHome from '../views/kennisbank/KennisbankHome.vue'
import KennisbankDetail from '../views/kennisbank/KennisbankDetail.vue'
import KennisbankEditor from '../views/kennisbank/KennisbankEditor.vue'
import SurveyList from '../views/surveys/SurveyList.vue'
import SurveyDetail from '../views/surveys/SurveyDetail.vue'
import SurveyForm from '../views/surveys/SurveyForm.vue'
import SurveyAnalytics from '../views/surveys/SurveyAnalytics.vue'
import PublicSurveyForm from '../views/surveys/PublicSurveyForm.vue'
import PipelineManager from '../views/settings/PipelineManager.vue'

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
		{ path: '/kennisbank', name: 'Kennisbank', component: KennisbankHome },
		{ path: '/kennisbank/new', name: 'KennisbankNew', component: KennisbankEditor, props: () => ({ articleId: 'new' }) },
		{ path: '/kennisbank/:id', name: 'KennisbankDetail', component: KennisbankDetail, props: route => ({ articleId: route.params.id }) },
		{ path: '/kennisbank/:id/edit', name: 'KennisbankEdit', component: KennisbankEditor, props: route => ({ articleId: route.params.id }) },
		{ path: '/surveys', name: 'Surveys', component: SurveyList },
		{ path: '/surveys/new', name: 'SurveyCreate', component: SurveyForm },
		{ path: '/surveys/:id', name: 'SurveyDetail', component: SurveyDetail, props: route => ({ surveyId: route.params.id }) },
		{ path: '/surveys/:id/edit', name: 'SurveyEdit', component: SurveyForm, props: route => ({ surveyId: route.params.id }) },
		{ path: '/surveys/:id/analytics', name: 'SurveyAnalytics', component: SurveyAnalytics, props: route => ({ surveyId: route.params.id }) },
		{ path: '/public/survey/:token', name: 'PublicSurvey', component: PublicSurveyForm, props: route => ({ token: route.params.token }) },
		{ path: '/my-work', name: 'MyWork', component: MyWork },
		{ path: '/pipelines', name: 'Pipelines', component: PipelineManager },
		{ path: '*', redirect: '/' },
	],
})
