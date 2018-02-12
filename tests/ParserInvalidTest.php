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
use Toml\Parser;
use Toml\Lexer;
use Toml\TokenStream;

class ParserInvalidTest extends TestCase
{
    private $parser;

    public function setUp()
    {
        $this->parser = new Parser(new Lexer());
    }

    public function tearDown()
    {
        $this->parser = null;
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_EQUAL at line 1. Expect Key = , [Path] or # Comment
     */
    public function testKeyEmpty()
    {
        $this->parser->parse('= 1');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_HASH at line 1. Expected T_EQUAL
     */
    public function testParseMustFailWhenKeyHash()
    {
        $this->parser->parse('a# = 1');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_NEWLINE at line 1. Expected T_EQUAL
     */
    public function testParseMustFailWhenKeyNewline()
    {
        $this->parser->parse("a\n= 1");
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage The key { dupe } has already been defined previously.
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Unexpected '=' in path, Line 1
     */
    public function testParseMustFailWhenKeyOpenBracket()
    {
        $this->parser->parse('[abc = 1');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Table path cannot be empty at line 1
     */
    public function testParseMustFailWhenKeySingleOpenBracket()
    {
        $this->parser->parse('[');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'b' }.  Expected T_EQUAL
     */
    public function testParseMustFailWhenKeySpace()
    {
        $this->parser->parse('a b = 1');
    }
    /** TOM04 - White space around . is ignored, best practice is no white space, but
     * the fail problem is the '='
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Unexpected '=' in path, Line 2
     */
    public function testParseMustFailWhenKeyStartBracket()
    {
        $this->parser->parse("[a]\n[xyz = 5\n[b]");
    }


    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_EQUAL at line 1. Expected boolean, integer, long, string or datetime.
     */
    public function testParseMustFailWhenKeyTwoEquals()
    {
        $this->parser->parse('key= = 1');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'the' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenTextAfterInteger()
    {
        $this->parser->parse('answer = 42 the ultimate answer?');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Invalid integer number: leading zeros are not allowed. Token: "T_INTEGER" line: 1 value { '042' }.
     */
    public function testParseMustFailWhenIntegerLeadingZeros()
    {
        $this->parser->parse('answer = 042');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { '_42' }.  Expected boolean, integer, long, string or datetime.
     */
    public function testParseMustFailWhenIntegerLeadingUnderscore()
    {
        $this->parser->parse('answer = _42');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Invalid integer number: underscore must be surrounded by at least one digit.
     */
    public function testParseMustFailWhenIntegerFinalUnderscore()
    {
        $this->parser->parse('answer = 42_');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Invalid integer number: leading zeros are not allowed. Token: "T_INTEGER" line: 1 value { '0_42' }.
     */
    public function testParseMustFailWhenIntegerLeadingZerosWithUnderscore()
    {
        $this->parser->parse('answer = 0_42');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_DOT at line 1. Expected boolean, integer, long, string or datetime.
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_DOT at line 1. Expected T_NEWLINE or T_EOS.
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { '_1' }.  Expected boolean, integer, long, string or datetime.
     */
    public function testParseMustFailWhenFloatLeadingUnderscore()
    {
        $this->parser->parse('number = _1.01');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Invalid float number: underscore must be surrounded by at least one digit.
     */
    public function testParseMustFailWhenFloatFinalUnderscore()
    {
        $this->parser->parse('number = 1.01_');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Invalid float number: underscore must be surrounded by at least one digit.
     */
    public function testParseMustFailWhenFloatUnderscorePrefixE()
    {
        $this->parser->parse('number = 1_e6');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'e_6' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenFloatUnderscoreSuffixE()
    {
        $this->parser->parse('number = 1e_6');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { '-7-05T17' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenDatetimeMalformedNoLeads()
    {
        $this->parser->parse('no-leads = 1987-7-05T17:45:00Z');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'T17' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenDatetimeMalformedNoSecs()
    {
        $this->parser->parse('no-secs = 1987-07-05T17:45Z');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { '17' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenDatetimeMalformedNoT()
    {
        $this->parser->parse('no-t = 1987-07-0517:45:00Z');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { '-07-5T17' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenDatetimeMalformedWithMilli()
    {
        $this->parser->parse('with-milli = 1987-07-5T17:45:00.12Z');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_ESCAPE at line 1. This character is not valid.
     */
    public function testParseMustFailWhenBasicStringHasBadByteEscape()
    {
        $this->parser->parse('naughty = "\xAg"');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_ESCAPE at line 1. This character is not valid.
     */
    public function testParseMustFailWhenBasicStringHasBadEscape()
    {
        $this->parser->parse('invalid-escape = "This string has a bad \a escape character."');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_ESCAPE at line 1. This character is not valid.
     */
    public function testParseMustFailWhenBasicStringHasByteEscapes()
    {
        $this->parser->parse('answer = "\x33"');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_EOS at line 1. This character is not valid.
     */
    public function testParseMustFailWhenBasicStringIsNotClose()
    {
        $this->parser->parse('no-ending-quote = "One time, at band camp');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'No' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenThereIsTextAfterBasicString()
    {
        $this->parser->parse('string = "Is there life after strings?" No.');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Type object added to ValueList of integer
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesArraysAndInts()
    {
        $this->parser->parse('arrays-and-ints =  [1, ["Arrays are not integers."]]');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Type double added to ValueList of integer
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesIntsAndFloats()
    {
        $this->parser->parse('ints-and-floats = [1, 1.1]');
    }
/**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Type integer added to ValueList of double
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesFloatAndInts()
    {
        $this->parser->parse('ints-and-floats = [1.1, 1]');
    }
    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Type integer added to ValueList of string
     */
    public function testParseMustFailWhenThereIsAnArrayWithMixedTypesStringsAndInts()
    {
        $this->parser->parse('strings-and-ints = ["hi", 42]');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 2 value { 'No' }.  Expected boolean, integer, long, string or datetime.
     */
    public function testParseMustFailWhenAppearsTextAfterArrayEntries()
    {
        $toml = <<<'toml'
        array = [
            "Is there life after an array separator?", No
            "Entry"
        ]
toml;

        $this->parser->parse($toml);
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 2 value { 'No' }.  Expect '.' or ']' after array item
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage  Syntax error: unexpected token T_UNQUOTED_KEY at line 3 value { 'I' }.  Expected boolean, integer, long, string or datetime.
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Duplicate key path: {fruit}.type line 4
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Duplicate key path: [{a}] line 2
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Table path cannot be empty at line 1
     */
    public function testParseMustFailWhenTableEmpty()
    {
        $this->parser->parse('[]');
    }

    /**
     * TOM04 - expected a dot
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Expected a '.' after path key, Line 1
     */
    public function testParseMustFailWhenTableWhitespace()
    {
        $this->parser->parse('[invalid key]');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Found '..' in path, Line 1
     */
    public function testParseMustFailWhenEmptyImplicitTable()
    {
        $this->parser->parse('[naughty..naughty]');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Unexpected '#' in path, Line 1
     */
    public function testParseMustFailWhenTableWithPound()
    {
        $this->parser->parse("[key#group]\nanswer = 42");
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'this' }.  Expected T_NEWLINE or T_EOS.
     */
    public function testParseMustFailWhenTextAfterTable()
    {
        $this->parser->parse('[error] this shouldn\'t be here');
    }

    /**
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Expected a '.' after path key, Line 1
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_UNQUOTED_KEY at line 1 value { 'b' }.  Expected T_NEWLINE or T_EOS.
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Syntax error: unexpected token T_NEWLINE at line 1. Unexpected token in parseKeyName
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage Table path mismatch with [fruit][variety] line 8
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage AOT Segment cannot be empty, Line 1
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage New line in unfinished path, Line 1
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
     * @expectedException Toml\XArrayable
     * @expectedExceptionMessage AOT Segment cannot be empty, Line 1
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
