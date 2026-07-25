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
    'table_options' => 'v6 set charset/format/delimiter/location as dynamic properties, '
        . 'deprecated in PHP 8.2. Replaced by HiveTableOptions and explicit builder methods. '
        . 'Emitted SQL clauses are unchanged; only the API to set them differs.',
];
