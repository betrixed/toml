<?php
/*
 * This file is part of the Yosymfony\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Toml;

/** 
 * Useful to pass around values,
 * and hold intermediate parse result
 *  
 */
class Token
{
    public $value;
    public $id; // integer identity!
    public $line;
    public $isSingle;

    /**
     * Set all at Once
     *
     * @param string $value The value of the token
     * @param int $id The constant id of the token. e.g: T_BRACE_BEGIN
     * @param int $line Line of the code in where the token is found.
     * @param A single unicode character found in a table of such
     */
    public function set(string $value, int $id, int $line, bool $isSingle=false)
    {
        $this->value = $value;
        $this->id = $id;
        $this->line = $line;
        $this->isSingle = $isSingle;
    }


    public function __toString() : string
    {
        return sprintf(
            "[\n id: %s\n value:%s\n line: %s\n singular: %s\n]",
            $this->id,
            $this->value,
            $this->line,
            $this->isSingle
        );
    }
}
