<?php

namespace App\Models;

use App\Database\JSONDB;
use App\Database\DB;
use App\Traits\HasRelations;

abstract class BaseModel
{
    use HasRelations;

    public ?int $id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    protected static string $collection;
    

    protected static array $tableColumnsCache = [];
    

    protected static string $driver = 'database';
    

    protected static ?string $table = null;
    
    protected array $relations = [];
    
    protected static array $eagerLoad = [];

    public function __construct(array $data = [])
    {
        $this->fill($data);
    }


    protected static function getNextId(): int
    {
        if (static::$driver === 'json') {
            return JSONDB::getNextId(static::$collection);
        } else {
            throw new \Exception("getNextId non supportato per driver database.");
        }
    }


    protected static function getTableName(): string
    {
        return static::$table ?? static::$collection;
    }

   
    protected static function getTableColumns(): array
    {
        if (static::$driver === 'json') {
            return [];
        }
        $table = static::getTableName();
        if (!isset(self::$tableColumnsCache[$table])) {
            $rows = DB::select(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table",
                ['schema' => 'public', 'table' => $table]
            );
            self::$tableColumnsCache[$table] = array_map(fn($r) => $r['column_name'], $rows);
        }
        return self::$tableColumnsCache[$table];
    }

    public static function with(string|array $relations): static
    {
        static::$eagerLoad = is_array($relations) ? $relations : [$relations];

        return new static();
    }


    public static function all(): array
    {
        $rows = [];
        
        if (static::$driver === 'json') {
            $rows = JSONDB::read(static::$collection);
        } else {
            if (!empty(static::$eagerLoad)) {
                $models = static::allWithJoins();
                static::$eagerLoad = [];
                return $models;
            } else {
                $rows = DB::select("SELECT * FROM " . static::getTableName());
            }
        }
        
        $models = array_map(fn($row) => new static($row), $rows);
        
        if (!empty(static::$eagerLoad)) {
            static::eagerLoadRelations($models);
            static::$eagerLoad = [];
        }
        
        return $models;
    }

  
    public static function find(int $id): ?static
    {
        $row = null;
        if (static::$driver === 'json') {
            $collection = JSONDB::read(static::$collection);
            foreach ($collection as $item) {
                if (isset($item['id']) && $item['id'] === $id) {
                    $row = $item;
                    break;
                }
            }
        } else {
            if (!empty(static::$eagerLoad)) {
                $models = static::findWithJoins($id);
                if (!empty($models)) {
                    static::$eagerLoad = [];
                    return $models[0];
                }
                return null;
            } else {
                $result = DB::select("SELECT * FROM " . static::getTableName() . " WHERE id = :id", ['id' => $id]);
                $row = $result[0] ?? null;
            }
        }
        
        if (!$row) {
            return null;
        }

        $model = new static($row);

        if (!empty(static::$eagerLoad)) {
            static::eagerLoadRelations([$model]);
            static::$eagerLoad = [];
        }
        
        return $model;
    }

    public static function where(string $column, mixed $operator, mixed $value = null, string|array|null $relations = null): array
    {
        $numArgs = func_num_args();

        if ($numArgs >= 4 && $relations !== null) {
            static::$eagerLoad = is_array($relations) ? $relations : [$relations];
        }

        $allowedOperators = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];
        $operatorUpper = is_string($operator) ? strtoupper($operator) : '';

        if ($numArgs === 2 || ($numArgs === 3 && $value === null && !in_array($operatorUpper, $allowedOperators))) {
            $value = $operator;
            $operator = '=';
        } elseif (is_string($operator)) {
            $operator = $operatorUpper;
        }

        if (!in_array($operator, $allowedOperators)) {
            throw new \InvalidArgumentException("Operatore non valido: {$operator}");
        }
        
        $models = [];

        if (static::$driver === 'json') {
            $collection = JSONDB::read(static::$collection);
            $filtered = array_filter($collection, function($item) use ($column, $operator, $value) {
                if (!isset($item[$column])) {
                    return false;
                }
                
                $columnValue = $item[$column];
                
                return match($operator) {
                    '=' => $columnValue == $value,
                    '!=', '<>' => $columnValue != $value,
                    '>' => $columnValue > $value,
                    '<' => $columnValue < $value,
                    '>=' => $columnValue >= $value,
                    '<=' => $columnValue <= $value,
                    'LIKE' => str_contains(strtolower($columnValue), strtolower(str_replace('%', '', $value))),
                    'NOT LIKE' => !str_contains(strtolower($columnValue), strtolower(str_replace('%', '', $value))),
                    'IN' => is_array($value) && in_array($columnValue, $value),
                    'NOT IN' => is_array($value) && !in_array($columnValue, $value),
                    default => false
                };
            });
            
            $models = array_map(fn($row) => new static($row), array_values($filtered));
        } else {
            if (!empty(static::$eagerLoad)) {
                $models = static::whereWithJoins($column, $operator, $value);
                static::$eagerLoad = [];
                return $models;
            }

            if (in_array($operator, ['IN', 'NOT IN'])) {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("Il valore per gli operatori IN/NOT IN deve essere un array");
                }

                $placeholders = implode(',', array_fill(0, count($value), '?'));
                $sql = "SELECT * FROM " . static::getTableName() . " WHERE {$column} {$operator} ({$placeholders})";
                $rows = DB::select($sql, array_values($value));
            } else {
                $sql = "SELECT * FROM " . static::getTableName() . " WHERE {$column} {$operator} :value";
                $rows = DB::select($sql, ['value' => $value]);
            }
            
