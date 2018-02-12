<?php

/*
 * This file is part of the Yosymfony\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TomlTests;

use PHPUnit\Framework\TestCase;
use Toml\Input;

class TomlTest extends TestCase
{
    public function testParseMustParseAString()
    {
        $array = Input::parse('data = "question"');

        $this->assertEquals([
            'data' => 'question',
        ], $array);
    }

    public function testParseMustReturnEmptyArrayWhenStringEmpty()
    {
        $array = Input::parse('');

        $this->assertNull($array);
    }

    public function testParseFileMustParseFile()
    {
        $filename = __DIR__.'/fixtures/simple.toml';

        $array = Input::parseFile($filename);

        $this->assertEquals([
            'name' => 'Víctor',
        ], $array);
    }

    public function testParseMustReturnAnObjectWhenArgumentResultAsObjectIsTrue()
    {
        $actual = Input::parse('name = "Víctor"', true);
        $expected = new \stdClass();
        $expected->name = 'Víctor';

        $this->assertEquals($expected, $actual);
    }

    public function testParseFileMustReturnAnObjectWhenArgumentResultAsObjectIsTrue()
    {
        $filename = __DIR__.'/fixtures/simple.toml';

        $actual = Input::parseFile($filename, true);
        $expected = new \stdClass();
        $expected->name = 'Víctor';

        $this->assertEquals($expected, $actual);
    }

    /**
     * @expectedException Toml\Exception\ParseException
     */
    public function testParseFileMustFailWhenFilenameDoesNotExists()
    {
        $filename = __DIR__.'/fixtures/does-not-exists.toml';

        Input::parseFile($filename);
    }
}
