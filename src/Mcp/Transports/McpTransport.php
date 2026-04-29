<?php

namespace Laravel\Ai\Mcp\Transports;

interface McpTransport
{
    /**
     * Open the transport connection.
     */
    public function open(): void;

    /**
     * Send a JSON-RPC request and receive a response.
     */
    public function send(array $request): array;

    /**
     * Send a JSON-RPC notification.
     */
    public function notify(array $notification): void;

    /**
     * Close the transport connection.
     */
    public function close(): void;

    /**
     * Determine if the transport connection is open.
     */
    public function isOpen(): bool;
}
