<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>DTR Report - {{ $employee['name'] }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Serif:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;1,100;1,200;1,300;1,400;1,500;1,600;1,700&display=swap');

        body {
            display: flex;
            justify-content: center;
            font-family: "IBM Plex Serif", serif;
            user-select: none;
        }

        #po {
            width: 380px;
            padding: 5px;
        }

        #titleBar {
            text-align: center;
            font-size: 9px;
            font-weight: 350;
            margin-bottom: 5px;
        }

        #zcmc {
            font-size: 11px;
            font-weight: 450;
        }

        #addr {
            font-size: 8px;
            font-weight: 350;
        }

        #header {
            text-align: center;
            margin-top: -10px;
        }

        #header h6 {
            font-size: 11px;
            letter-spacing: 1px;
        }

        #userName {
            text-align: center;
            text-transform: uppercase;
            margin-top: -5px;
            font-size: 12px;
            font-weight: bold;
        }

        #userName div {
            height: 1.5px;
            width: 100%;
            background-color: gray;
        }

        #userName span {
            font-size: 10px;
            font-weight: 520;
            font-weight: bold;
        }

        .tit {
            font-weight: 500;
            font-size: 11px;
        }

        .ot {
            font-size: 10px;
            font-weight: bold;
        }

        #zcmclogo {
            width: 35px;
            float: left;
        }

        #dohlogo {
            width: 50px;
            float: right;
        }

        #tabledate {
            width: 98%;
            margin-left: 1%;
            border-collapse: collapse;
        }

        #tabledate tr {
            font-size: 9px !important;
        }

        #tabledate th {
            font-size: 9px;
            font-weight: 520;
            text-align: center;
            text-transform: uppercase;
        }

        #tabledate td {
            text-align: center;
            border: 1px solid rgb(153, 152, 152);
            font-size: 9px !important;
            width: 38px !important;
            height: 18px !important;
            text-transform: uppercase;
        }

        .certification {
            text-align: left;
            margin-top: -10px;
        }

        .certification p {
            font-size: 13px;
            line-height: 1;
            text-indent: 20px;
        }

        .signature {
            text-align: center;
            margin-top: 2px;
        }

        .signature .line {
            height: 2px;
            background-color: gray;
            width: 60%;
            margin-left: 20%;
        }

        .signature span {
            font-size: 11px;
        }

        .footer {
            margin-top: 20px;
        }

        .footer span {
            font-size: 10px;
        }

        #headertop {
            border-bottom: 1px solid rgb(197, 194, 194);
            border-top: 1px solid rgb(197, 194, 194);
            font-weight: bold;
        }

        #headertop th {
            font-size: 11px;
            font-weight: bold;
            color: #656f74;
        }
    </style>
