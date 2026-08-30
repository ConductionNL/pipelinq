# Reverse-spec — Product catalog UI

Retroactively specifies the observed behavior of 22 method(s) implementing product catalog screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/products/ProductCreateDialog.vue::objectStore`
- `src/views/products/ProductCreateDialog.vue::onSave`
- `src/views/products/ProductDetail.vue::confirmDelete`
- `src/views/products/ProductDetail.vue::fetchRelated`
- `src/views/products/ProductDetail.vue::formatCurrency`
- `src/views/products/ProductDetail.vue::loading`
- `src/views/products/ProductDetail.vue::mounted`
- `src/views/products/ProductDetail.vue::objectStore`
- `src/views/products/ProductDetail.vue::onFormCancel`
- `src/views/products/ProductDetail.vue::onFormSave`
- `src/views/products/ProductDetail.vue::openLead`
- `src/views/products/ProductDetail.vue::productData`
- `src/views/products/ProductDetail.vue::sidebarProps`
- `src/views/products/ProductForm.vue::categoryOptions`
- `src/views/products/ProductForm.vue::fetchCategories`
- `src/views/products/ProductForm.vue::handler`
- `src/views/products/ProductForm.vue::isValid`
- `src/views/products/ProductForm.vue::objectStore`
- `src/views/products/ProductForm.vue::onSave`
- `src/views/products/ProductForm.vue::populateForm`
- `src/views/products/ProductForm.vue::validateAll`
- `src/views/products/ProductForm.vue::validateField`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
