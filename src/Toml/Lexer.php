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

use Pun\Re8map;
use Pun\Token8Stream;
use Pun\Token8;
/**
 * Lexer for Toml strings.
 *
 * @author Victor Puertas <vpgugr@vpgugr.com>
 */
class Lexer
{

    // register all the regular expressions that 
    // might be used.  Not all of them all the time!
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
    const T_FLOAT_EXP = 22;
    const T_FLOAT = 23;
    const T_HASH = 24;
    const T_LITERAL_STRING = 25;
    const T_CHAR = 26;
    const T_LAST_TOKEN = 26; // for range values of  named token lookup
    const TOML_VERSION = "0.4";
    const USE_VERSION = "Interpreted";

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
        'T_FLOAT_EXP', //22
        'T_FLOAT', //23
        'T_HASH', //24
        'T_LITERAL_STRING', //25
        'T_CHAR', // 26
    ];
    // for particular tests, Lexer::T_BASIC_UNESCAPED must be last
    // and single character expressions first.
    // otherwise keep them out of the way.
    static private $_AllRegExp;
    static private $_AllExpIds;
    static private $_AllSingles;

    static public function getAllSingles(): array
    {
        if (empty(Lexer::$_AllSingles)) {
            $kt = [];
            $kt["="] = Lexer::T_EQUAL;
            $kt["["] = Lexer::T_LEFT_SQUARE_BRACE;
            $kt["]"] = Lexer::T_RIGHT_SQUARE_BRACE;
            $kt["."] = Lexer::T_DOT;
            $kt[","] = Lexer::T_COMMA;
            $kt["\""] = Lexer::T_QUOTATION_MARK;
            $kt["."] = Lexer::T_DOT;
            $kt["{"] = Lexer::T_LEFT_CURLY_BRACE;
            $kt["}"] = Lexer::T_RIGHT_CURLY_BRACE;
            $kt["'"] = Lexer::T_APOSTROPHE;
            $kt["#"] = Lexer::T_HASH;
            $kt["\\"] = Lexer::T_ESCAPE;
            $kt[" "] = Lexer::T_SPACE;
            $kt["\t"] = Lexer::T_SPACE;
            // maybe these are not necessary
            $kt[chr(12)] = Lexer::T_SPACE;
            $kt[chr(8)] = Lexer::T_SPACE;
            Lexer::$_AllSingles = $kt;
        }
        return Lexer::$_AllSingles;
    }

    static public function getAllIds()
    {
        if (empty(Lexer::$_AllExpIds)) {
            Lexer::$_AllExpIds = [
                Lexer::T_EQUAL, Lexer::T_BOOLEAN, Lexer::T_DATE_TIME,
                Lexer::T_FLOAT_EXP,
                Lexer::T_FLOAT, Lexer::T_INTEGER,
                Lexer::T_3_QUOTATION_MARK, Lexer::T_QUOTATION_MARK,
                Lexer::T_3_APOSTROPHE, Lexer::T_APOSTROPHE,
                Lexer::T_HASH, Lexer::T_SPACE,
                Lexer::T_LEFT_SQUARE_BRACE, Lexer::T_RIGHT_SQUARE_BRACE,
                Lexer::T_LEFT_CURLY_BRACE, Lexer::T_RIGHT_CURLY_BRACE,
                Lexer::T_COMMA, Lexer::T_DOT, Lexer::T_UNQUOTED_KEY,
                Lexer::T_ESCAPED_CHARACTER, Lexer::T_ESCAPE,
                Lexer::T_BASIC_UNESCAPED, Lexer::T_LITERAL_STRING
            ];
        }
        return Lexer::$_AllExpIds;
    }

    static public function getAllRegex(): Re8map
    {
        if (empty(Lexer::$_AllRegExp)) {
            $kt = new Re8map();
            $kt->setIdRex(Lexer::T_EQUAL, "^(=)");
            $kt->setIdRex(Lexer::T_BOOLEAN, "^(true|false)");
            $kt->setIdRex(Lexer::T_DATE_TIME, "^(\\d{4}-\\d{2}-\\d{2}(T\\d{2}:\\d{2}:\\d{2}(\\.\\d{6})?(Z|-\\d{2}:\\d{2})?)?)");
             $kt->setIdRex(Lexer::T_FLOAT_EXP,"^([+-]?((\\d_?)+([\\.](\\d_?)*)?)([eE][+-]?(_?\\d_?)+))");
            $kt->setIdRex(Lexer::T_FLOAT, "^([+-]?((\\d_?)+([\\.](\\d_?)*)))");
            $kt->setIdRex(Lexer::T_INTEGER, "^([+-]?(\\d_?)+)");
            $kt->setIdRex(Lexer::T_3_QUOTATION_MARK, "^(\"\"\")");
            $kt->setIdRex(Lexer::T_QUOTATION_MARK, "^(\")");
            $kt->setIdRex(Lexer::T_3_APOSTROPHE, "^(\'\'\')");
            $kt->setIdRex(Lexer::T_APOSTROPHE, "^(\')");
            $kt->setIdRex(Lexer::T_HASH, "^(#)");
            $kt->setIdRex(Lexer::T_SPACE, "^(\\h+)");
            $kt->setIdRex(Lexer::T_LEFT_SQUARE_BRACE, "^(\\[)");
            $kt->setIdRex(Lexer::T_RIGHT_SQUARE_BRACE, "^(\\])");
            $kt->setIdRex(Lexer::T_LEFT_CURLY_BRACE, "^(\\{)");
            $kt->setIdRex(Lexer::T_RIGHT_CURLY_BRACE, "^(\\})");
            $kt->setIdRex(Lexer::T_COMMA, "^(,)");
            $kt->setIdRex(Lexer::T_DOT, "^(\\.)");
            $kt->setIdRex(Lexer::T_UNQUOTED_KEY, "^([-A-Z_a-z0-9]+)");
            $kt->setIdRex(
                    Lexer::T_ESCAPED_CHARACTER, "^(\\\\(n|t|r|f|b|\\\"|\\\\|u[0-9A-Fa-f]{4,4}|U[0-9A-Fa-f]{8,8}))");
            // ESCAPE \ would also be caught by LITERAL_STRING
            $kt->setIdRex(Lexer::T_ESCAPE, "^(\\\\)");
            // T_BASIC_UNESCAPED Leaves out " \    (0x22, 0x5C)
            $kt->setIdRex(Lexer::T_BASIC_UNESCAPED, "^([^\\x{0}-\\x{19}\\x{22}\\x{5C}]+)");
            // Literal strings are 'WYSIWYG'
            // Single 'quote' (0x27) is separate fetch.
            $kt->setIdRex(Lexer::T_LITERAL_STRING, "^([^\\x{0}-\\x{19}\\x{27}]+)");


            Lexer::$_AllRegExp = $kt;
        }
        return Lexer::$_AllRegExp;
    }

   
    /**
     * Basic lexer testing of all regular expression parsing
     * {@inheritdoc}
     */
    public function tokenize(string $input): TokenList
    {
        // convert string into array of tokens
        // clone each from the stream

        $stream = new Token8Stream();
        $stream->setExpSet(Lexer::getAllIds());
        $stream->setSingles(Lexer::getAllSingles());
        $stream->setUnknownId(Lexer::T_CHAR);
        $stream->setEOLId(Lexer::T_NEWLINE);
        $stream->setEOSId(Lexer::T_EOS);
        $map = new Re8map();
        $map->addMapIds(Lexer::getAllRegex(), Lexer::getAllIds());
        $stream->setRe8map($map);

        $stream->setInput($input);

        $result = [];

        $token = new Token8(); 
        while ($stream->moveNextId() !== Lexer::T_EOS) {
            $result[] = clone $stream->getToken($token);
        }
        $result[] = clone $stream->getToken($token);
        return new TokenList($result);
    }

    static public function tokenName(int $tokenId): string
    {
        if ($tokenId > Lexer::T_LAST_TOKEN || $tokenId < 0) {
            $tokenId = 0;
        }
        return self::$nameSet[$tokenId];
    }

    static public function getTomlVersion(): string
    {
        return Lexer::TOML_VERSION;
    }

    static public function getUseVersion(): string
    {
        return Lexer::USE_VERSION;
    }

    /** return a KeyTable with expressions selected by $idList
     * @param array $idList
     * @return KeyTable
     */
    static public function getExpSet(array $idList): Re8map
    {
        $all = Lexer::getAllRegex();
        $result = new Re8map();
        $result->addMapIds($all, $idList);
        return $result;
    }

}
