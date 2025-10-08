<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Debug;

use DebugBar\DataCollector\TimeDataCollector;

/**
 * Lada Cache DebugBar collector.
 *
 * Collects timing measures for Lada Cache operations and exposes them to
 * the DebugBar timeline and badge widgets. This class extends DebugBar's
 * TimeDataCollector to integrate seamlessly with the existing DebugBar UI.
 *
 * Notes:
 * - Method signatures overriding the parent mirror the parent's declarations
 *   (no return types) for compatibility across DebugBar versions and Laravel 12.
 */
final class CacheCollector extends TimeDataCollector
{
    private ?float $startTime = null;

    public function startMeasuring(): void
    {
        $this->startTime = microtime(true);
    }

    public function endMeasuring(
        string $type,
        string|array $hash,
        array $tags,
        string $sql,
        array $parameters = []
    ): void {
        $endTime = microtime(true);

        $this->addMeasure(
            '['.ucfirst($type).'] '.$sql,
            $this->startTime ?? $endTime,
            $endTime,
            [
                'hash' => $hash,
                'tags' => $tags,
                'parameters' => $parameters,
            ]
        );
    }

    /** {@inheritDoc} */
    #[\Override]
    public function collect()
    {
        $data = parent::collect();
        $data['lada-measures'] = count($data['measures'] ?? []);

        return $data;
    }

    /** {@inheritDoc} */
    #[\Override]
    public function getName()
    {
        return 'lada';
    }

    /** {@inheritDoc} */
    #[\Override]
    public function getWidgets()
    {
        return [
            'Lada Cache' => [
                'icon' => 'database',
                'widget' => 'PhpDebugBar.Widgets.TimelineWidget',
                'map' => 'lada',
                'default' => '{}',
            ],
            'Lada Cache:badge' => [
                'map' => 'lada.lada-measures',
                'default' => 0,
            ],
        ];
    }
}
