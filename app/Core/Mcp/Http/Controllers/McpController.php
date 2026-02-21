<?php

declare(strict_types=1);

namespace App\Core\Mcp\Http\Controllers;

use App\Core\Mcp\Server\McpServer;
use App\Core\Mcp\Server\McpTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class McpController extends Controller
{
    public function __construct(
        private readonly McpServer $mcpServer,
        private readonly McpTransport $transport,
    ) {}

    public function handle(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        try {
            $jsonRpc = $this->transport->decodeRequest($request);
        } catch (\InvalidArgumentException $e) {
            return $this->transport->jsonResponse(
                $this->transport->encodeError(-32600, $e->getMessage(), null)
            );
        }

        /** @var \App\Core\Models\User $user */
        $user = $request->user();

        $response = $this->mcpServer->handleRequest($jsonRpc, $user);

        // Notifications return null → HTTP 202 with no body per MCP spec
        if ($response === null) {
            return response('', 202);
        }

        return $this->transport->jsonResponse($response);
    }

    public function stream(Request $request): StreamedResponse
    {
        return $this->transport->streamResponse();
    }
}
