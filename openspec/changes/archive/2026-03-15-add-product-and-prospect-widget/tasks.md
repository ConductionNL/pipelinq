# Tasks: add-product-and-prospect-widget

## 1. Register Schema Setup [V1]

- [x] 1.1 Add `product` schema to `pipelinq_register.json` with all properties (name, description, sku, unitPrice, cost, category, type, status, unit, taxRate, image)
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-entity`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register JSON WHEN imported THEN a `product` schema with type `schema:Product` MUST exist with all defined properties

- [x] 1.2 Add `productCategory` schema to `pipelinq_register.json` with properties (name, description, parent, order)
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-category-entity`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register JSON WHEN imported THEN a `productCategory` schema with type `schema:DefinedTermSet` MUST exist

- [x] 1.3 Add `leadProduct` schema to `pipelinq_register.json` with properties (lead, product, quantity, unitPrice, discount, total, notes)
  - **spec_ref**: `specs/lead-product-link/spec.md#requirement-leadproduct-entity`
  - **files**: `lib/Settings/pipelinq_register.json`
  - **acceptance_criteria**:
    - GIVEN the register JSON WHEN imported THEN a `leadProduct` schema with type `schema:Offer` MUST exist with uuid references for lead and product

- [x] 1.4 Update register schemas array to include the three new schemas and update repair step
  - **spec_ref**: `design.md#register-update`
  - **files**: `lib/Settings/pipelinq_register.json`, `lib/Repair/InitializeSettings.php`
  - **acceptance_criteria**:
    - GIVEN a fresh install WHEN the repair step runs THEN all three new schemas MUST be created in the register

## 2. Product Frontend [V1]

- [x] 2.1 Create `product.js` Pinia store for product CRUD via OpenRegister API
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-entity`
  - **files**: `src/store/modules/product.js`
  - **acceptance_criteria**:
    - GIVEN the store WHEN calling fetchProducts/fetchProduct/saveProduct/deleteProduct THEN OpenRegister API calls MUST be made correctly

- [x] 2.2 Create ProductList.vue view with search, sort, filter, and pagination
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-list-view`
  - **files**: `src/views/products/ProductList.vue`
  - **acceptance_criteria**:
    - GIVEN products exist WHEN navigating to /products THEN a table with Name, SKU, Type, Category, Unit Price, Status columns MUST be displayed
    - GIVEN the list WHEN searching by name THEN results MUST filter accordingly

- [x] 2.3 Create ProductDetail.vue view with edit form and linked leads section
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-detail-view`
  - **files**: `src/views/products/ProductDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a product WHEN opening its detail THEN all fields MUST be editable
    - GIVEN a product linked to leads THEN a "Linked Leads" section MUST show

- [x] 2.4 Create ProductCreateDialog.vue for quick product creation
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-entity`
  - **files**: `src/views/products/ProductCreateDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the dialog WHEN filling name, unitPrice, type and saving THEN a product MUST be created

- [x] 2.5 Add /products and /products/:id routes to Vue router
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-list-view`
  - **files**: `src/router/index.js`
  - **acceptance_criteria**:
    - GIVEN the router WHEN navigating to /products THEN ProductList loads; /products/:id THEN ProductDetail loads

- [x] 2.6 Add Products navigation item to the app sidebar
  - **files**: `src/App.vue` or equivalent navigation component
  - **acceptance_criteria**:
    - GIVEN the app WHEN viewing any page THEN a "Products" nav item MUST be visible in the sidebar

## 3. Product Category Admin [V1]

- [x] 3.1 Create ProductCategoryManager.vue for admin CRUD of categories
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-admin-settings`
  - **files**: `src/views/settings/ProductCategoryManager.vue`
  - **acceptance_criteria**:
    - GIVEN an admin WHEN navigating to settings THEN a "Product Categories" section MUST be available
    - GIVEN categories WHEN creating/editing/deleting THEN changes MUST persist

- [x] 3.2 Integrate ProductCategoryManager into existing admin Settings page
  - **spec_ref**: `specs/product-catalog/spec.md#requirement-product-admin-settings`
  - **files**: `src/views/settings/Settings.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings page WHEN viewing THEN Product Categories section MUST appear

## 4. Lead-Product Linking [V1]

- [x] 4.1 Create LeadProducts.vue component for line item management in lead detail
  - **spec_ref**: `specs/lead-product-link/spec.md#requirement-lead-product-list-display`
  - **files**: `src/components/LeadProducts.vue`
  - **acceptance_criteria**:
    - GIVEN a lead detail WHEN viewing THEN a "Products" section with line item table MUST appear
    - GIVEN the section WHEN clicking "Add Product" THEN a product search/select MUST appear

