<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Debug\CacheCollector;

class CacheCollectorTest extends TestCase
{
    public function testStartAndEndMeasuringAddsMeasureAndCollectCountsIt(): void
    {
        $collector = new CacheCollector();

        $collector->startMeasuring();
        $collector->endMeasuring(
            'miss',
            'hash-abc',
            ['tag1', 'tag2'],
            'select * from cars where id = ?',
            [1]
        );

        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('measures', $data);
        $this->assertArrayHasKey('lada-measures', $data);
        $this->assertGreaterThanOrEqual(1, $data['lada-measures']);
        $this->assertNotEmpty($data['measures']);

        $first = $data['measures'][0] ?? null;
        $this->assertIsArray($first);
        $this->assertArrayHasKey('label', $first);
        $this->assertStringContainsString('[Miss]', $first['label']);
        $this->assertArrayHasKey('params', $first);
        $this->assertSame(['hash' => 'hash-abc', 'tags' => ['tag1', 'tag2'], 'parameters' => [1]], $first['params']);
    }

    public function testGetNameAndWidgets(): void
    {
        $collector = new CacheCollector();

        $this->assertSame('lada', $collector->getName());

        $widgets = $collector->getWidgets();
        $this->assertIsArray($widgets);
        $this->assertArrayHasKey('Lada Cache', $widgets);
        $this->assertArrayHasKey('Lada Cache:badge', $widgets);

        $timeline = $widgets['Lada Cache'];
        $this->assertSame('PhpDebugBar.Widgets.TimelineWidget', $timeline['widget']);
        $this->assertSame('lada', $timeline['map']);

        $badge = $widgets['Lada Cache:badge'];
        $this->assertSame('lada.lada-measures', $badge['map']);
    }
}