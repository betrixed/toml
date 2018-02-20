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
    const T_LITERAL_STRING = 24;
    const T_CHAR = 25;
    const T_LAST_TOKEN = 25; // for range values of token lookup
    
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
        'T_FLOAT', //22
        'T_HASH', //23
        'T_LITERAL_STRING', //24
        'T_CHAR', // 25
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
    static private $_AllRegExp;
    static private $_AllSingles;
    
    
    static public function getAllSingles(): KeyTable {
        if (empty(Lexer::$_AllSingles)) {
            $kt = new KeyTable();
            $kt->offsetSet("=",Lexer::T_EQUAL);
            $kt->offsetSet("[",Lexer::T_LEFT_SQUARE_BRACE);
            $kt->offsetSet("]",Lexer::T_RIGHT_SQUARE_BRACE);
            $kt->offsetSet(".",Lexer::T_DOT);
            $kt->offsetSet(",",Lexer::T_COMMA);
            $kt->offsetSet("\"",Lexer::T_QUOTATION_MARK);
            $kt->offsetSet(".",Lexer::T_DOT);
            $kt->offsetSet("{",Lexer::T_LEFT_CURLY_BRACE);
            $kt->offsetSet("}",Lexer::T_RIGHT_CURLY_BRACE);
            $kt->offsetSet("'",Lexer::T_APOSTROPHE);
            $kt->offsetSet("#",Lexer::T_HASH);
            $kt->offsetSet("\\",Lexer::T_ESCAPE);
            Lexer::$_AllSingles = $kt;
        }
        return Lexer::$_AllSingles;
    }
    static public function getAllRegex() : KeyTable{
        if (empty(Lexer::$_AllRegExp)) {
            $kt = new KeyTable();
            $kt->offsetSet(Lexer::T_EQUAL,"/^(=)/");
            $kt->offsetSet(Lexer::T_BOOLEAN,"/^(true|false)/");
            $kt->offsetSet(Lexer::T_DATE_TIME,"/^(\\d{4}-\\d{2}-\\d{2}(T\\d{2}:\\d{2}:\\d{2}(\\.\\d{6})?(Z|-\\d{2}:\\d{2})?)?)/");
            $kt->offsetSet(Lexer::T_FLOAT,"/^([+-]?((((\\d_?)+[\\.]?(\\d_?)*)[eE][+-]?(\\d_?)+)|((\\d_?)+[\\.](\\d_?)+)))/");
            $kt->offsetSet(Lexer::T_INTEGER,"/^([+-]?(\\d_?)+)/");
            $kt->offsetSet(Lexer::T_3_QUOTATION_MARK,"/^(\"\"\")/");
            $kt->offsetSet(Lexer::T_QUOTATION_MARK,"/^(\")/");
            $kt->offsetSet(Lexer::T_3_APOSTROPHE,"/^(\'\'\')/");
            $kt->offsetSet(Lexer::T_APOSTROPHE,"/^(\')/");
            $kt->offsetSet(Lexer::T_HASH,"/^(#)/");
            $kt->offsetSet(Lexer::T_SPACE,"/^(\\s+)/");
            $kt->offsetSet(Lexer::T_LEFT_SQUARE_BRACE,"/^(\\[)/");
            $kt->offsetSet(Lexer::T_RIGHT_SQUARE_BRACE,"/^(\\])/");
            $kt->offsetSet(Lexer::T_LEFT_CURLY_BRACE,"/^(\\{)/");
            $kt->offsetSet(Lexer::T_RIGHT_CURLY_BRACE,"/^(\\})/");
            $kt->offsetSet(Lexer::T_COMMA,"/^(,)/");
            $kt->offsetSet(Lexer::T_DOT,"/^(\\.)/");
            $kt->offsetSet(Lexer::T_UNQUOTED_KEY,"/^([-A-Z_a-z0-9]+)/");
            $kt->offsetSet(Lexer::T_ESCAPED_CHARACTER,
                    "/^\\\\(n|t|r|f|b|\\\\|\\\"|u[0-9AaBbCcDdEeFf]{4,4}|U[0-9AaBbCcDdEeFf]{8,8})/");
            $kt->offsetSet(Lexer::T_ESCAPE,"/^(\\\\)/");
            $kt->offsetSet(Lexer::T_BASIC_UNESCAPED,
                    "/^([\\x{20}-\\x{21}\\x{23}-\\x{26}\\x{28}-\\x{5A}\\x{5E}-\\x{10FFFF}]+)/u");
            
            // Literal strings are 'WYSIWYG', any printable character including # and " 
            // This is superset of T_BASIC_UNESCAPED. Single quote is separate fetch.
            // The only disallowed characters are control characters. 
            $kt->offsetSet(Lexer::T_LITERAL_STRING,
                    "/^([\\x{20}-\\x{26}\\x{28}-\\x{10FFFF}]+)/u");
            Lexer::$_AllRegExp = $kt;
        }
        return Lexer::$_AllRegExp;
    }
    // php retains an array insertion order, so order of these is signficant, 
    // and if it wasn't , it would need to be enforced by iterating these directly
    static public $BriefList = [
        Lexer::T_SPACE,
        Lexer::T_UNQUOTED_KEY,
        Lexer::T_INTEGER,
    ];
    static public $LiteralString = [
        Lexer::T_LITERAL_STRING
    ];
    static public $BasicString = [
        Lexer::T_SPACE, Lexer::T_BASIC_UNESCAPED, Lexer::T_ESCAPED_CHARACTER, Lexer::T_3_QUOTATION_MARK,
    ];
    static public $LiteralMLString = [
        Lexer::T_LITERAL_STRING, Lexer::T_3_APOSTROPHE,
    ];
    // order is important, since T_INTEGER if first, will gazump T_FLOAT, T_DATE_TIME
    static public $FullList = [
        Lexer::T_SPACE, Lexer::T_BOOLEAN, Lexer::T_DATE_TIME, Lexer::T_FLOAT, Lexer::T_INTEGER, 
        Lexer::T_3_QUOTATION_MARK, Lexer::T_3_APOSTROPHE,
        Lexer::T_UNQUOTED_KEY
    ];

    /**
     * Basic lexer testing of all regular expression parsing
     * {@inheritdoc}
     */
    public function tokenize(string $input): TokenList
    {
        // convert string into array of tokens
        // clone each from the stream

        $stream = new TokenStream();
        $stream->setExpList(Lexer::getAllRegex());
        $stream->setSingles(Lexer::getAllSingles());
        $stream->setUnknownId(Lexer::T_CHAR);
        $stream->setNewLineId(Lexer::T_NEWLINE);
        $stream->setEOSId(Lexer::T_EOS);
        $stream->setInput($input);

        $result = [];

        while ($stream->moveNextId() !== Lexer::T_EOS) {
            $result[] = clone $stream->getToken();
        }
        $result[] = clone $stream->getToken();
        return new TokenList($result);
    }

    static public function tokenName(int $tokenId): string
    {
        if ($tokenId > Lexer::T_LAST_TOKEN || $tokenId < 0) {
            $tokenId = 0;
        }
        return self::$nameSet[$tokenId];
    }
    
    static public function getTomlVersion() : string {
        return Lexer::TOML_VERSION;
    }
    
    static public function getUseVersion() : string {
        return Lexer::USE_VERSION;
    }
    /** return a KeyTable with expressions selected by $idList
     * 
     * @param array $idList
     * @return KeyTable
     */
    static public function getExpSet(array $idList) {
        $all = Lexer::getAllRegex();
        
        $result = new KeyTable();
        foreach($idList as $id) {
            $result->offsetSet($id, $all->offsetGet($id));
        }
        return $result;
    }
}
