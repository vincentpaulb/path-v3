<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SETUP i-Fund Proposal Requirements Checklist - {{ $proposal->project_title }}</title>
    <style>
        *{box-sizing:border-box}
        html,body{margin:0;padding:0;background:#eef1f3;color:#000;font-family:Arial,Helvetica,sans-serif}
        .toolbar{position:sticky;top:0;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 18px;background:#fff;border-bottom:1px solid #d9e0e5;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .toolbar-title{font-size:13px;color:#334a5c}.toolbar-actions{display:flex;gap:8px}
        .tool-btn{appearance:none;border:1px solid #bdc9d1;background:#fff;color:#0b3854;padding:9px 14px;border-radius:7px;font:700 13px Arial;cursor:pointer;text-decoration:none}.tool-btn.primary{background:#0b3854;color:#fff;border-color:#0b3854}
        .sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:12mm 13mm 13mm;box-shadow:0 8px 30px rgba(0,0,0,.12)}
        .form-code{text-align:right;font-size:10.5pt;line-height:1.35;margin-bottom:4mm;min-height:14mm}
        .form-title{text-align:center;font-size:11pt;font-weight:700;margin:0 0 1.5mm;text-transform:uppercase}
        table{width:100%;border-collapse:collapse;table-layout:fixed}
        td,th{border:1px solid #000;padding:2.1mm 1.7mm;vertical-align:middle;font-size:9.2pt;line-height:1.2}
        .project-table{margin-bottom:0}.project-table td{padding-top:1.2mm;padding-bottom:1.2mm}.project-table .label{width:32%;font-weight:700}.project-table .project-title-row td{height:17mm;vertical-align:top}.project-table .value{font-weight:400}
        .requirements-table thead th{height:13mm;text-align:center;font-size:9pt}.requirements-table .req-col{width:53%;}.requirements-table .check-col{width:10%;}.requirements-table .remarks-col{width:17%;}.requirements-table tbody td{min-height:7mm}.requirements-table tbody td:first-child{text-align:left}.requirements-table .check{text-align:center;font-size:13pt;font-weight:700}.requirements-table .remarks{font-size:8.5pt}.requirements-table tr.long-row td{padding-top:2.4mm;padding-bottom:2.4mm}
        .empty{text-align:center;color:#555;font-style:italic;padding:8mm!important}
        @page{size:A4 portrait;margin:0}
        @media print{
            html,body{background:#fff}.toolbar{display:none!important}.sheet{width:210mm;min-height:297mm;margin:0;box-shadow:none;padding:12mm 13mm 13mm;page-break-after:auto}
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div class="toolbar-title"><b>SETUP i-Fund Proposal Requirements Checklist</b> · {{ $proposal->proposal_code ?: 'Proposal #'.$proposal->id }}</div>
        <div class="toolbar-actions">
            <button type="button" class="tool-btn" onclick="window.close()">Close</button>
            <button type="button" class="tool-btn primary" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>

    <main class="sheet">
        <div class="form-code">
            <div>TO-SET F14</div>
            <div>Revision 3</div>
            <div>04-21-2026</div>
        </div>

        <h1 class="form-title">CHECKLIST OF SETUP i-Fund PROPOSAL REQUIREMENTS</h1>

        <table class="project-table" aria-label="Proposal information">
            <tr class="project-title-row">
                <td class="label">PROJECT TITLE</td>
                <td class="value">{{ $proposal->project_title }}</td>
            </tr>
            <tr>
                <td class="label">PROPONENT</td>
                <td class="value">{{ $proposal->proponent }}</td>
            </tr>
            <tr>
                <td class="label">ADDRESS</td>
                <td class="value">{{ $proposal->address ?: '' }}</td>
            </tr>
            <tr>
                <td class="label">Amount Requested</td>
                <td class="value">₱{{ number_format((float) $proposal->amount, 2) }}</td>
            </tr>
        </table>

        <table class="requirements-table" aria-label="Required documents checklist">
            <thead>
                <tr>
                    <th class="req-col">REQUIREMENTS</th>
                    <th class="check-col">Complied</th>
                    <th class="check-col">Not<br>Complied</th>
                    <th class="remarks-col">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @forelse($checklistItems as $item)
                    @php
                        $isLong = strlen($item['document']->name) > 75;
                    @endphp
                    <tr class="{{ $isLong ? 'long-row' : '' }}">
                        <td>{{ $item['document']->name }}</td>
                        <td class="check">{{ $item['complied'] ? '✓' : '' }}</td>
                        <td class="check">{{ $item['not_complied'] ? '✓' : '' }}</td>
                        <td class="remarks">{{ $item['remarks'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">No required documents are configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </main>
</body>
</html>
