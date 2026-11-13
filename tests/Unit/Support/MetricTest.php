<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\LuaScripts;
use Spiritix\LadaCache\Support\Metric;
use Spiritix\LadaCache\Support\StatementShape;

final class MetricTest extends TestCase
{
    public function testAFieldNamesItsMetricAndLabel(): void
    {
        $this->assertSame('hit:owners', Metric::Hit->field('owners'));
        $this->assertSame('miss:(none)', Metric::Miss->field(''));
        $this->assertSame([Metric::Bypass, 'scope'], Metric::ofField('bypass:scope'));
        $this->assertSame([Metric::UnparsedRead, 'select a:b from t'], Metric::ofField('unparsed:select a:b from t'));
        $this->assertNull(Metric::ofField('unknown:owners'));
    }

    public function testAStatementShapeLeavesOutWhatVariesBetweenExecutions(): void
    {
        $this->assertSame(
            'update owners set name = ? where id in (?) and note = ?',
            StatementShape::of("update owners\n   set name = 'it''s' where id in (1, 22, 333) and note = 'a\\'b'"),
        );
        $this->assertSame('select * from t where id in (?)', StatementShape::of('select * from t where id in (?, ?, ?)'));
        $this->assertSame(160, strlen(StatementShape::of('select '.str_repeat('column_name, ', 40).'x from t')));
    }

    public function testEveryCountingScriptHasTheBodyOfTheOneItReplaces(): void
    {
        foreach (LuaScripts::COUNTING as $plain => $counting) {
            $this->assertStringContainsString('local function countField', $counting);
            $this->assertStringNotContainsString('local function countField', $plain);
            $this->assertSame(LuaScripts::nameOf($plain), LuaScripts::nameOf($counting));
        }
    }
}
