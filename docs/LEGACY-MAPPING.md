# Legacy PATH → Laravel 13 Domain Mapping

| Legacy | Laravel 13 reconstruction | Notes |
|---|---|---|
| `documents` | `proposals` | Core project/proposal record; normalized field names. |
| `doc_status` | `document_status` | Complete / Incomplete. |
| `status` | `status` | Approved / Disapproved / Ongoing Evaluation / Funded. |
| `funded_status` | `funding_status` | Ongoing Processing / Cheque Released. |
| textual quarter values | tiny integer quarter fields | Q1–Q4 stored as 1–4. |
| `lacking_documents` | `missing_documents` | Proper FK to proposal + creator. |
| `comments.author` | `comments.user_id` | Author linked to authenticated user. |
| `replies.parent_id` | `replies.comment_id` | Explicit FK. |
| `history.user` | `activity_logs.user_id` | Immutable user relation + optional JSON metadata. |
| `users.account_type` | `users.role` | Same seven business roles. |
| `users.image` | `users.avatar_path` | Optional managed avatar path. |
| old `files.php` placeholder | `proposal_files` | Actual proposal file attachments. |

## Preserved role behavior

- **Admin:** all modules, user administration, delete authority.
- **TOD:** proposal management, funded-project access, lacking-document evaluation.
- **PSTO:** proposal management and viewing limited to the user's assigned province.
- **Cashier:** funded-project processing only; may update funding processing status.
- **Evaluator:** can record lacking-document evaluations and access analytics.
- **Head / Regular:** read-oriented proposal / analytics access.

## Important correction from legacy code

The old sidebar used conditions such as `Admin || account_type != Cashier`, which effectively granted the menu to every non-Cashier role. The new system uses explicit role middleware on server routes, not menu visibility as security.

## Recommended production migration

1. Back up the legacy database and files.
2. Import users without copying passwords blindly. Generate temporary passwords or a reset workflow.
3. Convert quarter strings (`Quarter One` etc.) to integers 1–4.
4. Import `documents` into `proposals` and resolve invalid zero dates to `NULL`.
5. Import lacking-document rows with mapped proposal IDs.
6. Map comment/reply author strings to user IDs where possible; otherwise use a dedicated legacy/system user.
7. Import history after proposal/user IDs are stable.
8. Reconcile counts and funded totals by sector/province/year against the legacy reports.
9. Perform role-by-role UAT before cutover.
