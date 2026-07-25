<?php
declare(strict_types=1);

namespace NutriHelper\Repository;

use NutriHelper\Db\Database;

final class NutritionRepository
{
    public function __construct(private readonly \PDO $conn)
    {
    }

    /**
     * @return array{0:string,1:string} [start, end) of "today" (America/Argentina/Buenos_Aires
     *                                   calendar day), expressed as UTC datetime strings.
     */
    private function todayUtcBounds(): array
    {
        $tzLocal = new \DateTimeZone('America/Argentina/Buenos_Aires');
        $tzUtc = new \DateTimeZone('UTC');

        $startLocal = new \DateTime('today', $tzLocal);
        $endLocal = (clone $startLocal)->modify('+1 day');

        return [
            (clone $startLocal)->setTimezone($tzUtc)->format('Y-m-d H:i:s'),
            (clone $endLocal)->setTimezone($tzUtc)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Counts today's entries (America/Argentina/Buenos_Aires calendar day) for
     * a given identifier — used to enforce the 4-meals-per-day limit.
     */
    public function countTodayForIdentifier(string $identifier): int
    {
        [$startUtc, $endUtc] = $this->todayUtcBounds();

        $stmt = $this->conn->prepare(
            'SELECT COUNT(*) FROM nutri WHERE identifier = ? AND datetime >= ? AND datetime < ?'
        );
        $stmt->execute([$identifier, $startUtc, $endUtc]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Today's entries for an identifier, chronological — the raw material for
     * the end-of-day summary (includes the advice given at the time, if any).
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchTodayEntriesForIdentifier(string $identifier): array
    {
        [$startUtc, $endUtc] = $this->todayUtcBounds();

        $hasConsejo = Database::tableHasColumn($this->conn, 'nutri', 'consejo_actual');
        $fields = [
            'datetime', 'descripcion',
            'calorias', 'proteinas', 'grasas', 'carbohidratos',
        ];
        if ($hasConsejo) {
            $fields[] = 'consejo_actual';
        }

        $sql = sprintf(
            'SELECT %s FROM nutri WHERE identifier = ? AND datetime >= ? AND datetime < ? ORDER BY datetime ASC',
            implode(', ', $fields)
        );

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$identifier, $startUtc, $endUtc]);

        return $stmt->fetchAll();
    }

    /**
     * Chat numbers + identifiers of everyone with at least one meal logged
     * today — the recipient list for the end-of-day summary.
     *
     * @return array<int,array{number:string,identifier:string}>
     */
    public function findTodaysSummaryTargets(): array
    {
        [$startUtc, $endUtc] = $this->todayUtcBounds();

        $stmt = $this->conn->prepare(
            'SELECT DISTINCT p.number, p.identifier
             FROM persona p
             INNER JOIN nutri n ON n.identifier = p.identifier
             WHERE n.datetime >= ? AND n.datetime < ?'
        );
        $stmt->execute([$startUtc, $endUtc]);

        return $stmt->fetchAll();
    }

    /**
     * Inserts a nutrition record, skipping the insert (returns '') when the
     * description is identical to the identifier's most recent entry — avoids
     * duplicate rows from webhook retries.
     *
     * @param array{
     *     foto:string,
     *     descripcion:string,
     *     identifier:string,
     *     calorias:int,
     *     proteinas:int,
     *     grasas:int,
     *     carbohidratos:int,
     *     calorias_label:string,
     *     proteinas_label:string,
     *     grasas_label:string,
     *     carbohidratos_label:string,
     *     source?:string,
     *     consejo_actual?:string
     * } $record
     */
    public function insert(array $record): string
    {
        $lastDescription = $this->lastDescription($record['identifier']);
        if ($lastDescription !== null && trim($lastDescription) === trim($record['descripcion'])) {
            return '';
        }

        $hasSource = Database::tableHasColumn($this->conn, 'nutri', 'source');
        $hasConsejo = Database::tableHasColumn($this->conn, 'nutri', 'consejo_actual');

        $columns = [
            'foto', 'descripcion', 'datetime', 'identifier',
            'calorias', 'proteinas', 'grasas', 'carbohidratos',
            'calorias_label', 'proteinas_label', 'grasas_label', 'carbohidratos_label',
        ];
        $values = [
            $record['foto'], $record['descripcion'], $record['identifier'],
            $record['calorias'], $record['proteinas'], $record['grasas'], $record['carbohidratos'],
            $record['calorias_label'], $record['proteinas_label'], $record['grasas_label'], $record['carbohidratos_label'],
        ];

        if ($hasSource) {
            $columns[] = 'source';
            $values[] = $record['source'] ?? '';
        }

        if ($hasConsejo) {
            $columns[] = 'consejo_actual';
            $values[] = $record['consejo_actual'] ?? '';
        }

        // datetime uses NOW() rather than a bound placeholder.
        $placeholders = array_map(
            static fn (string $col) => $col === 'datetime' ? 'NOW()' : '?',
            $columns
        );

        $sql = sprintf(
            'INSERT INTO nutri (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($values);

        $id = $this->conn->lastInsertId();

        return $id !== '' ? $id : '';
    }

    /**
     * Chronological (oldest first) — matches the public history page, which
     * relies on ascending order to build its day separators; the page's own
     * JS re-sorts for display (defaults to newest-first).
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchEntriesForIdentifier(string $identifier): array
    {
        $hasSource = Database::tableHasColumn($this->conn, 'nutri', 'source');

        $fields = [
            'foto', 'descripcion', 'datetime',
            'calorias', 'proteinas', 'grasas', 'carbohidratos',
            'calorias_label', 'proteinas_label', 'grasas_label', 'carbohidratos_label',
        ];
        if ($hasSource) {
            $fields[] = 'source';
        }

        $sql = sprintf(
            'SELECT %s FROM nutri WHERE identifier = ? ORDER BY datetime ASC',
            implode(', ', $fields)
        );

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$identifier]);

        return $stmt->fetchAll();
    }

    private function lastDescription(string $identifier): ?string
    {
        $stmt = $this->conn->prepare(
            'SELECT descripcion FROM nutri WHERE identifier = ? ORDER BY datetime DESC LIMIT 1'
        );
        $stmt->execute([$identifier]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string)$value : null;
    }
}
