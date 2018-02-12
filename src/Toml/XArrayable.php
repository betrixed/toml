<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Toml;

/**
 * Description of ArrayException
 *
 * @author michael
 */
class XArrayable extends \Exception
{
    public function __construct(string $msg) {
        parent::__construct($msg);
    }
}
