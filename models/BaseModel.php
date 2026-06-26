<?php
namespace Quiznosis\Models;

use Quiznosis\Core\Database;
use Quiznosis\Core\Util;
use PDO;

/**
 * BaseModel — active-record-ish wrapper over PDO.
 * Subclasses set:
 *   protected static string $table       — table name
 *   protected static array  $jsonColumns — column names that should be JSON-encoded
 *   protected static array  $fillable    — column allow-list for create/update
 *
 * All methods are static for simplicity (no ORM-style hydration).
 * Primary keys are 24-char hex ids generated via Util::objectId() to match
 * the MongoDB ObjectId shape the Next.js source relied on.
 */
abstract class BaseModel
{
    /** @var string */
    protected static $table = '';
    /** @var array<string> */
    protected static $jsonColumns = [];
    /** @var array<string> */
    protected static $fillable = [];

    // -------------------------------------------------------------------
    //  CRUD
    // -------------------------------------------------------------------

    public static function findById(string $id): ?array
    {
        return self::firstWhere(['id' => $id]);
    }

    public static function firstWhere(array $where): ?array
    {
        [$sql, $params] = self::buildWhere($where);
        $stmt = Database::pdo()->prepare("SELECT * FROM " . static::$table . " {$sql} LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? self::decodeRow($row) : null;
    }

    /**
     * @param array $where  ['col' => 'val', ...]  (=)
     * @param array $opts   ['order' => 'created_at DESC', 'limit' => 20, 'offset' => 0]
     */
    public static function where(array $where = [], array $opts = []): array
    {
        [$sql, $params] = self::buildWhere($where);
        $order = $opts['order']  ?? null;
        $limit = $opts['limit']  ?? null;
        $off   = $opts['offset'] ?? null;
        $tail = '';
        if ($order) $tail .= ' ORDER BY ' . $order;
        if ($limit !== null) {
            $tail .= ' LIMIT ' . (int)$limit;
            if ($off !== null) $tail .= ' OFFSET ' . (int)$off;
        }
        $stmt = Database::pdo()->prepare("SELECT * FROM " . static::$table . " {$sql}{$tail}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map([static::class, 'decodeRow'], $rows);
    }

    public static function count(array $where = []): int
    {
        [$sql, $params] = self::buildWhere($where);
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) AS c FROM " . static::$table . " {$sql}");
        $stmt->execute($params);
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    public static function create(array $data): array
    {
        $data['id'] = $data['id'] ?? Util::objectId();
        $clean = self::pickFillable($data, /*forCreate*/ true);
        $clean['id'] = $data['id'];

        $cols = array_keys($clean);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO ' . static::$table . ' (' . implode(',', array_map([self::class, 'quoteCol'], $cols)) . ') '
             . 'VALUES (' . implode(',', $placeholders) . ')';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(self::encodeForWrite($clean));
        return self::findById($clean['id']) ?? $clean;
    }

    public static function update(string $id, array $data): ?array
    {
        $clean = self::pickFillable($data, /*forCreate*/ false);
        if (empty($clean)) return self::findById($id);
        $set = [];
        foreach ($clean as $c => $_) $set[] = self::quoteCol($c) . ' = :' . $c;
        $sql = 'UPDATE ' . static::$table . ' SET ' . implode(',', $set) . ' WHERE id = :__id';
        $stmt = Database::pdo()->prepare($sql);
        $bind = self::encodeForWrite($clean);
        $bind['__id'] = $id;
        $stmt->execute($bind);
        return self::findById($id);
    }

    public static function deleteById(string $id): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM ' . static::$table . ' WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public static function deleteWhere(array $where): int
    {
        [$sql, $params] = self::buildWhere($where);
        $stmt = Database::pdo()->prepare('DELETE FROM ' . static::$table . ' ' . $sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // -------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------

    protected static function pickFillable(array $data, bool $forCreate): array
    {
        if (empty(static::$fillable)) return $data;
        $allow = static::$fillable;
        if ($forCreate) $allow[] = 'id';
        return array_intersect_key($data, array_flip($allow));
    }

    protected static function encodeForWrite(array $row): array
    {
        foreach (static::$jsonColumns as $col) {
            if (array_key_exists($col, $row) && !is_string($row[$col]) && $row[$col] !== null) {
                $row[$col] = json_encode($row[$col], JSON_UNESCAPED_UNICODE);
            }
        }
        // Booleans → 0/1 to keep MySQL TINYINT happy
        foreach ($row as $k => $v) {
            if (is_bool($v)) $row[$k] = $v ? 1 : 0;
        }
        return $row;
    }

    public static function decodeRow(array $row): array
    {
        foreach (static::$jsonColumns as $col) {
            if (array_key_exists($col, $row) && is_string($row[$col]) && $row[$col] !== '') {
                $decoded = json_decode($row[$col], true);
                if (json_last_error() === JSON_ERROR_NONE) $row[$col] = $decoded;
            }
        }
        return $row;
    }

    /** Build a WHERE clause from an associative array. Values that are arrays
     *  are treated as IN (...). */
    protected static function buildWhere(array $where): array
    {
        if (empty($where)) return ['', []];
        $parts = [];
        $params = [];
        $i = 0;
        foreach ($where as $col => $val) {
            $i++;
            if (is_array($val)) {
                if (empty($val)) {
                    // Empty IN list — make condition impossible
                    $parts[] = '0';
                    continue;
                }
                $ph = [];
                foreach ($val as $j => $v) {
                    $k = ":w{$i}_{$j}";
                    $ph[] = $k;
                    $params[$k] = $v;
                }
                $parts[] = self::quoteCol($col) . ' IN (' . implode(',', $ph) . ')';
            } elseif ($val === null) {
                $parts[] = self::quoteCol($col) . ' IS NULL';
            } else {
                $k = ":w{$i}";
                $parts[] = self::quoteCol($col) . ' = ' . $k;
                $params[$k] = $val;
            }
        }
        return ['WHERE ' . implode(' AND ', $parts), $params];
    }

    /** Quote column names that collide with reserved words like `order` / `rank`. */
    protected static function quoteCol(string $col): string
    {
        // identifier — already letters/underscore-only from our schema
        return '`' . $col . '`';
    }
}