- [x] 4.2 Integrate LeadProducts component into LeadDetail.vue
  - **spec_ref**: `specs/lead-product-link/spec.md#requirement-lead-product-list-display`
  - **files**: `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the lead detail view WHEN rendering THEN LeadProducts component MUST be included

- [x] 4.3 Implement lead value auto-calculation from line items
  - **spec_ref**: `specs/lead-product-link/spec.md#requirement-lead-value-auto-calculation`
  - **files**: `src/components/LeadProducts.vue`, `src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with line items WHEN items change THEN lead value MUST auto-recalculate
    - GIVEN a manual override WHEN set THEN the override value MUST be preserved with a hint showing the calculated total

- [x] 4.4 Update pipeline board to display product-based lead values
  - **spec_ref**: `specs/lead-product-link/spec.md#requirement-pipeline-board-product-value`
  - **files**: `src/views/pipeline/PipelineBoard.vue`
  - **acceptance_criteria**:
    - GIVEN leads with line items on the board THEN card values MUST reflect line item totals
    - GIVEN stage columns THEN totals MUST sum all lead values

## 5. Prospect Discovery Backend [V1]

- [x] 5.1 Create IcpConfigService for reading/writing ICP settings via IAppConfig
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration`
  - **files**: `lib/Service/IcpConfigService.php`
  - **acceptance_criteria**:
    - GIVEN the service WHEN reading/writing ICP criteria THEN IAppConfig MUST be used with proper keys

- [x] 5.2 Create KvkApiClient for KVK Handelsregister Zoeken API integration
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-kvk-api-integration`
  - **files**: `lib/Service/KvkApiClient.php`
  - **acceptance_criteria**:
    - GIVEN a valid API key and search criteria WHEN calling the KVK Zoeken API THEN results MUST be returned with company details
    - GIVEN an API error WHEN the call fails THEN a graceful error MUST be returned

- [x] 5.3 Create OpenCorporatesApiClient for optional OpenCorporates integration
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-opencorporates-integration`
  - **files**: `lib/Service/OpenCorporatesApiClient.php`
  - **acceptance_criteria**:
    - GIVEN OpenCorporates is enabled WHEN searching THEN results MUST be returned
    - GIVEN OpenCorporates is disabled WHEN searching THEN no error MUST occur

- [x] 5.4 Create ProspectScoringService for ICP fit score calculation
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-prospect-fit-scoring`
  - **files**: `lib/Service/ProspectScoringService.php`
  - **acceptance_criteria**:
    - GIVEN a prospect matching all ICP criteria THEN score MUST be 100
    - GIVEN a partial match THEN score MUST reflect only matching criteria
    - GIVEN no match THEN score MUST be 10 (active registration only)

- [x] 5.5 Create ProspectDiscoveryService orchestrating search, scoring, caching, and client exclusion
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-existing-client-exclusion`
  - **files**: `lib/Service/ProspectDiscoveryService.php`
  - **acceptance_criteria**:
    - GIVEN ICP criteria WHEN searching THEN KVK + optional OC results MUST be merged, scored, and sorted
    - GIVEN existing clients WHEN results are returned THEN matching companies MUST be excluded
    - GIVEN a cache TTL of 1h WHEN cached results exist THEN API calls MUST be skipped

- [x] 5.6 Create ProspectController with GET /api/prospects and POST /api/prospects/create-lead
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-prospect-dashboard-widget`
  - **files**: `lib/Controller/ProspectController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN a GET /api/prospects WHEN ICP is configured THEN prospect results MUST be returned
    - GIVEN a POST /api/prospects/create-lead THEN a Client + Lead MUST be created

- [x] 5.7 Create ProspectSettingsController with GET/PUT /api/prospects/settings
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration`
  - **files**: `lib/Controller/ProspectSettingsController.php`, `appinfo/routes.php`
  - **acceptance_criteria**:
    - GIVEN an admin WHEN GET /api/prospects/settings THEN current ICP config MUST be returned
    - GIVEN an admin WHEN PUT /api/prospects/settings THEN ICP config MUST be saved

## 6. Prospect Discovery Frontend [V1]