</head>
<body>
    <div id="po">
        <div id="titleBar">
            <img id="zcmclogo" src="{{ public_path('storage/logo/zcmc.jpeg') }}" alt="zcmcLogo">
            <img id="dohlogo" src="{{ public_path('storage/logo/doh.jpeg') }}" alt="dohLogo">

            <span id="rotp">
                Republic of the Philippines
                <br>
                Department of Health
            </span>
            <br>
            <span id="zcmc">
                ZAMBOANGA CITY MEDICAL CENTER
            </span>
            <br>
            <span id="addr">
                DR. EVANGELISTA ST., STA. CATALINA, ZAMBOANGA CITY
            </span>
            <div id="header" style="font-size:13px;">
                <h5>DAILY TIME RECORD</h5>
            </div>
        </div>

        @php
            $monthName = strtoupper(date('F', strtotime($date_from)));
            $year = date('Y', strtotime($date_from));
        @endphp

        <div id="userName" style="text-align: center;">
            {{ strtoupper($employee['name']) }}
            <hr>
        </div>

        <table style="width:100% !important;">
            <tr>
                <td class="tit">
                    <span>
                        For the month of <span style="font-size:11px;font-weight: bold;text-transform: uppercase;margin-left: 10px">{{ $monthName }}-{{ $year }}</span>
                    </span>
                </td>
                <td class="ot">
                    <span style="font-size:10px">{{ $hours ?? '' }} Regular Days</span>
                </td>
            </tr>
            <tr>
                <td class="tit" colspan="2">
                    <span>
                        Arrival and Departure : <span style="font-size:10px;font-weight: bold">{{ $arrival_departure ?? '' }}</span>
                    </span>
                </td>
            </tr>
        </table>

        <table id="tabledate">
            <tr id="headertop">
                <th colspan="2" style="border: 1px solid gray">DAY</th>
                <th colspan="2" style="text-align: center;border: 1px solid gray">AM</th>
                <th colspan="2" style="text-align: center;border: 1px solid gray">PM</th>
                <th colspan="2" style="border: 1px solid gray;text-align: center">UNDERTIME</th>
            </tr>
            <tr style="padding: 5px">
                <th style="border: 1px solid gray">No</th>
                <th style="border: 1px solid gray">Day</th>
                <th style="border: 1px solid gray; width: 50px">Arrival</th>
                <th style="border: 1px solid gray; width: 50px">Departure</th>
                <th style="border: 1px solid gray; width: 50px">Arrival</th>
                <th style="border: 1px solid gray; width: 50px">Departure</th>
                <th style="border: 1px solid gray;width: 40px">Hours</th>
                <th style="border: 1px solid gray;width: 40px">Minutes</th>
            </tr>
            <tbody>
                @foreach ($daily_records as $record)
                    @php
                        $hasLeave = count($record['has_leave']) > 0;
                        $hasOb = count($record['has_ob']) > 0;
                        $hasOt = count($record['has_ot']) > 0;
                        $hasCto = count($record['has_cto']) > 0;
                        $hasApplication = $hasLeave || $hasOb || $hasOt || $hasCto;

                        $undertimeMinutes = $record['undertime'] ?? null;
                        $utHours = null;
                        $utMins = null;
                        if ($undertimeMinutes !== null && $undertimeMinutes > 0) {
                            $utHours = intdiv($undertimeMinutes, 60);
                            $utMins = $undertimeMinutes % 60;
                        }

                        $hasTimeEntries = $record['first_in'] || $record['second_in'] || $record['first_out'] || $record['second_out'];
                    @endphp
                    <tr>
                        <td style="width: 35px !important;font-size:10px;font-weight:bold">{{ $record['day'] }}</td>
                        <td style="font-weight:bold;text-transform: capitalize; color:#010b0f; font-size:10px;width: 35px !important;">
                            {{ $record['day_short'] }}
                        </td>
                        @if ($hasApplication)
                            @php
                                $application = '';
                                if ($hasLeave) {
                                    $application = 'Leave';
                                } elseif ($hasOb) {
                                    $application = 'Official Business ( OB )';
                                } elseif ($hasOt) {
                                    $application = 'Official Time ( OT )';
                                } elseif ($hasCto) {
                                    $application = 'Compensatory Time Off ( C T O )';
                                }
                            @endphp
                            <td colspan="6" style="text-align: center;"><span style="padding-right:60px !important">{{ $application }}</span></td>
                        @else
                            <td>{{ $record['first_in'] ?? '' }}</td>
                            <td>{{ $record['first_out'] ?? '' }}</td>
                            <td>{{ $record['second_in'] ?? '' }}</td>
                            <td>{{ $record['second_out'] ?? '' }}</td>
                            <td>{{ $hasTimeEntries ? ($utHours ?? '') : '' }}</td>
                            <td>{{ $hasTimeEntries ? ($utMins ?? '') : '' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="certification" style="padding: 2px">
            <p>I certify on my honor that the above is a true and correct report of the hours of work performed, recorded daily at the time of arrival and departure from the office.</p>
        </div>
        <br>
        <div class="signature">
            <div style="font-size: 12px;text-transform:uppercase;font-weight:bold;margin-top: 20px">
                {{ strtoupper($employee['name']) }}
            </div>
            <div class="line"></div>
            <span>Verified as to prescribed hours</span>
        </div>
        <br>
        <div class="signature">
            <div style="font-size: 12px;text-transform:uppercase;font-weight:bold;margin-top: 20px"></div>
            <div class="line"></div>
            <span>In Charge</span>
        </div>
        <div class="footer" style="padding: 2px">
            <span>Adopted from CSC FORM NO. 48</span>
        </div>
    </div>
</body>
</html>
