<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{id: string, path: string, kind: string, size: int, created_at: string} $resource
 */
class BackupResource extends JsonResource
{
    /**
     * The disk path stays on the server; the filename is the id.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'kind' => $this->resource['kind'],
            'size' => $this->resource['size'],
            'created_at' => $this->resource['created_at'],
        ];
    }
}
