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
        padding: 1px;
        background: #fff;
    }

    #titleBar {
        text-align: center;
        font-size: 8px;
        font-weight: 350;
        margin-bottom: 1px;
        margin-top: 10px;
    }

    #zcmc {
        font-size: 10px;
        font-weight: 450;
    }

    #addr {
        font-size: 7px;
        font-weight: 350;
    }

    #header {
        text-align: center;
        margin-top: -6px;
    }

    #header h6 {
        font-size: 10px;
        letter-spacing: 1px;
    }

    #userName {
        text-align: center;
        text-transform: uppercase;
        margin-top: -2px;
        margin-bottom: 2px;
        font-size: 11px;
        font-weight: bold;
    }

    #userName div {
        height: 1.5px;
        width: 100%;
        background-color: gray;
    }

    #userName span {
        font-size: 10px;
        font-weight: bold;
    }

    .ftmo {
        display: flex;
        width: 100%;
        font-weight: normal;
        font-weight: bold
    }

    .ftmo>* {
        flex-grow: 1;
        /* Makes all items expand equally */
        flex-basis: 0;
        /* Distributes available space equally among items */
        max-width: 100%;
        /* Ensure that items don't exceed the container width */
    }

    .ftmo span {
        font-size: 10px;
        text-transform: uppercase;
    }

    #f1 {
        margin-top: 2px;
    }

    #f2 {
        text-align: center !important;
    }

    #f2 div {
        height: 1.5px;
        background-color: gray;
    }

    .tit {
        font-weight: 500;
        font-size: 10px;
    }

    .ot {
        font-size: 9px;
        font-weight: bold;
    }

    #zcmclogo {
        width: 38px;
        float: left;
        margin-left: 10px !important;
    }

    #dohlogo {
        width: 47px;
        float: right;
        margin-right: 10px !important;
    }

    /* Apply styling to the entire table */
    #tabledate {
        width: 100%;
        border-collapse: collapse;
    }

    /* Style table rows */
    #tabledate tr {
        font-size: 12px !important;
    }

    /* Style table headers (th) */
    #tabledate th {
        font-size: 10px;
        font-weight: 520;
        text-align: center;
        padding: 1px;
        text-transform: uppercase;
    }

    /* Style table data cells (td) */
    #tabledate td {
        text-align: center;
        border: 1px solid rgb(153, 152, 152);
        font-size: 11px !important;
        width: 40px !important;
        height: 18px !important;
        padding: 1px;
        line-height: 1.3;
        text-transform: uppercase;
    }

    /* Alternate row background color for better readability */
    #tabledate tbody tr:nth-child(even) {
        background-color: #f7f7f7;
    }


    .certification {
        text-align: left;
        margin-top: -3px;
    }

    .certification p {
        font-size: 14px;
        line-height: 1;
        text-indent: 20px;
        margin: 10px 0;
    }

    .signature {
        text-align: center;
        margin-top: 1px;
    }

    .signature .line {
        height: 1.5px;
        background-color: gray;
        width: 60%;
        margin-left: 20%;
    }

    .signature span {
        font-size: 9px;
    }

    .footer {
        margin-top: 5px;
    }

    .footer span {
        font-size: 9px;
    }

    #lfooter {
        font-size: 9px;
        width: 100% !important;
    }

    #f1 {
        float: left;
    }

    #f2 {

        text-align: right;
    }

    #f3 {
        text-align: right;
    }

    .fentry {
        color: black;
        font-weight: bold
    }

    #tblheader {
        border-collapse: collapse;
    }

    #tblheader tr td {
        padding: 0;
        border: 1px solid gray;
        text-transform: capitalize;
    }

    #headertop {
        border-bottom: 1px solid rgb(197, 194, 194);
        border-top: 1px solid rgb(197, 194, 194);
        font-weight: bold
    }

    #headertop th {
        font-size: 10px;
        font-weight: bold;
        color: #656f74
    }
</style>

