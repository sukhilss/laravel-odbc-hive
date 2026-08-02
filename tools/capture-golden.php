<?php

/**
 * Captures DDL output from the pre-port grammar so the ported implementation
 * can be checked for regressions.
 *
 * Pinned to commit ea23f65. Run via:
 *   docker compose --profile capture run --rm legacy-capture \
 *     sh -c "sh tools/capture-golden.sh"
 */

require '/tmp/legacy-vendor/autoload.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Sukhil\Database\Hive\Schema\Grammars\HiveGrammar;

$connection = new SQLiteConnection(new PDO('sqlite::memory:'));
$grammar = new HiveGrammar;

$fixtures = [
    'numeric_types' => function (Blueprint $table): void {
        $table->integer('integer_field');
        $table->bigInteger('big_integer');
        $table->smallInteger('small_integer');
        $table->tinyInteger('tinyinteger_field');
        $table->float('float_field');
        $table->double('double_field');
        $table->decimal('decimal_field');
    },
    'string_types' => function (Blueprint $table): void {
        $table->string('string_field');
        $table->char('char_field');
        $table->text('text_field');
        $table->mediumText('medium_text_field');
        $table->longText('long_text_field');
    },
    'temporal_and_misc_types' => function (Blueprint $table): void {
        $table->timestamp('timestamp_field');
        $table->date('date_field');
        $table->dateTime('datetime_field');
        $table->boolean('boolean_field');
        $table->binary('binary_field');
    },
    'modifiers_are_dropped' => function (Blueprint $table): void {
        $table->string('nullable_field')->nullable();
        $table->integer('default_field')->default(7);
        $table->integer('unsigned_field')->unsigned();
    },
];

$golden = [];

foreach ($fixtures as $name => $definition) {
    $blueprint = new Blueprint('sample_table', $definition);
    $blueprint->create();
    $golden[$name] = $blueprint->toSql($connection, $grammar);
}

// Table options were dynamic properties in v6.
$optioned = new Blueprint('optioned_table', function (Blueprint $table): void {
    $table->string('name');
});
$optioned->create();
$optioned->charset = 'UTF-8';
$optioned->format = 'ORC';
$optioned->delimiter = ',';
$optioned->location = '/warehouse/optioned';
$golden['table_options'] = $optioned->toSql($connection, $grammar);

// Guard against a vacuous capture: an empty or malformed fixture file would
// let Task 12's parity test pass without verifying anything, which is worse
// than not having this harness at all.
$expectedFixtureCount = 5;

if (count($golden) !== $expectedFixtureCount) {
    fwrite(
        STDERR,
        "Capture failed: expected {$expectedFixtureCount} fixtures, got ".count($golden).".\n"
    );
    exit(1);
}

foreach ($golden as $name => $statements) {
    if (! is_array($statements) || count($statements) === 0) {
        fwrite(STDERR, "Capture failed: fixture '{$name}' produced no SQL statements.\n");
        exit(1);
    }

    foreach ($statements as $statement) {
        if (! is_string($statement) || trim($statement) === '') {
            fwrite(STDERR, "Capture failed: fixture '{$name}' contains an empty statement.\n");
            exit(1);
        }

        if (stripos($statement, 'create table') === false) {
            fwrite(
                STDERR,
                "Capture failed: fixture '{$name}' statement does not contain 'create table': {$statement}\n"
            );
            exit(1);
        }
    }
}

file_put_contents(
    '/app/tests/fixtures/golden-v6-schema.json',
    json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
);

echo 'Captured '.count($golden)." fixtures.\n";
