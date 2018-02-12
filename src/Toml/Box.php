<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Toml;

/**
 * Description of Box
 * Anything can go here, All access allowed
 * Emulate object reference of enclosed array, without reference &
 * @author Michael Rynn
 */
class Box
{
    public $_me;
    
    public function __construct($me) {
        $this->_me = $me;
    }
}
