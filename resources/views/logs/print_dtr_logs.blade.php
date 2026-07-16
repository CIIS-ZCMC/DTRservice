<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Logs - {{ $employeeName }} - {{ $date }}</title>
    <style>
        @page {
            margin: 30px 35px;
        }

        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 10px;
        }

        #po {
            width: 100%;
        }

        /* Header logos + org info as a table (Dompdf-friendly) */
        #headerTable {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        #headerTable td {
            vertical-align: middle;
        }

        .logo-cell {
            width: 50px;
            text-align: center;
        }

        #zcmclogo {
            width: 55px;
         
        }

        #dohlogo {
            width: 70px;
        }

        .org-cell {
            text-align: center;
        }

        #rotp {
            font-size: 10px;
            font-weight: 400;
        }

        #zcmc {
            font-size: 12px;
            font-weight: bold;
        }

        #addr {
            font-size: 9px;
            font-weight: 400;
        }

        .title-block {
            text-align: center;
            margin-top: 20px;
            margin-bottom: 15px;
        }

        .title-block .main-title {
            font-size: 14px;
            font-weight: bold;
        }

        .title-block .sub-title {
            font-size: 11px;
            color: #656f74;
        }

        .title-block .doc-date {
            font-size: 15px;
            color: #0B60B0;
            font-weight: bold;
        }

        #infoTable {
            width: 100%;
            text-align: center;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        #infoTable td {
            font-size: 12px;
            padding: 2px 0;
        }

        #tabledate {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        #tabledate th {
            background-color: #f2f2f2;
            font-size: 9px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            padding: 8px 4px;
            border: 1px solid #9e9999;
            color: #656f74;
        }

        #tabledate td {
            text-align: center;
            border: 1px solid #9e9999;
            font-size: 10px;
            padding: 8px;
            text-transform: uppercase;
        }

        .certification {
            text-align: left;
            margin-top: 30px;
        }

        .certification p {
            font-size: 10px;
            line-height: 1.3;
        }

        .signature {
            text-align: center;
            margin-top: 45px;
        }

        .signature .line {
            border-bottom: 1px solid #555;
            width: 60%;
            margin: 0 auto;
            height: 1px;
        }

        .signature span {
            font-size: 11px;
            font-weight: bold;
        }

        #footerTable {
            width: 100%;
            font-size: 9px;
            margin-top: 25px;
            border-collapse: collapse;
        }

        #footerTable .f1 {
            text-align: left;
        }

        #footerTable .f2 {
            text-align: right;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            font-size: 14px;
            color: #999;
        }
    </style>
</head>
<body>
    @php
        $zcmcLogo = public_path('storage/logo/zcmc.jpeg');
        $dohLogo = public_path('storage/logo/doh.jpeg');
    @endphp
    <div id="po">
        <table id="headerTable">
            <tr>
                <td class="logo-cell" >
                    @if (file_exists($zcmcLogo))
                        <img id="zcmclogo" src="{{ $zcmcLogo }}" alt="zcmcLogo">
                    @endif
                </td>
                <td class="org-cell">
                    <span id="rotp">Republic of the Philippines<br>Department of Health</span><br>
                    <span id="zcmc">ZAMBOANGA CITY MEDICAL CENTER</span><br>
                    <span id="addr">DR. EVANGELISTA ST., STA. CATALINA, ZAMBOANGA CITY</span>
                </td>
                <td class="logo-cell">
                    @if (file_exists($dohLogo))
                        <img id="dohlogo" src="{{ $dohLogo }}" alt="dohLogo">
                    @endif
                </td>
            </tr>
        </table>

        <div class="title-block">
            <span class="main-title">DTR Logs</span><br>
            <span class="sub-title">
                Device Daily Time Records<br>
                User Management Information System
            </span><br>
            <span class="doc-date">{{ date('F j, Y', strtotime($date)) }}</span>
        </div>

        <table id="infoTable">
            <tr>
                <td style="text-align:left;">{{ $employeeName }}</td>
                <td style="text-align:right;font-size:13px;">Print Date : {{ date('m-d-Y') }}</td>
            </tr>
            <tr>
                <td style="text-align:left;font-size:12px;">
                    @if ($designation)
                        {{ is_object($designation) ? ($designation->area_name ?? $designation->name ?? $designation->department ?? '') : $designation }}<br>
                    @endif
                    @if ($empId)
                        {{ $empId }}<br>
                    @endif
                    @if ($biometricId)
                        Biometric ID: {{ $biometricId }}
                    @endif
                </td>
                <td></td>
            </tr>
        </table>

        @if ($noData || $logs->isEmpty())
            <div class="no-data">
                <p>No device log entries found for {{ date('F j, Y', strtotime($date)) }}.</p>
            </div>
        @else
            <table id="tabledate">
                <thead>
                    <tr>
                        <th>LOG<br><span style="font-size:8px;">Time Registered</span></th>
                        <th style="width:140px;">PULLED<br><span style="font-size:8px;">Time Pulled by Device</span></th>
                        <th style="width:150px;">Device Name</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($logs as $item)
                        <tr>
                            <td style="font-weight:bold;">{{ $item->date_time ? date('h:i a', strtotime($item->date_time)) : '' }}</td>
                            <td style="font-weight:bold;">{{ $item->created_at ? date('Y-m-d h:i a', strtotime($item->created_at)) : '' }}</td>
                            <td>{{ $item->device_name ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="certification">
            <p>I CERTIFY that the above records are true and correct as taken from the</p>
            <p>biometric device logs of the Zamboanga City Medical Center.</p>
        </div>

        <div class="signature">
            <div class="line"></div>
            <span>{{ $employeeName }}</span>
        </div>

        <table id="footerTable">
            <tr>
                <td class="f1">Generated by UMIS - DTR Service</td>
                <td class="f2">{{ date('m/d/Y h:i A') }}</td>
            </tr>
        </table>
    </div>
</body>
</html>
