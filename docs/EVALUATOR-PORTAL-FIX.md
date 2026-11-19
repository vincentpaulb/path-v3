# Evaluator Portal completion and 500-error fix

## Root cause of the 500 error

`ProposalController::show()` loads the `evaluatorRemarks` relationship. The application already contained an `EvaluatorRemark` model and migration, but the active `path13` database shown in the supplied project dump did not contain the `evaluator_remarks` table. MySQL therefore raised SQLSTATE 42S02 / error 1146.

## Required database command

After replacing the project files, run:

```powershell
php artisan migrate
php artisan optimize:clear
```

Migration `2026_08_17_000009_create_evaluator_remarks_table.php` creates the normal table. Migration `2026_08_17_000010_repair_evaluator_remarks_table.php` is an additional recovery migration: if an installation somehow marked the earlier migration as completed while the table is still absent, the recovery migration safely creates it.

## Completed evaluator workflow

- Evaluator users are matched to Evaluator Directory records by email.
- Evaluator login lands on **My Assigned Proposals**.
- The evaluator portal lists only proposals assigned to that evaluator.
- Evaluator visibility is enforced at the query/backend level, including dashboard and analytics queries.
- Evaluators have read-only proposal record access.
- Evaluators cannot edit/delete the proposal, change MOA/check status, upload files, or create lacking-document records.
- Evaluators may add discussion comments/replies on assigned proposals only.
- Evaluators may set their own assignment status to **Started**, **Ongoing**, or **Approved**.
- A dedicated **Evaluator Remarks → Forward & Return trail** now appears under Document Review.
- Repeated actions display as `Forward #1`, `Return #1`, `Return #2`, etc., per evaluator/proposal.
- A Return action automatically keeps the evaluator's status at Ongoing.
- Evaluator status changes and Forward/Return remarks are written to the proposal activity trail.
- Admin Evaluator Management now shows whether each evaluator directory entry has a matching Evaluator user account.

## Account matching rule

For evaluator portal access, the following must both exist:

1. an entry in `evaluators`, and
2. an entry in `users` with role `Evaluator`.

The two records must use the same email address.
