<?php

namespace Tests\Support;

use Closure;
use Illuminate\Session\ArraySessionHandler;
use SessionHandlerInterface;

/**
 * An in-memory session handler that reports every destroy(), read() and write() to a probe before delegating it.
 *
 * Lets a test observe the moment a session is destroyed, read or saved (what else had been persisted by then),
 * interleave a concurrent action there, or make the destruction fail - how the ordering and failure handling of
 * revocation paths are pinned.
 */
final readonly class ObservableSessionHandler implements SessionHandlerInterface
{
    private SessionHandlerInterface $inner;

    /**
     * @param  Closure(string): void  $onDestroy  called with the session id before it is destroyed; may throw
     * @param  Closure(string): void|null  $onRead  called with the session id before it is read
     * @param  Closure(string): void|null  $onWrite  called with the session id before it is written
     */
    public function __construct(
        private Closure $onDestroy,
        private ?Closure $onRead = null,
        private ?Closure $onWrite = null,
    ) {
        $this->inner = new ArraySessionHandler(120);
    }

    /**
     * Register the handler as the configured session driver for the rest of the test.
     *
     * @param  Closure(string): void  $onDestroy
     * @param  Closure(string): void|null  $onRead
     * @param  Closure(string): void|null  $onWrite
     */
    public static function install(Closure $onDestroy, ?Closure $onRead = null, ?Closure $onWrite = null): void
    {
        // The manager rebinds the creator's scope to itself, so the class is named rather than written as self.
        app('session')->extend('observable',
            fn(): ObservableSessionHandler => new ObservableSessionHandler($onDestroy, $onRead, $onWrite));
        config(['session.driver' => 'observable']);
    }

    public function open(string $path, string $name): bool
    {
        return $this->inner->open($path, $name);
    }

    public function close(): bool
    {
        return $this->inner->close();
    }

    public function read(string $id): string|false
    {
        if ($this->onRead !== null) {
            ($this->onRead)($id);
        }

        return $this->inner->read($id);
    }

    public function write(string $id, string $data): bool
    {
        if ($this->onWrite !== null) {
            ($this->onWrite)($id);
        }

        return $this->inner->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        ($this->onDestroy)($id);

        return $this->inner->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->inner->gc($max_lifetime);
    }
}
