<?php

declare(strict_types=1);

namespace App\Core\Mcp\Server;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpTransport
{
    /**
     * @return array{jsonrpc: string, method: string, id: string|null, params: array}
     */
    public function decodeRequest(Request $request): array
    {
        $payload = $request->json()->all();

        if (($payload['jsonrpc'] ?? null) !== '2.0' || empty($payload['method'])) {
            throw new \InvalidArgumentException('Invalid JSON-RPC 2.0 request.');
        }

        return [
            'jsonrpc' => '2.0',
            'method' => $payload['method'],
            'id' => $payload['id'] ?? null,
            'params' => $payload['params'] ?? [],
        ];
    }

    public function encodeResponse(mixed $result, string|int|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public function encodeError(int $code, string $message, string|int|null $id, mixed $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }

    public function jsonResponse(array $payload): JsonResponse
    {
        return new JsonResponse($payload);
    }

    public function streamResponse(): StreamedResponse
    {
        return new StreamedResponse(function () {
            echo "event: ping\n";
            echo "data: {}\n\n";

            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