<div id="po">

    @php
        // Support both legacy ($user, $dailyLogs) and new ($employee, $daily_records) data structures
        if (isset($user) && is_object($user) && isset($user->personalInformation)) {
            $name = strtoupper($user->personalInformation->employeeName());
        } elseif (isset($user) && is_object($user) && method_exists($user, 'getFullNameAttribute')) {
            $name = strtoupper($user->getFullNameAttribute());
        } elseif (isset($employee) && is_array($employee)) {
            $name = strtoupper($employee['name'] ?? 'Unknown');
        } else {
            $name = 'Unknown';
        }

        $displayMonth = $displayMonth ?? strtoupper(date('F', strtotime($date_from ?? 'now')));
        $year = $year ?? date('Y', strtotime($date_from ?? 'now'));
        $OHF = $OHF ?? ($hours ?? '');
        $Arrival_Departure = $Arrival_Departure ?? ($arrival_departure ?? '');
        $dailyLogs = $dailyLogs ?? ($daily_records ?? []);
        $isExternal = $is_external ?? false;
    @endphp

    <div id="titleBar">

        @if (($w_print ?? 0) > 1)
            <img id="zcmclogo" src="{{ asset('storage/logo/zcmc.jpeg') }}" alt="zcmcLogo">
            <img id="dohlogo" src="{{ asset('storage/logo/doh.jpeg') }}" alt="dohLogo">
        @else
            <img id="zcmclogo" src="{{ public_path('storage/logo/zcmc.jpeg') }}" alt="zcmcLogo">
            <img id="dohlogo" src="{{ public_path('storage/logo/doh.jpeg') }}" alt="dohLogo">
        @endif


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
            <h5> {{ $isExternal ? 'PARTNER - DAILY TIME RECORD' : 'DAILY TIME RECORD' }} </h5>
        </div>

    </div>

    <div id="userName" style="text-align: center; ">
        {{ $name }}
        <hr>
    </div>

    <table style="width:100% !important;">


        <tr>
            <td class="tit">
                <span>
                    For the month of <span
                        style="font-size:11px;font-weight: bold;text-transform: uppercase;margin-left: 10px">{{ $displayMonth }}-{{ $year }}</span>
                </span>
            </td>
            <td class="ot">
                <span style="font-size:10px">{{ $isExternal ? '' : $OHF . ' | ' }} Regular Days</span>
            </td>
        </tr>


        <tr>
            <td class="tit" colspan="2">
                <span>
                    Arrival and Departure : <span
                        style="font-size:10px;font-weight: bold">{{ $isExternal ? '' : $Arrival_Departure }}</span>
                </span>
            </td>

            {{-- {{ substr($Arrival_Departure, 0, 35) }} --}}

        </tr>

        {{-- @endif --}}

    </table>

    @php
        $expanded = false;

        if (isset($schedule)) {
            switch ($schedule) {
                case 'normal':
                    $expanded = false;
                    break;
                case 'shifting':
                    if ($isExternal) {
                        $expanded = true;
                    }
                    break;
                case 'custom':
                    break;
            }
        }
    @endphp




    <table id="tabledate">
        <tr id="headertop">
            <th colspan="2" style="border: 1px solid gray">
                DAY
            </th>
            <th colspan="2" style="text-align: center;border: 1px solid gray">
                @if ($expanded)
                    <span style="font-size:8px">Arrival/Departure</span>
                @else
                    AM
                @endif

            </th>
            <th colspan="2" style="text-align: center;border: 1px solid gray">
                @if ($expanded)
                    <span style="font-size:8px">Arrival/Departure</span>
                @else
                    PM
                @endif

            </th>
            <th colspan="2" style="border: 1px solid gray;text-align: center">
                {{ $isExternal ? '' : 'UNDERTIME' }}</th>
        </tr>

        <tr>
            <th style="border: 1px solid gray">
                No
            </th>
            <th style="border: 1px solid gray">
                Day
            </th>
            <th style="border: 1px solid gray; width: 48px">
                @if ($expanded)
                    <span style="font-size:9px">AM</span>
                @else
                    Arrival
                @endif
            </th>
            <th style="border: 1px solid gray; width: 48px">
                @if ($expanded)
                    <span style="font-size:9px">PM</span>
                @else
                    Departure
                @endif
            </th>
            <th style="border: 1px solid gray; width: 48px">
                @if ($expanded)
                    <span style="font-size:9px">AM</span>
                @else
                    Arrival
                @endif
            </th>
            <th style="border: 1px solid gray; width: 48px">
                @if ($expanded)
                    <span style="font-size:9px">PM</span>
                @else
                    Departure
                @endif
            </th>
            <th style="border: 1px solid gray;width: 36px">
                {{ $isExternal ? '' : 'Hours' }}
            </th>
            <th style="border: 1px solid gray;width: 36px">
                {{ $isExternal ? '' : 'Minutes' }}
            </th>
        </tr>

        <tbody>
            @foreach ($dailyLogs as $dailyLog)
                @if ($isExternal)
                    @include('dtr.ExternalDisplay')
                @else
                    @php
                        $hasLeave = count($dailyLog['has_leave'] ?? []) > 0;
                        $hasOb = count($dailyLog['has_ob'] ?? []) > 0;
                        $hasOt = count($dailyLog['has_ot'] ?? []) > 0;
                        $hasCto = count($dailyLog['has_cto'] ?? []) > 0;
                        $hasApplication = $hasLeave || $hasOb || $hasOt || $hasCto;

                        $secondOut = $dailyLog['second_outPrint'] ?? ($dailyLog['second_out'] ?? null);

                        $hasTimeEntries = $dailyLog['first_in'] || $dailyLog['second_in'] || $dailyLog['first_out'] || $secondOut;

                        $hasHoliday = count($dailyLog['has_holiday'] ?? []) > 0;
                        $statusLabel = $dailyLog['first_in'] ?? '';
                        $hasActualTime = $dailyLog['first_out'] || $dailyLog['second_in'] || $secondOut;
                        $isStatusRow = !$hasApplication && !$hasActualTime && in_array(strtoupper($statusLabel), ['ABSENT', 'DAY OFF']);
                        $isHolidayRow = !$hasApplication && $hasHoliday && !$hasActualTime;

                        $undertimeMinutes = $dailyLog['undertime'] ?? null;
                        if (!is_null($undertimeMinutes) && is_numeric($undertimeMinutes) && $undertimeMinutes > 0) {
                            $utHours = intdiv($undertimeMinutes, 60);
                            $utMinutes = $undertimeMinutes % 60;
                            $utHours = $utHours >= 1 ? $utHours : null;
                            $utMinutes = $utMinutes >= 1 ? $utMinutes : null;
                        } else {
                            $utHours = null;
                            $utMinutes = null;
                        }
                    @endphp
                    <tr>
                        <td style="width: 30px !important;font-size:12px;font-weight:bold">{{ $dailyLog['day'] }}</td>
                        <td style="font-weight:bold;text-transform: capitalize; color:#010b0f; font-size:12px;width: 30px !important;">
                            {{ $dailyLog['day_short'] }}
                        </td>
                        @if ($hasApplication)
                            @php
                                $application = '';
                                if ($hasLeave) {
                                    $application = 'Leave';
                                } elseif ($hasCto) {
                                    $application = 'Compensatory Time Off ( C T O )';
                                } elseif ($hasOb) {
                                    $application = 'Official Business ( OB )';
                                } elseif ($hasOt) {
                                    $application = 'Official Time ( OT )';
                                }
                            @endphp
                            <td colspan="6" style="text-align: center;"><span style="padding-right:60px !important">{{ $application }}</span></td>
                        @elseif ($isHolidayRow)
                            @php
                                $holidayDesc = $dailyLog['has_holiday'][0]['description'] ?? 'Holiday';
                            @endphp
                            <td colspan="6" style="text-align: center;"><span style="padding-right:60px !important">{{ $holidayDesc }}</span></td>
                        @else
                            <td>{{ $dailyLog['first_in'] ?? '' }}</td>
                            <td>{{ $dailyLog['first_out'] ?? '' }}</td>
                            <td>{{ $dailyLog['second_in'] ?? '' }}</td>
                            <td>{{ $secondOut ?? '' }}</td>
                            <td>{{ $hasTimeEntries ? ($utHours ?? '') : '' }}</td>
                            <td>{{ $hasTimeEntries ? ($utMinutes ?? '') : '' }}</td>
                        @endif
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
    <div class="certification" style="padding: 1px">
        <p> I certify on my honor that the above is a true and correct report of the hours of work performed, recorded
            daily at the time of arrival and departure from the office.</p>
    </div>
    <div class="signature" style="margin-top: 25px;">
        <div style="font-size: 11px;text-transform:uppercase;font-weight:bold;">
            {{ $isExternal ? '' : $name }}
        </div>
        <div class="line">
        </div>
        <span style="font-size: 10px;"> Verified as to prescribed hours</span>
    </div>
    <div class="signature" style="margin-top: 25px;">
        <div style="font-size: 11px;text-transform:uppercase;font-weight:bold;">
        </div>
        <div class="line"></div>
        <span style="font-size: 10px;"> In Charge</span>
    </div>
    <div class="footer" style="padding: 1px; margin-top: 5px;">
        <span>Adopted from CSC FORM NO. 48</span>
        <br>
        {{-- <table id="lfooter">
            <tr>
                <td id="f1">ZCMC-F-HRMO-01</td>
                <td id="f2">ReV.0</td>
                <td id="f3">Effectivity Date: June 2, 2014</td>
            </tr>
        </table>  --}}

    </div>

</div>

<script>
    document.addEventListener("keydown", function(event) {
        if (event.keyCode === 123) {
            event.preventDefault();
        }
    });

    document.addEventListener("contextmenu", function(e) {
        e.preventDefault();
    });


    document.addEventListener("keydown", function(e) {
        if (e.key === "F12" || (e.ctrlKey && e.shiftKey && (e.key === "I" || e.key === "J"))) {
            e.preventDefault();
        }
    });
</script>
