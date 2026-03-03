<?php
/**
 * @file classes/IdMapManager.php
 *
 * @brief Manages bidirectional mapping between source (exported) IDs and
 *        target (imported) IDs across all entity types.
 *
 * During import, every entity gets a new ID in the target database. This class
 * tracks those mappings so that relationships (e.g. submission->publication->author)
 * can be correctly re-linked.
 *
 * AI-GENERATED CODE — produced by Claude Opus 4.6 with human testing and verification.
 */

namespace APP\plugins\importexport\fullJournalImport\classes;

class IdMapManager
{
    /** @var array<string, array<int|string, int|string>> */
    protected array $maps = [];

    public function set(string $entityType, int|string $sourceId, int|string $targetId): void
    {
        $this->maps[$entityType][$sourceId] = $targetId;
    }

    public function get(string $entityType, int|string|null $sourceId): int|string|null
    {
        if ($sourceId === null) {
            return null;
        }
        return $this->maps[$entityType][$sourceId] ?? null;
    }

    public function has(string $entityType, int|string $sourceId): bool
    {
        return isset($this->maps[$entityType][$sourceId]);
    }

    public function getAll(string $entityType): array
    {
        return $this->maps[$entityType] ?? [];
    }

    public function getAllMaps(): array
    {
        return $this->maps;
    }

    public function getSummary(): array
    {
        $summary = [];
        foreach ($this->maps as $type => $map) {
            $summary[$type] = count($map);
        }
        return $summary;
    }

    public function toArray(): array
    {
        return $this->maps;
    }
}