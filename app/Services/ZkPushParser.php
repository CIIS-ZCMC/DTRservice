<?php

namespace App\Services;

class ZkPushParser
{
    /**
     * Parse tab-separated key-value records from ZKTeco push payload.
     * Example input:
     * PIN=101\tName=John Doe\tPri=0\tPasswd=\tCard=0\tGrp=1
     * FP PIN=493\tFID=3\tSize=612\tValid=1\tTMP=SIlTUzIx...
     * 
     * @param string $rawContent
     * @return array<int, array<string, string>>
     */
    public static function parseKeyValues(string $rawContent): array
    {
        $rows = preg_split('/\r\n|\r|\n/', trim($rawContent));
        $data = [];

        foreach ($rows as $row) {
            $row = trim($row);
            if (empty($row)) {
                continue;
            }

            $fields = explode("\t", $row);
            $parsedRow = [];

            foreach ($fields as $field) {
                if (str_contains($field, '=')) {
                    [$key, $value] = explode('=', $field, 2);
                    $key = trim($key);
                    $value = trim($value);

                    $parsedRow[$key] = $value;

                    // Normalize compound keys like "FP PIN", "USER PIN", "BIODATA PIN"
                    if (str_contains($key, ' ')) {
                        $subParts = explode(' ', $key, 2);
                        $type = strtoupper($subParts[0]);
                        $subKey = strtoupper($subParts[1]);

                        if ($subKey === 'PIN') {
                            $parsedRow['PIN'] = $value;
                            $parsedRow['type'] = $type;
                        }
                    }

                    // Normalize Finger ID aliases
                    $upperKey = strtoupper($key);
                    if (in_array($upperKey, ['FID', 'FINGERID', 'FINGER_ID'])) {
                        $parsedRow['FID'] = $value;
                        $parsedRow['Finger_ID'] = $value;
                        $parsedRow['FingerID'] = $value;
                    }

                    // Normalize Template aliases
                    if (in_array($upperKey, ['TMP', 'TEMPLATE'])) {
                        $parsedRow['TMP'] = $value;
                        $parsedRow['Template'] = $value;
                    }
                }
            }

            if (!empty($parsedRow)) {
                $data[] = $parsedRow;
            }
        }

        return $data;
    }

    /**
     * Check if a raw line represents a biometric template push.
     */
    public static function isBiometricTemplateLine(string $line): bool
    {
        $trimmed = trim($line);
        if (stripos($trimmed, 'FP PIN=') === 0 || stripos($trimmed, 'BIODATA PIN=') === 0 || stripos($trimmed, 'FACE PIN=') === 0) {
            return true;
        }

        if ((str_contains($trimmed, 'FID=') || str_contains($trimmed, 'FingerID=') || str_contains($trimmed, 'Finger_ID='))
            && (str_contains($trimmed, 'TMP=') || str_contains($trimmed, 'Template='))) {
            return true;
        }

        return false;
    }

    /**
     * Check if a raw line represents a user profile push.
     */
    public static function isUserPushLine(string $line): bool
    {
        $trimmed = trim($line);
        if (stripos($trimmed, 'USER PIN=') === 0 || stripos($trimmed, 'USER ') === 0) {
            return true;
        }

        if (str_contains($trimmed, 'PIN=') && str_contains($trimmed, 'Name=')) {
            return true;
        }

        return false;
    }

    /**
     * Parse URL-encoded/query-string-formatted command ACK lines.
     * Example input:
     * ID=101&Return=0&CMD=DATA USER
     * 
     * @param string $rawContent
     * @return array<int, array<string, string>>
     */
    public static function parseQueryStringLines(string $rawContent): array
    {
        $rows = preg_split('/\r\n|\r|\n/', trim($rawContent));
        $data = [];

        foreach ($rows as $row) {
            $row = trim($row);
            if (empty($row)) {
                continue;
            }

            parse_str($row, $result);

            if (!empty($result)) {
                $data[] = $result;
            }
        }

        return $data;
    }
}
