<?php

declare(strict_types=1);

namespace App\Modules\Document\Mcp;

use App\Core\Mcp\Contracts\McpToolInterface;
use App\Core\Models\Artifact;
use App\Core\Models\Project;
use App\Core\Models\ProjectMember;
use App\Core\Models\User;
use App\Core\Support\Role;
use App\Modules\Document\Models\Document;
use Illuminate\Validation\ValidationException;

class DocumentTools implements McpToolInterface
{
    private const CHUNK_SIZE = 2000;

    /**
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function tools(): array
    {
        return [
            $this->getCreateDescription(),
            $this->getListDescription(),
            $this->getReadDescription(),
            $this->getUpdateDescription(),
            $this->getAppendDescription(),
            $this->getReplaceDescription(),
            $this->getDeleteDescription(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $params, User $user): mixed
    {
        return match ($toolName) {
            'document_create' => $this->createDocument($params, $user),
            'document_list' => $this->listDocuments($params, $user),
            'document_read' => $this->readDocument($params, $user),
            'document_update' => $this->updateDocument($params, $user),
            'document_append' => $this->appendContent($params, $user),
            'document_replace' => $this->replaceContent($params, $user),
            'document_delete' => $this->deleteDocument($params, $user),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };
    }

    // ─── Tool descriptions ───────────────────────────────────────────

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getCreateDescription(): array
    {
        return [
            'name' => 'document_create',
            'description' => 'Create a reference document in a project. Returns the document identifier (e.g. PROJ-5).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code'],
                    'title' => ['type' => 'string', 'description' => 'Document title'],
                    'summary' => ['type' => 'string', 'description' => 'Short summary (max 2000 chars)'],
                    'type' => ['type' => 'string', 'enum' => Document::TYPES, 'description' => 'Document type'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Free-form tags'],
                ],
                'required' => ['project_code', 'title'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getListDescription(): array
    {
        return [
            'name' => 'document_list',
            'description' => 'List documents of a project. Returns identifiers, titles, summaries, types, statuses and tags. Content is NOT included — use document_read to fetch content.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'project_code' => ['type' => 'string', 'description' => 'Project code'],
                    'type' => ['type' => 'string', 'enum' => Document::TYPES, 'description' => 'Filter by type'],
                    'status' => ['type' => 'string', 'enum' => Document::STATUSES, 'description' => 'Filter by status'],
                    'tag' => ['type' => 'string', 'description' => 'Filter by tag'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                ],
                'required' => ['project_code'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getReadDescription(): array
    {
        return [
            'name' => 'document_read',
            'description' => 'Read a document content by its identifier. Content is paginated in chunks of 2000 characters. Returns metadata + content page.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string', 'description' => 'Artifact identifier (e.g. PROJ-5)'],
                    'page' => ['type' => 'integer', 'description' => 'Content page number (default 1)'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getUpdateDescription(): array
    {
        return [
            'name' => 'document_update',
            'description' => 'Update document metadata (title, summary, type, status, tags). Does NOT modify content — use document_append or document_replace for that.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'summary' => ['type' => 'string', 'description' => 'Max 2000 chars'],
                    'type' => ['type' => 'string', 'enum' => Document::TYPES],
                    'status' => ['type' => 'string', 'enum' => Document::STATUSES],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getAppendDescription(): array
    {
        return [
            'name' => 'document_append',
            'description' => 'Append text to the end of a document content. Use this to build content incrementally.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'text' => ['type' => 'string', 'description' => 'Text to append'],
                ],
                'required' => ['identifier', 'text'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getReplaceDescription(): array
    {
        return [
            'name' => 'document_replace',
            'description' => 'Replace the entire content of a document. Pass empty string to clear.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'New content (replaces everything)'],
                ],
                'required' => ['identifier', 'content'],
            ],
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>} */
    private function getDeleteDescription(): array
    {
        return [
            'name' => 'document_delete',
            'description' => 'Delete a document permanently.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'identifier' => ['type' => 'string'],
                ],
                'required' => ['identifier'],
            ],
        ];
    }

    // ─── Tool implementations ────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function createDocument(array $params, User $user): array
    {
        $this->assertCanCrud($user);
        $project = $this->findProjectWithAccess($params['project_code'], $user);

        $this->validateSummaryLength($params['summary'] ?? '');
        $this->validateType($params['type'] ?? 'reference');

        $document = Document::create([
            'project_id' => $project->id,
            'title' => $params['title'],
            'summary' => $params['summary'] ?? '',
            'type' => $params['type'] ?? 'reference',
            'tags' => $params['tags'] ?? null,
        ]);

        $document->load('artifact');

        return $document->format();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function listDocuments(array $params, User $user): array
    {
        $project = $this->findProjectWithAccess($params['project_code'], $user);
        $perPage = min((int) ($params['per_page'] ?? 25), 100);
        $page = max((int) ($params['page'] ?? 1), 1);

        $query = Document::where('project_id', $project->id);

        if (isset($params['type'])) {
            $query->where('type', $params['type']);
        }
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (isset($params['tag'])) {
            $query->whereJsonContains('tags', $params['tag']);
        }

        $documents = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $documents->map(fn (Document $d) => $d->format())->all(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'last_page' => $documents->lastPage(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function readDocument(array $params, User $user): array
    {
        $document = $this->resolveDocument($params['identifier']);
        $this->assertProjectAccess($document->project_id, $user);

        $content = $document->content ?? '';
        $totalLength = mb_strlen($content);
        $totalPages = $totalLength > 0 ? (int) ceil($totalLength / self::CHUNK_SIZE) : 1;
        $page = max((int) ($params['page'] ?? 1), 1);
        $page = min($page, $totalPages);

        $offset = ($page - 1) * self::CHUNK_SIZE;
        $chunk = mb_substr($content, $offset, self::CHUNK_SIZE);

        return [
            ...$document->format(),
            'content' => $chunk,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'chunk_size' => self::CHUNK_SIZE,
                'total_length' => $totalLength,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function updateDocument(array $params, User $user): array
    {
        $this->assertCanCrud($user);
        $document = $this->resolveDocument($params['identifier']);
        $this->assertProjectAccess($document->project_id, $user);

        if (isset($params['summary'])) {
            $this->validateSummaryLength($params['summary']);
        }
        if (isset($params['type'])) {
            $this->validateType($params['type']);
        }
        if (isset($params['status'])) {
            $this->validateStatus($params['status']);
        }

        $data = array_filter([
            'title' => $params['title'] ?? null,
            'summary' => $params['summary'] ?? null,
            'type' => $params['type'] ?? null,
            'status' => $params['status'] ?? null,
            'tags' => $params['tags'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($data)) {
            $document->update($data);
        }

        return $document->format();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function appendContent(array $params, User $user): array
    {
        $this->assertCanCrud($user);
        $document = $this->resolveDocument($params['identifier']);
        $this->assertProjectAccess($document->project_id, $user);

        $document->update([
            'content' => ($document->content ?? '').$params['text'],
        ]);

        return [
            'identifier' => $document->identifier,
            'content_length' => mb_strlen($document->content ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function replaceContent(array $params, User $user): array
    {
        $this->assertCanCrud($user);
        $document = $this->resolveDocument($params['identifier']);
        $this->assertProjectAccess($document->project_id, $user);

        $document->update([
            'content' => $params['content'],
        ]);

        return [
            'identifier' => $document->identifier,
            'content_length' => mb_strlen($document->content ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function deleteDocument(array $params, User $user): array
    {
        $this->assertCanCrud($user);
        $document = $this->resolveDocument($params['identifier']);
        $this->assertProjectAccess($document->project_id, $user);

        $document->delete();

        return ['message' => 'Document deleted.'];
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function resolveDocument(string $identifier): Document
    {
        $model = Artifact::resolveIdentifier($identifier);

        if (! $model instanceof Document) {
            throw ValidationException::withMessages([
                'identifier' => ["'{$identifier}' is not a document."],
            ]);
        }

        return $model;
    }

    private function findProjectWithAccess(string $code, User $user): Project
    {
        $project = Project::where('code', $code)->firstOrFail();

        $this->assertProjectAccess($project->id, $user);

        return $project;
    }

    private function assertProjectAccess(string $projectId, User $user): void
    {
        if (! ProjectMember::where('project_id', $projectId)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['project' => ['Access denied.']]);
        }
    }

    private function assertCanCrud(User $user): void
    {
        if (! Role::canCrudArtifacts($user->role)) {
            throw ValidationException::withMessages([
                'document' => ['You do not have permission to manage documents.'],
            ]);
        }
    }

    private function validateSummaryLength(string $summary): void
    {
        if (mb_strlen($summary) > 2000) {
            throw ValidationException::withMessages([
                'summary' => ['Summary must not exceed 2000 characters.'],
            ]);
        }
    }

    private function validateType(string $type): void
    {
        if (! in_array($type, Document::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => ['Invalid document type. Allowed: '.implode(', ', Document::TYPES)],
            ]);
        }
    }

    private function validateStatus(string $status): void
    {
        if (! in_array($status, Document::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid status. Allowed: '.implode(', ', Document::STATUSES)],
            ]);
        }
    }
}
