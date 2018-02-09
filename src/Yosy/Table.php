<?php

/*
 * @author Michael Rynn
 */

/**
 * Description of Table
 * This is almost exactly like Phalcon\Config.
 * The original zephir config.zep source was directly reimplemented in php.
 * There is an added callback for value transformation in the offsetSet method.
 * This was originally used for Lookup of ${name} for substitution by a PHP defined(name) value,
 * useful for filename paths.
 * I found it simpler to just iterate and substitute after initial read
 * , and this abolished a  need to reimplement Phalcon\Config
 * 
 * @author Michael Rynn
 */

namespace Yosy;

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
        $key = strval($index);
        if (is_array($value)) {
            $this->$key = new Table($value);
        } else {
            $this->$key = $value; // default magic __set
        }
    }

    public function offsetExists($index): bool
    {
        $key = strval($index);
        return isset($this->$key);
    }

    public function offsetGet($index)
    {
        $key = strval($index);
        return $this->$key;
    }

    public function offsetUnset($index)
    {
        $key = strval($index);
        $this->$key = null;
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
        $key = strval($index);
        if (isset($this->$key)) {
            return $this->$key;
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
            if ($recurse && is_object($value) && ($value instanceof \Yosy\Arrayable)) {
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
            if (is_object($value) && (is_a($value, '\Yosy\Toml\Table') || is_a($value, '\Yosy\Toml\TableList'))) {
                $value->treeIterateValues($callback);
            } else {
                $this->$key = \call_user_func($callback, $value);
            }
        }
    }
}
