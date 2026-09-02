<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Guftagu — {{ ucfirst($type) }} report</title>
<style>
    /* dompdf reads a plain CSS subset; kept deliberately simple rather than borrowed from
       the admin panel's own stylesheet, which relies on features dompdf does not render. */
    @page { margin: 24px 28px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; }
    h1 { font-size: 15px; margin: 0 0 2px; }
    .meta { color: #666; font-size: 9px; margin-bottom: 14px; }
    .meta span { margin-right: 14px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ddd; padding: 4px 6px; text-align: left; }
    th { background: #f0f0f0; font-weight: bold; }
    tr:nth-child(even) td { background: #fafafa; }
    .footer { margin-top: 10px; font-size: 8px; color: #999; }
</style>
</head>
<body>
    <h1>Guftagu — {{ ucfirst($type) }} report</h1>
    <div class="meta">
        <span>Generated {{ $generatedAt }}</span>
        <span>{{ count($rows) }} rows</span>
        @if($filterSummary)
            <span>Filters: {{ $filterSummary }}</span>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                @foreach($columns as $column)
                    <th>{{ str_replace('_', ' ', $column) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($columns as $column)
                        <td>{{ $row[$column] ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Guftagu admin panel — confidential. This report may contain personal or financial
        data and is not for external distribution.
    </div>
</body>
</html>
