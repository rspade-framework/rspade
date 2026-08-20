<?php

namespace App\Database;

use Illuminate\Database\Eloquent\Collection;

/**
 * Custom Eloquent collection that prevents eager loading
 * 
 * This collection overrides all eager loading methods to throw exceptions
 * when any form of relationship preloading is attempted on collections.
 */
class RestrictedEloquentCollection extends Collection
{
    /**
     * Prevent eager loading via load()
     *
     * @param mixed $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function load($relations)
    {
        throw new \RuntimeException(
            'Eager loading on collections via load() is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to eager load: ' . $this->format_relations($relations)
        );
    }
    
    /**
     * Prevent eager loading via loadMissing()
     *
     * @param mixed $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function loadMissing($relations)
    {
        throw new \RuntimeException(
            'Conditional eager loading on collections via loadMissing() is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead. ' .
            'Attempted to conditionally eager load: ' . $this->format_relations($relations)
        );
    }
    
    /**
     * Prevent eager loading via loadCount()
     *
     * @param mixed $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function loadCount($relations)
    {
        throw new \RuntimeException(
            'Eager loading counts on collections via loadCount() is not allowed in the RSpade framework. ' .
            'Use explicit count queries instead. ' .
            'Attempted to eager load counts for: ' . $this->format_relations($relations)
        );
    }
    
    /**
     * Prevent eager loading via loadMax()
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function loadMax($relations, $column)
    {
        throw new \RuntimeException(
            'Max eager loading on collections via loadMax() is not allowed in the RSpade framework. ' .
            'Use explicit max queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadMin()
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function loadMin($relations, $column)
    {
        throw new \RuntimeException(
            'Min eager loading on collections via loadMin() is not allowed in the RSpade framework. ' .
            'Use explicit min queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadSum()
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function loadSum($relations, $column)
    {
        throw new \RuntimeException(
            'Sum eager loading on collections via loadSum() is not allowed in the RSpade framework. ' .
            'Use explicit sum queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadAvg()
     *
     * @param array|string $relations
     * @param string $column
     * @return $this
     * @throws \RuntimeException
     */
    public function loadAvg($relations, $column)
    {
        throw new \RuntimeException(
            'Avg eager loading on collections via loadAvg() is not allowed in the RSpade framework. ' .
            'Use explicit avg queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadExists()
     *
     * @param array|string $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function loadExists($relations)
    {
        throw new \RuntimeException(
            'Exists eager loading on collections via loadExists() is not allowed in the RSpade framework. ' .
            'Use explicit exists queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadAggregate()
     *
     * @param mixed $relations
     * @param string $column
     * @param string $function
     * @return $this
     * @throws \RuntimeException
     */
    public function loadAggregate($relations, $column, $function = null)
    {
        throw new \RuntimeException(
            'Aggregate eager loading on collections via loadAggregate() is not allowed in the RSpade framework. ' .
            'Use explicit aggregate queries instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadMorph()
     *
     * @param string $relation
     * @param array $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function loadMorph($relation, $relations)
    {
        throw new \RuntimeException(
            'Morphed eager loading on collections via loadMorph() is not allowed in the RSpade framework. ' .
            'Use explicit queries for each relationship instead.'
        );
    }
    
    /**
     * Prevent eager loading via loadMorphCount()
     *
     * @param string $relation
     * @param array $relations
     * @return $this
     * @throws \RuntimeException
     */
    public function loadMorphCount($relation, $relations)
    {
        throw new \RuntimeException(
            'Morphed count eager loading on collections via loadMorphCount() is not allowed in the RSpade framework. ' .
            'Use explicit count queries instead.'
        );
    }
    
    /**
     * Format relations for error messages
     *
     * @param mixed $relations
     * @return string
     */
    protected function format_relations($relations)
    {
        if (is_array($relations)) {
            $formatted = [];
            foreach ($relations as $key => $value) {
                if (is_string($key)) {
                    $formatted[] = $key;
                } else {
                    $formatted[] = $value;
                }
            }
            return implode(', ', $formatted);
        }
        
        return (string) $relations;
    }
}