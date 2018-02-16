<?php

/*
 * @author Michael Rynn
 */

/**
 * Store properties in objects property table.
 * I thought that strval(index) conversions would
 * enforce string keys, but PHP always converts numeric strings
 * into binary values. PHP internals override any attempt
 * to use numeric strings as keys.
 * @author Michael Rynn
 */

namespace Toml;

/**
 * Converts all keys to string, stores values as internal php object property.
 * Handles TableList object as values.
 * Is Recursive for Table objects as value.
 * 
 */
class Table extends Arrayable
{
    const PATH_DELIMITER = '.';

    
    
    public function mergeArray(array $kv) : void {
        foreach ($kv as $key => $value) {
            $this->offsetSet($key, $value);
        }
    }
    
    /**
     * Associate management properties to private tag 
     * @param type $any
     */
    
    public function __construct(array $aval = null)
    { 
        if (!is_null($aval)) {
            $this->mergeArray($aval);
        }
    }

    /**
     * This implements the array-access [],
     * but is not called for property access.
     * Looks like a good place to 
     * substitute global defines for ${defined}
     * @param type $index
     * @param type $value
     * Does not get called when values set as ->property
     * as this uses magic __set
     */
    public function offsetSet($index, $value)
    {
        if (is_array($value)) {
            $this->$index = new Table($value);
        } else {
            $this->$index = $value; // default magic __set
        }
    }

    public function offsetExists($index): bool
    {
        return isset($this->$index);
    }

    public function offsetGet($index)
    {
        return $this->$index;
    }

    public function offsetUnset($index)
    {
        $this->$index = null;
    }

    public function count(): int
    {
        return count(get_object_vars($this));
    }

    public function path(string $path, $defaultValue = null)
    {
        if (isset($this->$path)) {
            return $this->$path;
        }
        $delimiter = Table::PATH_DELIMITER;

        $config = $this;
        $keys = explode($delimiter, $path);

        while (!empty($keys)) {
            $key = array_shift($keys);
            if (!isset($config->$key)) {
                break;
            }

            if (empty($keys)) {
                return $config->$key;
            }
            $config = $config->$key;

            if (empty($config)) {
                break;
            }
        }
        return $defaultValue;
    }

    public function get($index, $defaultValue = null)
    {
        if (isset($this->$index)) {
            return $this->$index;
        }
        return $defaultValue;
    }

    /**
     * Modification to Config - allow recurse option, 
     * false for no recurse. It can't be passed on.
     * @param bool $recurse
     * @return array
     */
    public function toArray(bool $recurse = true): array
    {
        $arrayConfig = [];
        foreach (get_object_vars($this) as $key => $value) {
            if ($recurse && is_object($value) && ($value instanceof \Toml\Arrayable)) {
                $arrayConfig[$key] = $value->toArray();
            } else {
                $arrayConfig[$key] = $value;
            }
        }
        return $arrayConfig;
    }

    /** iterate the config tree for $callback on values
     * 
     * @param type $callback
     */
    public function treeIterateValues($callback)
    {
        if (!is_callable($callback)) {
            throw new XArrayable('Needs function for callback');
        }

        foreach (get_object_vars($this) as $key => $value) {
            if (is_object($value) && (is_a($value, '\Toml\Toml\Table') || is_a($value, '\Toml\Toml\TableList'))) {
                $value->treeIterateValues($callback);
            } else {
                $this->$key = \call_user_func($callback, $value);
            }
        }
    }
}
