# PATH v3 — Equipment Acquisition & Liquidation

The current PATH liquidation workflow follows the RPMO Equipment Database and is intentionally simple.

## Proposal-level flow

For each funded proposal:

1. Encode the approved equipment / technology and approved amount.
2. Encode the actual equipment acquired.
3. Record specifications as liquidated, realigned amount when applicable, purchased amount, supplier, property number, Date Acquired, Date Liquidated, and remarks.
4. PATH calculates project-level purchased and liquidated totals.

An acquisition's **Purchased Amount** counts as liquidated only when **Date Liquidated** is populated. Date Acquired and Date Liquidated are separate because equipment may be acquired before its liquidation is processed.

## Monitoring integration

Liquidation Monitoring uses the following precedence per proposal:

1. If equipment/acquisition records exist, Amount Liquidated is the sum of `actual_cost` for acquisitions with a non-null `date_liquidated`.
2. Otherwise, if legacy detailed liquidation ledger records exist, posted ledger disbursements remain the fallback.
3. Otherwise, the historical manual `liquidation_records.amount_liquidated` value is used.

The same precedence is used by the monitoring table, yearly summaries, overview totals, and monitoring exports.

## Legacy compatibility

The earlier ledger/document/finding tables and routes are retained so existing data is not destroyed. They are not required for the normal RPMO equipment-liquidation workflow.

## Permissions

- `liquidation.view`
- `liquidation.manage`
- `liquidation.review`
- `monitoring.liquidation.view`
- `monitoring.liquidation.manage`

Permissions remain configurable through Roles & Permissions.
