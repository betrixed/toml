<?php

/**
 * @author Michael Rynn
 */

namespace Toml;

/**
 * Tracking information for Arrayable objects used
 * by Toml\Parser
 */
class PartTag
{
    public $partKey; // String key, path part
    public $isAOT; // bool: Update in parse for TOM04 checks
    public $objAOT; // bool: true for TableList, false for Table
    public $implicit; // bool: implicit flag
    
    public function __construct($key, $objAOT) {
        $this->partKey = $key;
        $this->isAOT = $objAOT;
        $this->objAOT = $objAOT;
        $this->implicit = false;
    }
}
