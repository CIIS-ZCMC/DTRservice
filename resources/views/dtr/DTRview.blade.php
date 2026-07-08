<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        if (isset($user) && $user->personalInformation) {
            $name = $user->personalInformation->employeeName();
        } elseif (isset($user)) {
            $name = $user->getFullNameAttribute();
        } elseif (isset($employee) && is_array($employee)) {
            $name = strtoupper($employee['name'] ?? 'Unknown');
        } else {
            $name = 'Unknown';
        }
    @endphp
    <title> {{ $name }} - DTR {{ $displayMonth ?? strtoupper(date('F', strtotime($date_from ?? 'now'))) }}-{{ $year ?? date('Y', strtotime($date_from ?? 'now')) }}</title>
    <style>
        @page {
            margin: 0;
            padding: 0;
        }

        body {
            margin: 0;
            padding: 0;
        }

        #tbleformat {
            width: 100%;
            border-collapse: collapse;
        }

        #tbleformat tr td {
            padding: 0;
            margin: 0;
            vertical-align: top;
        }

        .dtr-copy {
            width: 49.5%;
            box-sizing: border-box;
        }

        .dtr-copy-left {
            padding-left: 4px !important;
           
        }

        .dtr-copy-right {
            padding-left: 2px;
        }
    </style>

    <input type="hidden" id="w-print-flag" value="{{ $w_print ?? 1 }}">
    <script>
        var wPrint = parseInt(document.getElementById('w-print-flag').value, 10) || 0;
        if (wPrint) {
            window.print();
            window.onafterprint = function() {
                window.close();
            };
            setTimeout(function() {
                window.close();
            }, 10000);
        }
    </script>

</head>

<body>

    <table id="tbleformat">
        <tr>
            <td class="dtr-copy dtr-copy-left">
                @include('dtr.DTR')
            </td>
            <td style="width: 1px; border-left: 1px dashed #999; padding: 0 10px 0 0 ;margin-right:10px !important"></td>
            <td class="dtr-copy dtr-copy-right">
                @include('dtr.DTR')
            </td>
        </tr>
    </table>

</body>

</html>
