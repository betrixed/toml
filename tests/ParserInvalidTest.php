<?php

/*
 * This file is part of the Yosy\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TomlTests;

use PHPUnit\Framework\TestCase;
use Pun\TomlReader as Parser;

class ParserInvalidTest extends TestCase
{
    private $parser;

    public function setUp()
    {
        $this->parser = new Parser();
    }

    public function tearDown()
    {
        $this->parser = null;
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expect Key = , [Path] or # Comment. Value { = }.
     */
    public function testKeyEmpty()
    {
        $this->parser->parse('= 1');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected: equal { = }.
     */
    public function testParseMustFailWhenKeyHash()
    {
        $this->parser->parse('a# = 1');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected: equal { = }. 
     */
    public function testParseMustFailWhenKeyNewline()
    {
        $this->parser->parse("a\n= 1");
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 2. Duplicate key.
     */
    public function testDuplicateKeys()
    {
        $toml = <<<'toml'
        dupe = false
        dupe = true
toml;

        $this->parser->parse($toml);
    }

    /**
     * TOM04 spaces around '.' can be ignored, therefore space after a key name
     * isn't a problem, the problem is the first wrong character, the '='
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Unexpected '=' in path. Value { = }.
     */
    public function testParseMustFailWhenKeyOpenBracket()
    {
        $this->parser->parse('[abc = 1');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Table path cannot be empty.
     */
    public function testParseMustFailWhenKeySingleOpenBracket()
    {
        $this->parser->parse('[');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected: equal { = }.
     */
    public function testParseMustFailWhenKeySpace()
    {
        $this->parser->parse('a b = 1');
    }
    /** TOM04 - White space around . is ignored, best practice is no white space, but
     * the fail problem is the '='
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 2. Unexpected '=' in path. Value { = }.
     */
    public function testParseMustFailWhenKeyStartBracket()
    {
        $this->parser->parse("[a]\n[xyz = 5\n[b]");
    }


    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. No value type match found for =. Value { = }.
     */
    public function testParseMustFailWhenKeyTwoEquals()
    {
        $this->parser->parse('key= = 1');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected NEWLINE or EOS. Value { the }.
     */
    public function testParseMustFailWhenTextAfterInteger()
    {
        $this->parser->parse('answer = 42 the ultimate answer?');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Invalid integer: Leading zeros not allowed. Value { 042 }.
     */
    public function testParseMustFailWhenIntegerLeadingZeros()
    {
        $this->parser->parse('answer = 042');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. No value type match found for _42. Value { _42 }.
     */
    public function testParseMustFailWhenIntegerLeadingUnderscore()
    {
        $this->parser->parse('answer = _42');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Invalid integer: Underscore must be between digits. Value { 42_ }.
     */
    public function testParseMustFailWhenIntegerFinalUnderscore()
    {
        $this->parser->parse('answer = 42_');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Invalid integer: Leading zeros not allowed. Value { 0_42 }.
     */
    public function testParseMustFailWhenIntegerLeadingZerosWithUnderscore()
    {
        $this->parser->parse('answer = 0_42');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. No value type match found for .12345. Value { .12345 }.
     */
    public function testParseMustFailWhenFloatNoLeadingZero()
    {
        $toml = <<<'toml'
        answer = .12345
        neganswer = -.12345
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Float needs at least one digit after decimal point. Value { 1. }.
     */
    public function testParseMustFailWhenFloatNoTrailingDigits()
    {
        $toml = <<<'toml'
        answer = 1.
        neganswer = -1.
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. No value type match found for _1.01. Value { _1.01 }.
     */
    public function testParseMustFailWhenFloatLeadingUnderscore()
    {
        $this->parser->parse('number = _1.01');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Invalid float: Underscore must be between digits. Value { 1.01_ }.
     */
    public function testParseMustFailWhenFloatFinalUnderscore()
    {
        $this->parser->parse('number = 1.01_');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Invalid float with exponent: Underscore must be between digits. Value { 1_e6 }.
     */
    public function testParseMustFailWhenFloatUnderscorePrefixE()
    {
        $this->parser->parse('number = 1_e6');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Invalid float with exponent: Underscore must be between digits. Value { 1e_6 }.
     */
    public function testParseMustFailWhenFloatUnderscoreSuffixE()
    {
        $this->parser->parse('number = 1e_6');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Value { 1987 } is not full match for { 1987-7-05T17:45:00Z }.
     */
    public function testParseMustFailWhenDatetimeMalformedNoLeads()
    {
        $this->parser->parse('no-leads = 1987-7-05T17:45:00Z');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Value { 1987-07-05 } is not full match for { 1987-07-05T17:45Z }.
     */
    public function testParseMustFailWhenDatetimeMalformedNoSecs()
    {
        $this->parser->parse('no-secs = 1987-07-05T17:45Z');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Value { 1987-07-05 } is not full match for { 1987-07-0517:45:00Z }.
     */
    public function testParseMustFailWhenDatetimeMalformedNoT()
    {
        $this->parser->parse('no-t = 1987-07-0517:45:00Z');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Value { 1987 } is not full match for { 1987-07-5T17:45:00.12Z }.
     */
    public function testParseMustFailWhenDatetimeMalformedWithMilli()
    {
        $this->parser->parse('with-milli = 1987-07-5T17:45:00.12Z');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. parseEscString: Unfinished string value. Value { \ }.
     */
    public function testParseMustFailWhenBasicStringHasBadByteEscape()
    {
        $this->parser->parse('naughty = "\xAg"');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. parseEscString: Unfinished string value. Value { \ }.
     * 
     */
    public function testParseMustFailWhenBasicStringHasBadEscape()
    {
        $this->parser->parse('invalid-escape = "This string has a bad \a escape character."');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. parseEscString: Unfinished string value. Value { \ }
     */
    public function testParseMustFailWhenBasicStringHasByteEscapes()
    {
        $this->parser->parse('answer = "\x33"');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. parseEscString: Unfinished string value.
     */
    public function testParseMustFailWhenBasicStringIsNotClose()
    {
        $this->parser->parse('no-ending-quote = "One time, at band camp');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected NEWLINE or EOS. Value { No }.
     */
    public function testParseMustFailWhenThereIsTextAfterBasicString()
    {
        $this->parser->parse('string = "Is there life after strings?" No.');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Cannot add object to list of integer. Value { [ }.
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesArraysAndInts()
    {
        $this->parser->parse('arrays-and-ints =  [1, ["Arrays are not integers."]]');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Cannot add float to list of integer
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesIntsAndFloats()
    {
        $this->parser->parse('ints-and-floats = [1, 1.1]');
    }
/**
     * @expectedException Exception
     * @expectedExceptionMessage Cannot add integer to list of float
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesFloatAndInts()
    {
        $this->parser->parse('ints-and-floats = [1.1, 1]');
    }
    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Cannot add integer to list of string.
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesStringsAndInts()
    {
        $this->parser->parse('strings-and-ints = ["hi", 42]');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 2. No value type match found for No. Value { No }.
     */
    public function testParseMustFailWhenAppearsTextAfterArrayEntries()
    {
        $toml = <<<'toml'
        array = [
            "Is there life after an array separator?", No
            "Entry"
        ]
toml;
// HANG HERE
        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 2. No value type match found for No. Value { No }.
     */
    public function testParseMustFailWhenAppearsTextBeforeArraySeparator()
    {
        $toml = <<<'toml'
        array = [
            "Is there life before an array separator?" No,
            "Entry"
        ]
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage  Toml Parse at line 3. No value type match found for I. Value { I }.
     */
    public function testParseMustFailWhenAppearsTextInArray()
    {
        $toml = <<<'toml'
        array = [
            "Entry 1",
            I don't belong,
            "Entry 2",
        ]
toml;
        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 4. Duplicate key path: {fruit}.type.
     */
    public function testParseMustFailWhenDuplicateKeyTable()
    {
        $toml = <<<'toml'
        [fruit]
        type = "apple"

        [fruit.type]
        apple = "yes"
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 2. Duplicate key path: {a}. Value { ] }.
     */
    public function testParseMustFailWhenDuplicateTable()
    {
        $toml = <<<'toml'
        [a]
        [a]
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Table path cannot be empty. Value { ] }.
     */
    public function testParseMustFailWhenTableEmpty()
    {
        $this->parser->parse('[]');
    }

    /**
     * TOM04 - expected a dot
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected a '.' after path part.
     */
    public function testParseMustFailWhenTableWhitespace()
    {
        $this->parser->parse('[invalid key]');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Too many consecutive . . in path. Value { . }.
     */
    public function testParseMustFailWhenEmptyImplicitTable()
    {
        $this->parser->parse('[naughty..naughty]');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Unexpected '#' in path. Value { # }.
     */
    public function testParseMustFailWhenTableWithPound()
    {
        $this->parser->parse("[key#group]\nanswer = 42");
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected NEWLINE or EOS. Value { this }.
     */
    public function testParseMustFailWhenTextAfterTable()
    {
        $this->parser->parse('[error] this shouldn\'t be here');
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected a '.' after path part. Value { [ }.
     */
    public function testParseMustFailWhenTableNestedBracketsOpen()
    {
        $toml = <<<'toml'
        [a[b]
        zyx = 42
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Expected NEWLINE or EOS. Value { b }.
     */
    public function testParseMustFailWhenTableNestedBracketsClose()
    {
        $toml = <<<'toml'
        [a]b]
        zyx = 42
toml;
        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. Improper Key.
     */
    public function testParseMustFailWhenInlineTableWithNewline()
    {
        $toml = <<<'toml'
        name = { first = "Tom",
	           last = "Preston-Werner"
        }
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 8. Table path mismatch with [fruit][variety]. Value { ] }.
     */
    public function testParseMustFailWhenTableArrayWithSameNameOfTable()
    {
        $toml = <<<'toml'
        [[fruit]]
        name = "apple"

        [[fruit.variety]]
        name = "red delicious"

        # This table conflicts with the previous table
        [fruit.variety]
        name = "granny smith"
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. AOT Segment cannot be empty. Value { ] }.
     */
    public function testParseMustFailWhenTableArrayMalformedEmpty()
    {
        $toml = <<<'toml'
        [[]]
        name = "Born to Run"
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. New line in unfinished path.
     */
    public function testParseMustFailWhenTableArrayMalformedBracket()
    {
        $toml = <<<'toml'
        [[albums]
        name = "Born to Run"
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Toml Parse at line 1. AOT Segment cannot be empty. Value { ] }.
     */
    public function testParseAOTSegmentNoneEmpty()
    {
        $toml = <<<'toml'
        [[albums].[]]
        name = "Born to Run"
toml;

        $this->parser->parse($toml);
    }    
}
