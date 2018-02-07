<?php

/*
 * This file is part of the Yosymfony\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Yosymfony\Toml;

/**
 * Lexer for Toml strings.
 *
 * @author Victor Puertas <vpgugr@vpgugr.com>
 */
class Lexer
{

    const T_BAD = 0;
    const T_EQUAL = 1;
    const T_BOOLEAN = 2;
    const T_DATE_TIME = 3;
    const T_EOS = 4;
    const T_INTEGER = 5;
    const T_3_QUOTATION_MARK = 6;
    const T_QUOTATION_MARK = 7;
    const T_3_APOSTROPHE = 8;
    const T_APOSTROPHE = 9;
    const T_NEWLINE = 10;
    const T_SPACE = 11;
    const T_LEFT_SQUARE_BRACE = 12;
    const T_RIGHT_SQUARE_BRACE = 13;
    const T_LEFT_CURLY_BRACE = 14;
    const T_RIGHT_CURLY_BRACE = 15;
    const T_COMMA = 16;
    const T_DOT = 17;
    const T_UNQUOTED_KEY = 18;
    const T_ESCAPED_CHARACTER = 19;
    const T_ESCAPE = 20;
    const T_BASIC_UNESCAPED = 21;
    const T_FLOAT = 22;
    const T_HASH = 23;
    const T_CHAR = 24;
    const T_LAST_TOKEN = 24; // for range values of token lookup

    static private $nameSet = [
        'T_BAD', //0
        'T_EQUAL', //1
        'T_BOOLEAN', //2
        'T_DATE_TIME', //3
        'T_EOS', //4
        'T_INTEGER', //5
        'T_3_QUOTATION_MARK', //6
        'T_QUOTATION_MARK', //7
        'T_3_APOSTROPHE', //8
        'T_APOSTROPHE', //9
        'T_NEWLINE', //10
        'T_SPACE', //11
        'T_LEFT_SQUARE_BRACE', //12
        'T_RIGHT_SQUARE_BRACE', //13
        'T_LEFT_CURLY_BRACE', //14
        'T_RIGHT_CURLY_BRACE', //15
        'T_COMMA', //16
        'T_DOT', //17
        'T_UNQUOTED_KEY', //18
        'T_ESCAPED_CHARACTER', //19
        'T_ESCAPE', //20
        'T_BASIC_UNESCAPED', //21
        'T_FLOAT', //22
        'T_HASH', //23
        'T_CHAR', // 24
    ];
    static public $Singles = [
        '=' => Lexer::T_EQUAL,
        '[' => Lexer::T_LEFT_SQUARE_BRACE,
        ']' => Lexer::T_RIGHT_SQUARE_BRACE,
        '.' => Lexer::T_DOT,
        ',' => Lexer::T_COMMA,
        '{' => Lexer::T_LEFT_CURLY_BRACE,
        '}' => Lexer::T_RIGHT_CURLY_BRACE,
        '"' => Lexer::T_QUOTATION_MARK,
        "'" => Lexer::T_APOSTROPHE,
        "#" => Lexer::T_HASH,
        '\\' => Lexer::T_ESCAPE,
    ];
    // for particular tests, Lexer::T_BASIC_UNESCAPED must be last
    // and single character expressions first.
    // otherwise keep them out of the way.

    static public $Regex = [
        Lexer::T_EQUAL => '/^(=)/',
        Lexer::T_BOOLEAN => '/^(true|false)/',
        Lexer::T_DATE_TIME => '/^(\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}(\.\d{6})?(Z|-\d{2}:\d{2})?)?)/',
        Lexer::T_FLOAT => '/^([+-]?((((\d_?)+[\.]?(\d_?)*)[eE][+-]?(\d_?)+)|((\d_?)+[\.](\d_?)+)))/',
        Lexer::T_INTEGER => '/^([+-]?(\d_?)+)/',
        Lexer::T_3_QUOTATION_MARK => '/^(""")/',
        Lexer::T_QUOTATION_MARK => '/^(")/',
        Lexer::T_3_APOSTROPHE => "/^(''')/",
        Lexer::T_APOSTROPHE => "/^(')/",
        Lexer::T_HASH => '/^(#)/',
        Lexer::T_SPACE => '/^(\s+)/',
        Lexer::T_LEFT_SQUARE_BRACE => '/^(\[)/',
        Lexer::T_RIGHT_SQUARE_BRACE => '/^(\])/',
        Lexer::T_LEFT_CURLY_BRACE => '/^(\{)/',
        Lexer::T_RIGHT_CURLY_BRACE => '/^(\})/',
        Lexer::T_COMMA => '/^(,)/',
        Lexer::T_DOT => '/^(\.)/',
        Lexer::T_UNQUOTED_KEY => '/^([-A-Z_a-z0-9]+)/',
        Lexer::T_ESCAPED_CHARACTER => '/^(\\\(b|t|n|f|r|"|\\\\|u[0-9AaBbCcDdEeFf]{4,4}|U[0-9AaBbCcDdEeFf]{8,8}))/',
        Lexer::T_BASIC_UNESCAPED => '/^([\x{20}-\x{21}\x{23}-\x{26}\x{28}-\x{5A}\x{5E}-\x{10FFFF}]+)/u'
    ];
    // php retains an array insertion order, so order of these is signficant, 
    // and if it wasn't , it would need to be enforced by iterating these directly
    static public $BriefList = [
        Lexer::T_SPACE,
        Lexer::T_UNQUOTED_KEY,
        Lexer::T_INTEGER,
    ];
    
    static public $BasicStringList = [
        Lexer::T_SPACE, Lexer::T_BASIC_UNESCAPED, Lexer::T_ESCAPED_CHARACTER, Lexer::T_3_QUOTATION_MARK,
    ];
    static public $LiteralStringList = [
        Lexer::T_BASIC_UNESCAPED, Lexer::T_ESCAPED_CHARACTER, Lexer::T_3_APOSTROPHE,
    ];
    // order is important, since T_INTEGER if first, will gazump T_FLOAT, T_DATE_TIME
    static public $FullList = [
        Lexer::T_SPACE, Lexer::T_BOOLEAN, Lexer::T_DATE_TIME, Lexer::T_FLOAT, Lexer::T_INTEGER, 
        Lexer::T_3_QUOTATION_MARK, Lexer::T_3_APOSTROPHE,
        Lexer::T_UNQUOTED_KEY,
        Lexer::T_ESCAPED_CHARACTER
    ];

    /**
     * Basic lexer testing of all regular expression parsing
     * {@inheritdoc}
     */
    public function tokenize(string $input): TokenArray
    {
        // convert string into array of tokens
        // clone each from the stream

        $stream = new TokenStream();
        $stream->setExpList(self::$Regex);
        $stream->setSingles(self::$Singles);
        $stream->setUnknownId(Lexer::T_CHAR);

        $stream->setInput($input);

        $result = [];

        while ($stream->hasPendingTokens()) {
            $result[] = clone $stream->moveNext();
        }
        $result[] = clone $stream->end();

        return new TokenArray($result);
    }

    static public function tokenName(int $tokenId): string
    {
        if ($tokenId > Lexer::T_LAST_TOKEN || $tokenId < 0) {
            $tokenId = 0;
        }
        return self::$nameSet[$tokenId];
    }

}
