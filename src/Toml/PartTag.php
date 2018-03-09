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
    public $objAOT; // bool: true for TableList, false for Table
    public $implicit; // bool: implicit flag
}
