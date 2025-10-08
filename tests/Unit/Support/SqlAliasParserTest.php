<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\SqlAliasParser;

class SqlAliasParserTest extends TestCase
{
    public function testStripAliasRemovesTrailingAsAlias(): void
    {
        $this->assertSame('engines', SqlAliasParser::stripAlias('engines as e'));
        $this->assertSame('cars', SqlAliasParser::stripAlias('cars'));
    }

    public function testExtractAliasReturnsAliasOrNull(): void
    {
        $this->assertSame('e', SqlAliasParser::extractAlias('engines as e'));
        $this->assertNull(SqlAliasParser::extractAlias('drivers'));
    }

    public function testExtractAliasFromExpressionHandlesQuotedAndUnquoted(): void
    {
        $this->assertSame('m', SqlAliasParser::extractAliasFromExpression('(select * from materials) as m'));
        $this->assertSame('m', SqlAliasParser::extractAliasFromExpression('(select * from materials) AS m'));
        $this->assertSame('m', SqlAliasParser::extractAliasFromExpression('(select * from materials) as `m`'));
    }
}
