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

use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\Schema\Blueprint;
use Sukhil\Database\Hive\Schema\Grammars\HiveGrammar;

$connection = new SQLiteConnection(new PDO('sqlite::memory:'));
$grammar = new HiveGrammar();

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

file_put_contents(
    '/app/tests/fixtures/golden-v6-schema.json',
    json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Captured " . count($golden) . " fixtures.\n";