- [x] 6.1 Create `prospect.js` Pinia store for prospect data fetching
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-prospect-dashboard-widget`
  - **files**: `src/store/modules/prospect.js`
  - **acceptance_criteria**:
    - GIVEN the store WHEN fetchProspects is called THEN GET /api/prospects MUST be called
    - GIVEN the store WHEN createLeadFromProspect is called THEN POST /api/prospects/create-lead MUST be called

- [x] 6.2 Create ProspectCard.vue component for rendering a single prospect result
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-prospect-dashboard-widget`
  - **files**: `src/components/ProspectCard.vue`
  - **acceptance_criteria**:
    - GIVEN a prospect object WHEN rendered THEN company name, fit score, SBI, employees, city, KVK number MUST be shown
    - GIVEN the card WHEN "Create Lead" is clicked THEN the create-lead action MUST be triggered

- [x] 6.3 Create ProspectWidget.vue dashboard widget
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-prospect-dashboard-widget`
  - **files**: `src/components/ProspectWidget.vue`
  - **acceptance_criteria**:
    - GIVEN ICP configured and prospects found WHEN viewing dashboard THEN top 10 prospects MUST be listed
    - GIVEN no ICP configured THEN setup prompt MUST be shown
    - GIVEN the widget WHEN refresh clicked THEN cache MUST be cleared and data re-fetched

- [x] 6.4 Create ProspectSettings.vue admin component for ICP configuration
  - **spec_ref**: `specs/prospect-discovery/spec.md#requirement-ideal-customer-profile-configuration`
  - **files**: `src/views/settings/ProspectSettings.vue`
  - **acceptance_criteria**:
    - GIVEN an admin WHEN viewing settings THEN ICP form with all criteria MUST be available
    - GIVEN the form WHEN saving THEN PUT /api/prospects/settings MUST be called

- [x] 6.5 Integrate ProspectSettings into admin Settings page and ProspectWidget into Dashboard
  - **spec_ref**: `specs/dashboard/spec.md#requirement-prospect-discovery-widget`
  - **files**: `src/views/settings/Settings.vue`, `src/views/Dashboard.vue`
  - **acceptance_criteria**:
    - GIVEN the admin settings THEN "Prospect Discovery" section MUST appear
    - GIVEN the dashboard THEN ProspectWidget MUST appear below existing charts

## 7. Dashboard Updates [V1]

- [x] 7.1 Create ProductRevenue.vue KPI card showing top products by pipeline value
  - **spec_ref**: `specs/dashboard/spec.md#requirement-product-revenue-kpi-card`
  - **files**: `src/components/ProductRevenue.vue`
  - **acceptance_criteria**:
    - GIVEN leads with line items THEN top 3 products by total pipeline value MUST be shown
    - GIVEN no line items THEN "No product data yet" MUST be displayed

- [x] 7.2 Add Products count KPI card to dashboard
  - **spec_ref**: `specs/dashboard/spec.md#requirement-kpi-cards`
  - **files**: `src/views/Dashboard.vue`
  - **acceptance_criteria**:
    - GIVEN active products THEN a "Products" KPI card MUST display the count

- [x] 7.3 Add "New Product" to dashboard quick actions
  - **files**: `src/views/Dashboard.vue`
  - **acceptance_criteria**:
    - GIVEN the dashboard THEN a "New Product" quick action button MUST be visible

## 8. Quality & Testing [V1]

- [x] 8.1 Run PHPCS and PHPMD on all new PHP files, fix any violations
  - **files**: All new PHP files in `lib/Controller/`, `lib/Service/`
  - **acceptance_criteria**:
    - GIVEN new PHP code WHEN running `composer phpcs` and `composer phpmd` THEN zero violations

- [x] 8.2 Run ESLint and Stylelint on all new Vue files, fix any violations
  - **files**: All new Vue files in `src/`
  - **acceptance_criteria**:
    - GIVEN new Vue code WHEN running `npm run lint` and `npm run stylelint` THEN zero violations

- [x] 8.3 Manual end-to-end testing: create product, add to lead, verify value calculation
  - **acceptance_criteria**:
    - GIVEN a product WHEN added to a lead THEN the lead value MUST update
    - GIVEN the pipeline board THEN lead values MUST reflect line item totals

## Verification

- [x] All tasks checked off
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements
- [x] PHPCS + PHPMD + ESLint + Stylelint pass
- [x] Register schema imports correctly on fresh install
