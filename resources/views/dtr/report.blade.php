<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>DTR Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
            line-height: 1.4;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            color: #333;
        }
        .employee-info {
            margin-bottom: 20px;
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
        }
        .employee-info p {
            margin: 5px 0;
        }
        .employee-info strong {
            display: inline-block;
            width: 120px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .summary {
            margin-top: 30px;
            background: #e8f5e9;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #4CAF50;
        }
        .summary p {
            margin: 5px 0;
            font-size: 14px;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daily Time Record (DTR) Report</h1>
    </div>

    <div class="employee-info">
        <p><strong>Employee ID:</strong> {{ $employee['biometric_id'] }}</p>
        <p><strong>Name:</strong> {{ $employee['name'] }}</p>
        <p><strong>Department:</strong> {{ $employee['department'] }}</p>
        <p><strong>Period:</strong> {{ $date_from }} to {{ $date_to }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Day</th>
                <th>First In</th>
                <th>First Out</th>
                <th>Second In</th>
                <th>Second Out</th>
            </tr>
        </thead>
        <tbody>
            @forelse($daily_records as $record)
                <tr>
                    <td>{{ $record['dtr_date'] }}</td>
                    <td>{{ $record['day_short'] }}</td>
                    <td>{{ $record['first_in'] ?? '' }}</td>
                    <td>{{ $record['first_out'] ?? '' }}</td>
                    <td>{{ $record['second_in'] ?? '' }}</td>
                    <td>{{ $record['second_out'] ?? '' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center;">No records found for this period</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="summary">
        <p><strong>Total Days:</strong> {{ $summary['total_days'] }}</p>
    </div>

    <div class="footer">
        <p>Generated on {{ date('Y-m-d H:i:s') }} | DTR Service</p>
    </div>
</body>
</html>