            $models = array_map(fn($row) => new static($row), $rows);
        }

        if (!empty(static::$eagerLoad)) {
            static::eagerLoadRelations($models);
            static::$eagerLoad = [];
        }
        
        return $models;
    }

    public static function create(array $data): static
    {
        $model = new static($data);
        $model->save();
        return $model;
    }

    public function update(array $data): static
    {
        $this->fill($data);
        $this->save();
        return $this;
    }

    public function fill(array $data): static
    {
        foreach($data as $key => $value) {
            if(property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
        return $this;
    }

    public function save(): void
    {
        $isNew = !isset($this->id);
        $now = date('Y-m-d H:i:s');
        $existingColumns = static::getTableColumns();

        if (empty($existingColumns) || in_array('created_at', $existingColumns)) {
            $this->created_at = $this->created_at ?: $now;
        }
        if (empty($existingColumns) || in_array('updated_at', $existingColumns)) {
            $this->updated_at = $now;
        }

        if (static::$driver === 'json') {
            $collectionData = JSONDB::read(static::$collection);
            if ($isNew) {
                $this->id = JSONDB::getNextId(static::$collection);
                $collectionData[] = $this->toArray();
            } else {
                $collectionData = array_map(function ($item) {
                    if ($item['id'] === $this->id) {
                        return $this->toArray();
                    }
                    return $item;
                }, $collectionData);
            }
            JSONDB::write(static::$collection, $collectionData);
        } else {
            $bindings = array_filter($this->toArray(), fn($key) => $key !== 'id', ARRAY_FILTER_USE_KEY);

            if (!empty($existingColumns)) {
                $bindings = array_filter(
                    $bindings,
                    fn($value, $key) => in_array($key, $existingColumns, true),
                    ARRAY_FILTER_USE_BOTH
                );
            }

            $columns = array_keys($bindings);

            $placeholders = array_map(fn($col) => ":{$col}", $columns);

            if ($isNew) {
                $this->id = DB::insert(sprintf("INSERT INTO %s (%s) VALUES (%s)", static::getTableName(), implode(', ', $columns), implode(', ', $placeholders)), $bindings);
            } else {
                $columnWithValues = array_map(fn($col) => "{$col} = :{$col}", $columns);
                $bindings['id'] = $this->id;
                DB::update(sprintf("UPDATE %s SET %s WHERE id = :id", static::getTableName(), implode(', ', $columnWithValues)), $bindings);
            }
        }
    }

    public function delete(): int
    {
        $result = 0;
        if(static::$driver === 'json') {
            $collection = static::all();
            $filtered = array_filter($collection, fn($item) => $item->id !== $this->id);
            $serializable = array_values(array_map(function($item) {
                if ($item instanceof BaseModel) {
                    return $item->toArray();
                }
                return $item;
            }, $filtered));

            $result = JSONDB::write(static::$collection, $serializable);
        } else {
            $result = DB::delete("DELETE FROM " . static::getTableName() . " WHERE id = :id", ['id' => $this->id]);
        }
        if($result === 0) {
            throw new \Exception("Errore durante l'eliminazione dell'utente");
        }
        return $result;
    }

    public function __call(string $method, array $arguments)
    {
        if ($method === 'find' && !empty($arguments)) {
            return static::find($arguments[0]);
        }

        if ($method === 'all' && empty($arguments)) {
            return static::all();
        }

        if ($method === 'where' && count($arguments) >= 2) {
            return static::where(...$arguments);
        }

        throw new \BadMethodCallException("Metodo {$method} non trovato nella classe " . get_class($this));
    }

    public function toArray(): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        $result = [];
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if (in_array($propertyName, ['collection', 'driver', 'table', 'relations'])) {
                continue;
            }
            $result[$propertyName] = $property->getValue($this);
        }

        foreach ($this->relations as $relationName => $relationData) {
            if (is_array($relationData)) {
                $result[$relationName] = array_map(function($model) {
                    return $model instanceof BaseModel ? $model->toArray() : $model;
                }, $relationData);
            } elseif ($relationData instanceof BaseModel) {
                $result[$relationName] = $relationData->toArray();
            } elseif ($relationData === null) {
                $result[$relationName] = null;
            } else {
                $result[$relationName] = $relationData;
            }
        }

        return $result;
    }
}
