<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Toml;

/**
 * Description of ValueList
 *
 * @author Michael Rynn
 * 
 * Toml creates arrays with values of only one type.
 * Aliased the "Tag" property with the array _type.
 */
class ValueList implements Arrayable
{

    public $_type; // string indication of value type
    public $_list = [];
    protected $_tag;

    public function setTag($any)
    {
        $this->_tag = $any;
    }

    public function getTag()
    {
        return $this->_tag;
    }

    public function offsetSet($index, $value)
    {
        if (is_null($index)) {
            $index = count($this->_list);
        } else {
            $index = intval($index);
        }
        $atype = gettype($value);
        if (count($this->_list) === 0) {
            $this->_type = $atype;
        } else {
            if ($this->_type !== $atype) {
                throw new XArrayable("Type " . $atype . " cannot be added to ValueList of " . $this->_type);
            }
        }

        $this->_list[$index] = $value;
    }

    public function offsetExists($index): bool
    {
        return isset($this->_list[$index]);
    }

    public function offsetGet($index)
    {
        return ($this->_list[$index] ?? null);
    }

    public function offsetUnset($index)
    {
        unset($this->_list[$index]);
    }

    public function count(): int
    {
        return count($this->_list);
    }

    public function get(int $index, $defaultValue = null)
    {
        return isset($this->_list[$index]) ? $this->_list[$index] : $defaultValue;
    }

    /**
     * Return array copy of everything with nested ValueList object
     * mediation removed.
     * Modification to Config - allow recurse option, 
     * false for no recurse. It can't be passed on.
     * @param bool $recurse
     * @return array
     */
    public function toArray(bool $recurse = true): array
    {
        $arrayConfig = [];
        foreach ($this->_list as $idx => $value) {
            if ($recurse && is_object($value) && ($value instanceof \Toml\Arrayable)) {

                $arrayConfig[$idx] = $value->toArray();
            } else {
                $arrayConfig[$idx] = $value;
            }
        }
        return $arrayConfig;
    }

}
