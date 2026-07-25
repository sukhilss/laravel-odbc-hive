<?php

declare(strict_types=1);

/**
 * Fixtures whose ported output legitimately differs from v6, with the reason.
 *
 * GoldenParityTest skips these and prints the reason. Every entry is a
 * deliberate, reviewed behavior change — not a tolerated regression.
 *
 * @return array<string, string>
 */
return [
    'table_options' => 'v6 set charset/format/delimiter/location as dynamic properties, deprecated in '
        . 'PHP 8.2. Replaced by HiveTableOptions and explicit builder methods (e.g. storedAs()). '
        . 'Beyond the API change, v7 fixes three malformed-SQL bugs the capture exposed in v6\'s output, '
        . 'rather than reproducing them: '
        . '(1) v6 emitted ")ROW FORMAT SERDE" with no space between the column list and the options; '
        . 'v7 emits ") ROW FORMAT SERDE". '
        . '(2) v6 emitted both "ROW FORMAT SERDE" and "ROW FORMAT DELIMITED" in the same statement when '
        . 'charset and delimiter were both set, but HiveQL permits exactly one row-format clause; v7 '
        . 'emits only one, with the SerDe form winning when a charset is set and any delimiter ignored '
        . 'in that case. '
        . '(3) v6 emitted "ROW FORMAT DELIMITED" after "STORED AS ORC", but HiveQL requires the clause '
        . 'order ROW FORMAT, then STORED AS, then LOCATION; v7 emits that order.',
];
