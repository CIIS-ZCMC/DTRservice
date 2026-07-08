<tr>
    <td style="width: 35px !important;font-size:10px;font-weight:bold">{{ $dailyLog['day'] }}</td>
    <td style="font-weight:bold;text-transform: capitalize; color:#010b0f; font-size:10px;width: 35px !important;">
        {{ $dailyLog['day_short'] }}
    </td>
    <td>{{ $dailyLog['first_in'] ?? '' }}</td>
    <td>{{ $dailyLog['first_out'] ?? '' }}</td>
    <td>{{ $dailyLog['second_in'] ?? '' }}</td>
    <td>{{ $dailyLog['second_outPrint'] ?? ($dailyLog['second_out'] ?? '') }}</td>
    <td></td>
    <td></td>
</tr>
