# Evaluator Data Scoping Update

## Implemented behavior

Evaluator users are isolated to proposals assigned to the evaluator-directory record whose email matches the logged-in user.

### Overview / Dashboard
- All proposal metrics use `Proposal::visibleTo($user)`.
- Evaluator metrics therefore include assigned proposals only.
- Sector and province summaries include assigned proposals only.
- Lacking-document queue includes assigned proposals only.
- Reporting-year options are derived from assigned proposals only.
- Dashboard audit/activity for Evaluators includes only actions performed by the logged-in evaluator user.
- Dashboard proposal links use the dedicated Evaluator Review route.

### Analytics
- All counts, funded counts, amounts, province summaries and sector summaries use `Proposal::visibleTo($user)`.
- Reporting-year options are also scoped through `visibleTo()`.
- Evaluators cannot infer unrelated proposal years through the filter.

### Evaluator Review audit trail
- `EvaluatorPortalController::show()` loads `activities` filtered by the logged-in evaluator `user_id`.
- The Evaluator Review page labels the section **My audit trail**.
- Admin/TOD/PSTO/other users' activity entries are not loaded into the evaluator view.
- Empty state clearly states that the evaluator has no own audit entries yet.

### Route isolation
- If an Evaluator manually visits the generic `proposals.show` route, `ProposalController::show()` redirects to `evaluator.proposals.show` after assignment authorization.
- This prevents the generic proposal view loader from exposing the full activity relationship to Evaluator users.

### Login/navigation
- Evaluator login now lands on the scoped Overview dashboard.
- The PATH brand link goes to Overview for all roles.
