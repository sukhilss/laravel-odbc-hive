<?php

declare(strict_types=1);

namespace Sukhil\Database\Hive\Tests\Unit\Schema;

use PHPUnit\Framework\TestCase;
use Sukhil\Database\Hive\Schema\Grammars\HiveSchemaGrammar;
use Sukhil\Database\Hive\Schema\HiveBlueprint;
use Sukhil\Database\Hive\Tests\Support\BlueprintFactory;

/**
 * Asserts the ported grammar reproduces the DDL that the pre-port grammar
 * emitted, except where a deviation is explicitly registered.
 */
final class GoldenParityTest extends TestCase
{
    /**
     * @return array<string, array<int, string>>
     */
    private function golden(): array
    {
        $path = __DIR__.'/../../fixtures/golden-v6-schema.json';

        $this->assertFileExists($path, 'Run tools/capture-golden.sh first.');

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded, 'Golden fixture is empty; capture failed.');

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function deviations(): array
    {
        return require __DIR__.'/../../fixtures/intentional-deviations.php';
    }

    /**
     * @return array<string, callable>
     */
    private function fixtures(): array
    {
        return [
            'numeric_types' => function (HiveBlueprint $table): void {
                $table->integer('integer_field');
                $table->bigInteger('big_integer');
                $table->smallInteger('small_integer');
                $table->tinyInteger('tinyinteger_field');
                $table->float('float_field');
                $table->double('double_field');
                $table->decimal('decimal_field');
            },
            'string_types' => function (HiveBlueprint $table): void {
                $table->string('string_field');
                $table->char('char_field');
                $table->text('text_field');
                $table->mediumText('medium_text_field');
                $table->longText('long_text_field');
            },
            'temporal_and_misc_types' => function (HiveBlueprint $table): void {
                $table->timestamp('timestamp_field');
                $table->date('date_field');
                $table->dateTime('datetime_field');
                $table->boolean('boolean_field');
                $table->binary('binary_field');
            },
            'modifiers_are_dropped' => function (HiveBlueprint $table): void {
                $table->string('nullable_field')->nullable();
                $table->integer('default_field')->default(7);
                $table->integer('unsigned_field')->unsigned();
            },
        ];
    }

    public function test_ported_grammar_matches_v6_output(): void
    {
        $golden = $this->golden();
        $deviations = $this->deviations();
        $compared = 0;

        foreach ($this->fixtures() as $name => $definition) {
            if (isset($deviations[$name])) {
                continue;
            }

            $this->assertArrayHasKey($name, $golden, "No golden entry for {$name}.");

            $connection = BlueprintFactory::connection();
            $grammar = new HiveSchemaGrammar($connection);
            $connection->setSchemaGrammar($grammar);

            $blueprint = BlueprintFactory::make('sample_table', $definition, $grammar);
            $blueprint->create();

            $actual = BlueprintFactory::toSql($blueprint, $connection, $grammar);

            $this->assertSame(
                $golden[$name],
                $actual,
                "Ported DDL for '{$name}' differs from v6. If deliberate, register it "
                .'in tests/fixtures/intentional-deviations.php with a reason.'
            );

            $compared++;
        }

        $this->assertGreaterThan(0, $compared, 'No fixtures were compared.');
    }

    public function test_every_registered_deviation_has_a_reason(): void
    {
        foreach ($this->deviations() as $name => $reason) {
            $this->assertNotSame('', trim($reason), "Deviation '{$name}' has no reason.");
        }
    }

    /**
     * Guards against a stale or mistyped deviation entry that names a
     * fixture the golden file no longer (or never did) contain: such an
     * entry would be dead weight at best, and at worst would mask the fact
     * that nothing is actually verifying it.
     */
    public function test_every_registered_deviation_names_a_real_golden_fixture(): void
    {
        $golden = $this->golden();

        foreach (array_keys($this->deviations()) as $name) {
            $this->assertArrayHasKey(
                $name,
                $golden,
                "Deviation '{$name}' does not match any entry in golden-v6-schema.json."
            );
        }
    }

    /**
     * Guards against a golden fixture silently falling out of coverage: one
     * that is neither exercised by a closure in fixtures() nor registered as
     * a deliberate deviation would simply never be compared again, without
     * the suite ever failing to say so.
     */
    public function test_every_golden_fixture_is_either_compared_or_deviated(): void
    {
        $golden = $this->golden();
        $deviations = $this->deviations();
        $fixtureNames = array_keys($this->fixtures());

        foreach (array_keys($golden) as $name) {
            $this->assertTrue(
                in_array($name, $fixtureNames, true) || isset($deviations[$name]),
                "Golden fixture '{$name}' is neither exercised in fixtures() nor registered in "
                .'intentional-deviations.php, so it is silently excluded from parity checking.'
            );
        }
    }
}
