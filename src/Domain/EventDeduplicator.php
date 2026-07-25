<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

/**
 * File-based dedupe for webhook events, keyed by Green API's idMessage.
 * Prevents double-processing when Green API retries a delivery.
 */
final class EventDeduplicator
{
    public function __construct(
        private readonly string $locksDirectory,
        private readonly int $ttlSeconds = 300
    ) {
        if (!is_dir($this->locksDirectory) && !mkdir($this->locksDirectory, 0775, true) && !is_dir($this->locksDirectory)) {
            throw new \RuntimeException("No se pudo crear el directorio de locks: {$this->locksDirectory}");
        }
    }

    /**
     * Returns true (and marks the event as processed) only the first time a
     * given key is seen within the TTL window; false on any repeat.
     */
    public function claim(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        $path = $this->pathFor($key);

        if (is_file($path) && (time() - (int)filemtime($path)) < $this->ttlSeconds) {
            return false;
        }

        file_put_contents($path, (string)time());

        return true;
    }

    private function pathFor(string $key): string
    {
        return rtrim($this->locksDirectory, '/') . '/' . hash('sha256', $key) . '.lock';
    }
}
