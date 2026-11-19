# PATH Role & Permission Model

PATH separates **permission** from **data scope**.

- Permission answers: *What module/action may this role use?*
- Data scope answers: *Which proposal records may this account see?*

System data scopes:
- Admin / Regular / TOD / Head / Cashier: Global
- PSTO: Assigned province only
- Evaluator: Assigned proposals only
- Custom roles: Global

The Admin role is protected and always has full permission. Role administration and permanent deletion are also Admin-only safeguards outside the configurable matrix.

## Permission keys

### Overview
- `dashboard.view`

### Proposals
- `proposals.view`
- `proposals.create`
- `proposals.edit`
- `proposals.archive`
- `proposals.restore`
- `proposals.document_checklist`
- `proposals.missing_evaluation`
- `proposals.assign_evaluators`
- `proposals.workflow`
- `proposals.comment`
- `proposals.download_files`
- `proposals.upload_files`

### Funded Proposals
- `funded.view`
- `funded.update_status`

### Analytics
- `analytics.view`

### Evaluator Portal
- `evaluator_portal.view`
- `evaluator_portal.update_status`
- `evaluator_portal.remarks`

### Required Documents
- `required_documents.view`
- `required_documents.create`
- `required_documents.edit`
- `required_documents.archive`
- `required_documents.restore`

### Evaluators
- `evaluators.view`
- `evaluators.create`
- `evaluators.edit`
- `evaluators.archive`
- `evaluators.restore`

### User Management
- `users.view`
- `users.create`
- `users.edit`
- `users.archive`
- `users.restore`

### Archives
- `archives.view`
