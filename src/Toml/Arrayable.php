<?php
namespace Toml;

/**
 * Provides array syntax like access to get and set PHP object properties, and
 * restricted conversions to and from array via merge and toArray.
 * The Tag property is for possible management data.
 * 
 * @author Michael Rynn
 */

interface Arrayable extends \ArrayAccess, \Countable {
    public function setTag($any);   
    public function getTag();
    public function toArray(): array;
}
