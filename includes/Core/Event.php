<?php

/**
 * includes/Core/Event.php
 * ==========================================================================
 * Lightweight synchronous event dispatcher (observer pattern).
 *
 * Decouples components: any part of an application can listen() for a named
 * event and any part can dispatch() it with a payload. Listeners run in
 * priority order (higher first); returning `false` from a listener halts
 * propagation — useful for veto-style hooks.
 *
 * Pure in-process and dependency-free. Bootstrap registers one shared instance
 * (e.g. it dispatches `app.terminating` at the end of the request).
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Event
{
    /**
     * @var array<string,array<int,array{callback:callable,priority:int,once:bool}>>
     */
    private array $listeners = [];

    /**
     * Register a listener for an event.
     *
     * @param callable(mixed,string):mixed $listener
     */
    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'callback' => $listener,
            'priority' => $priority,
            'once'     => false,
        ];
    }

    /**
     * Register a listener that fires at most once.
     *
     * @param callable(mixed,string):mixed $listener
     */
    public function once(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'callback' => $listener,
            'priority' => $priority,
            'once'     => true,
        ];
    }

    /**
     * Dispatch an event to its listeners (highest priority first).
     *
     * Returns each listener's return value in order. Propagation stops early
     * if a listener returns exactly `false`.
     *
     * @return array<int,mixed>
     */
    public function dispatch(string $event, mixed $payload = null): array
    {
        if (empty($this->listeners[$event])) {
            return [];
        }

        $listeners = $this->listeners[$event];
        // Stable sort by descending priority.
        usort($listeners, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);

        $results = [];
        $fired = [];
        foreach ($listeners as $index => $listener) {
            $result = ($listener['callback'])($payload, $event);
            $results[] = $result;
            if ($listener['once']) {
                $fired[] = $index;
            }
            if ($result === false) {
                break;
            }
        }

        if ($fired !== []) {
            $this->removeOnceListeners($event, $listeners, $fired);
        }

        return $results;
    }

    /**
     * Remove all listeners for an event.
     */
    public function forget(string $event): void
    {
        unset($this->listeners[$event]);
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * Rebuild an event's listener list without the one-time listeners that fired.
     *
     * @param array<int,array{callback:callable,priority:int,once:bool}> $sorted
     * @param array<int,int> $firedIndexes
     */
    private function removeOnceListeners(string $event, array $sorted, array $firedIndexes): void
    {
        $keep = [];
        foreach ($sorted as $index => $listener) {
            if ($listener['once'] && in_array($index, $firedIndexes, true)) {
                continue;
            }
            $keep[] = $listener;
        }
        $this->listeners[$event] = $keep;
    }
}
