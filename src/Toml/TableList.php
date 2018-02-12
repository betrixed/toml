<?php

/*
 * @author Michael Rynn
 */

/**
 * Description of TableArray
 * Designed as a dumb list of Table objects
 * 
 * @author Michael Rynn
 */

namespace Toml;
/**
 * This object is limited to storing Table objects in numeric
 * zero-indexed array
 */
class TableList extends Arrayable {
    // This seperates key path component for nested Table objects.
    private $_list;

    public function __construct(array $arrayOfTable = null) {
        if (is_null($arrayOfTable)) {
            $this->_list = [new Table()];
        }
        else {
            //TODO: check all members are Table objects, and no key:values
            $this->_list = $arrayOfTable;
        }
    }
    /** 
     * return offset to last Table *
     * @return int
     */
    public function getEndIndex() : int {
        return count($this->_list)-1;
    }
    /**
     * Return last Table object
     * @return \Toml\Table
     */
    public function getEndTable() : Table {
        return $this->_list[count($this->_list)-1];
    }
    
    public function getTables() {
        return $this->_list;
    }
    /**
     * Return a new table added to the end of the list
     * @return \Toml\Toml\Table
     */
    public function newTable() : Table {
        $table = new Table();
        $this->_list[] = $table;
        return $table;
    }
    public function addTables(TableList $obj) {
        $this->_list = array_merge($this->_list, $obj->_list);
    }
    /**
     * This implements the array-access [],
     * Strictly don't even want to provide such write access
     * so this may just throw exception in future
     * @param type $index
     * @param type $value
     */
    public function offsetSet($index, $value) {
        $key = intval($index);
        if (!is_a($value, '\Toml\Toml\Table')) {
            throw new XArrayable('TableList Value must be a Table');
        }
        $this->_list[$key] = $value;
    }

    public function offsetExists($index): bool {
        $key = intval($index);
        return isset($this->_list[$key]);
    }

    public function offsetGet($index) {
        $key = intval($index);
        return $this->_list[$key];
    }

    /**
     * May throw exception in future
     * @param type $index
     */
    public function offsetUnset($index) {
        $key = intval($index);
        unset($this->_list[$key]);
    }

    public function count(): int {
        return count($this->_list);
    }

    /**
     * Return a copy of the TableList as array.
     * Default $recurse is true to return Table content instead of Table object
     * @param bool $recurse
     * @return array
     */
    public function toArray(bool $recurse = true): array {
        $arrayConfig = array_fill(0,count($this->_list),null);
        foreach ($this->_list as $idx => $value) {
            if ($recurse) {
                $arrayConfig[$idx] = $value->toArray();
            } else {
                $arrayConfig[$idx] = $value;
            }
        }
        return $arrayConfig;
    }

    /** Iterate tree for $callback on values of members
     * 
     * @param type $callback
     */
    public function treeIterateValues($callback) {
        if (!is_callable($callback)) { 
            throw new XArrayable('Needs function for callback');
        }
            
        foreach ($this->_list as $idx => $value) {
            if (is_object($value) && ($value instanceof \Toml\Table)) {
                $value->treeIterateValues($callback);
            }
        }
    }

}
