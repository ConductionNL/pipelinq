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
import ComplaintList from '../views/complaints/ComplaintList.vue'
import ComplaintDetail from '../views/complaints/ComplaintDetail.vue'
import PipelineBoard from '../views/pipeline/PipelineBoard.vue'
import ContactmomentenList from '../views/contactmomenten/ContactmomentenList.vue'
import ContactmomentDetail from '../views/contactmomenten/ContactmomentDetail.vue'
import TaskList from '../views/tasks/TaskList.vue'
import TaskDetail from '../views/tasks/TaskDetail.vue'
import MyWork from '../views/MyWork.vue'
import QueueList from '../views/queues/QueueList.vue'
import QueueDetail from '../views/queues/QueueDetail.vue'
import KennisbankHome from '../views/kennisbank/KennisbankHome.vue'
import KennisbankDetail from '../views/kennisbank/KennisbankDetail.vue'
import KennisbankEditor from '../views/kennisbank/KennisbankEditor.vue'
import SurveyList from '../views/surveys/SurveyList.vue'
import SurveyDetail from '../views/surveys/SurveyDetail.vue'
import SurveyForm from '../views/surveys/SurveyForm.vue'
import SurveyAnalytics from '../views/surveys/SurveyAnalytics.vue'
import PublicSurveyForm from '../views/surveys/PublicSurveyForm.vue'
import PipelineManager from '../views/settings/PipelineManager.vue'
import FormManager from '../views/forms/FormManager.vue'
import FormBuilder from '../views/forms/FormBuilder.vue'
import FormSubmissions from '../views/forms/FormSubmissions.vue'
import AutomationList from '../views/automations/AutomationList.vue'
import AutomationBuilder from '../views/automations/AutomationBuilder.vue'
import AutomationHistory from '../views/automations/AutomationHistory.vue'
import ContactmomentList from '../views/contactmomenten/ContactmomentList.vue'
import ContactmomentForm from '../views/contactmomenten/ContactmomentForm.vue'
import ContactmomentDetail from '../views/contactmomenten/ContactmomentDetail.vue'
import TaskList from '../views/tasks/TaskList.vue'
import TaskDetail from '../views/tasks/TaskDetail.vue'
import TaskForm from '../views/tasks/TaskForm.vue'
import KennisbankHome from '../views/kennisbank/KennisbankHome.vue'
import ArticleDetail from '../views/kennisbank/ArticleDetail.vue'
import ArticleEditor from '../views/kennisbank/ArticleEditor.vue'
import CategoryManager from '../views/kennisbank/CategoryManager.vue'
import SyncSettings from '../views/sync/SyncSettings.vue'
import RapportageDashboard from '../views/rapportage/RapportageDashboard.vue'
import ChannelAnalytics from '../views/rapportage/ChannelAnalytics.vue'
import AgentPerformance from '../views/rapportage/AgentPerformance.vue'

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
		{ path: '/complaints', name: 'Complaints', component: ComplaintList },
		{ path: '/complaints/:id', name: 'ComplaintDetail', component: ComplaintDetail, props: route => ({ complaintId: route.params.id }) },
		{ path: '/contacts', name: 'Contacts', component: ContactList },
		{ path: '/contacts/:id', name: 'ContactDetail', component: ContactDetail, props: route => ({ contactId: route.params.id }) },
		{ path: '/leads', name: 'Leads', component: LeadList },
		{ path: '/leads/:id', name: 'LeadDetail', component: LeadDetail, props: route => ({ leadId: route.params.id }) },
		{ path: '/contactmomenten', name: 'Contactmomenten', component: ContactmomentenList },
		{ path: '/contactmomenten/:id', name: 'ContactmomentDetail', component: ContactmomentDetail, props: route => ({ contactmomentId: route.params.id }) },
		{ path: '/tasks', name: 'Tasks', component: TaskList },
		{ path: '/tasks/:id', name: 'TaskDetail', component: TaskDetail, props: route => ({ taskId: route.params.id }) },
		{ path: '/products', name: 'Products', component: ProductList },
		{ path: '/products/:id', name: 'ProductDetail', component: ProductDetail, props: route => ({ productId: route.params.id }) },
		{ path: '/pipeline', name: 'Pipeline', component: PipelineBoard },
		{ path: '/queues', name: 'Queues', component: QueueList },
		{ path: '/queues/:id', name: 'QueueDetail', component: QueueDetail, props: route => ({ queueId: route.params.id }) },
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
		{ path: '/contactmomenten', name: 'Contactmomenten', component: ContactmomentList },
		{ path: '/contactmomenten/new', name: 'ContactmomentNew', component: ContactmomentForm },
		{ path: '/contactmomenten/:id', name: 'ContactmomentDetail', component: ContactmomentDetail, props: route => ({ contactmomentId: route.params.id }) },
		{ path: '/tasks', name: 'Tasks', component: TaskList },
		{ path: '/tasks/new', name: 'TaskNew', component: TaskForm },
		{ path: '/tasks/:id', name: 'TaskDetail', component: TaskDetail, props: route => ({ taskId: route.params.id }) },
		{ path: '/kennisbank', name: 'Kennisbank', component: KennisbankHome },
		{ path: '/kennisbank/articles/new', name: 'KennisbankNew', component: ArticleEditor },
		{ path: '/kennisbank/articles/:id', name: 'KennisbankDetail', component: ArticleDetail, props: route => ({ articleId: route.params.id }) },
		{ path: '/kennisbank/articles/:id/edit', name: 'KennisbankEdit', component: ArticleEditor, props: route => ({ articleId: route.params.id }) },
		{ path: '/kennisbank/categories', name: 'KennisbankCategories', component: CategoryManager },
		{ path: '/sync-settings', name: 'SyncSettings', component: SyncSettings },
		{ path: '/rapportage', name: 'Rapportage', component: RapportageDashboard },
		{ path: '/rapportage/channels', name: 'ChannelAnalytics', component: ChannelAnalytics },
		{ path: '/rapportage/agents', name: 'AgentPerformance', component: AgentPerformance },
		{ path: '/pipelines', name: 'Pipelines', component: PipelineManager },
		{ path: '/forms', name: 'Forms', component: FormManager },
		{ path: '/forms/new', name: 'FormNew', component: FormBuilder },
		{ path: '/forms/:id', name: 'FormDetail', component: FormBuilder, props: route => ({ formId: route.params.id }) },
		{ path: '/forms/:id/submissions', name: 'FormSubmissions', component: FormSubmissions, props: route => ({ formId: route.params.id }) },
		{ path: '/automations', name: 'Automations', component: AutomationList },
		{ path: '/automations/new', name: 'AutomationNew', component: AutomationBuilder },
		{ path: '/automations/:id', name: 'AutomationDetail', component: AutomationBuilder, props: route => ({ automationId: route.params.id }) },
		{ path: '/automations/:id/history', name: 'AutomationHistory', component: AutomationHistory, props: route => ({ automationId: route.params.id }) },
		{ path: '*', redirect: '/' },
	],
})
