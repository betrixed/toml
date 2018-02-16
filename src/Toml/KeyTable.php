<?php
/**
 * @author Michael Rynn
 */

namespace Toml;
/**
 * This is more general, no frills, object wrap of a PHP array
 * It is more inefficient than a bare PHP array.
 * The internal $_store is public for easy iteration.
 * Any PHP key type is allowed. 
 * Aim is to have a "referenced" array as object without a reference operator &
 */
class KeyTable extends Arrayable
{

    public $_store;

    public function __construct(array $init = null)
    {
        //$this->setTag(true);
        if (!empty($init)) {
            $this->_store = $init;
        } else {
            $this->_store = [];
        }
    }

    /**
     * @param type $index
     * @param type $value
     * Does not get called when values set as ->property
     * as this uses magic __set
     */
    public function offsetSet($index, $value)
    {
        $this->_store[$index] = $value;
    }

    public function offsetExists($index): bool
    {
        return isset($this->_store[$index]);
    }

    public function offsetGet($index)
    {
        return ($this->_store[$index] ?? null);
    }

    public function offsetUnset($index)
    {
        unset($this->_store[$index]);
    }

    public function count(): int
    {
        return count($this->_store);
    }

    public function get($index, $defaultValue = null)
    {
        return isset($this->_store[$index]) ? $this->_store[$index] : $defaultValue;
    }

    /**
     * Return array copy of everything with nested KeyTable object
     * mediation removed.
     * Modification to Config - allow recurse option, 
     * false for no recurse. It can't be passed on.
     * @param bool $recurse
     * @return array
     */
    public function toArray(bool $recurse = true): array
    {
        $arrayConfig = [];
        foreach ($this->_store as $key => $value) {
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
            if (is_object($value) && (is_a($value, '\Toml\KeyTable'))) {
                $value->treeIterateValues($callback);
            } else {
                $this->_store[$key] = \call_user_func($callback, $value);
            }
        }
    }

    public function merge(Mergeable $config): KeyTable
    {
        return $this->_merge($config);
    }

    /**
     * Merge values in $config, into the properties of $instance
     * TableList objects are added together.
     * @param \Toml\KeyTable $config
     * @param \Toml\KeyTable  $instance
     * @return \Toml\KeyTable 
     */
    protected final function _merge(KeyTable $config, KeyTable $instance = null): KeyTable
    {
        if (!is_object($instance)) {
            $instance = $this;
        }
        foreach ($config->_store as $key => $value) {
            $localObject = isset($instance->_store[$key]) ? $instance->_store[$key]
                        : null;

            if (is_object($localObject)) {
                // Are both objects Mergeable ? 
                if (($localObject instanceof \Toml\KeyTable) && is_object($value) && ($value instanceof \Toml\KeyTable)) {
                    $this->_merge($value, $localObject);
                    continue;
                }
            }
            $instance->_store[$key] = $value;
        }
        return $instance;
    }

}
