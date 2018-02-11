<?php

/*
 * This file is part of the Yosymfony\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 * AOT - AOTRef additions and  modifications by Michael Rynn <https://github.com/betrixed/toml>
 */

namespace Yosy\Toml;

use Yosy\Table;
use Yosy\TableList;
use Yosy\KeyTable;
use Yosy\TokenStream;
use Yosy\Token;
use Yosy\ValueList;
use Yosy\XArrayable;

/**
 * Tracking information for Arrayable tag property
 */
class PartTag
{

    public $isAOT; // path parsed as AOT this time
    public $objAOT; // object known to be AOT
    public $implicit; // implicit flag

}

/**
 * Parser for TOML strings (specification version 0.4.0).
 *
 * @author Victor Puertas <vpgugr@vpgugr.com>
 */
class Parser
{

    // Waiting for a test case that shows $useKeyStore is needed
    private $useKeyStore = false;
    private $keys = []; //usage controlled by $useKeyStore
    private $currentKeyPrefix = ''; //usage controlled by $useKeyStore
    // array context for key = value
    // parsed result to return
    private $root; // root Table object
    // path string to array context for key = value
    // by convention, is either empty , or set with
    // terminator of '.'
    // 
    private $table; // dyanamic reference to current Table object
    // array of all AOTRef using base name string key
    private $refAOT = []; // deprecating this version now
    //
    private $allPaths = [];  // blend of all registered table paths
    private $pathFull = null;  // instance of last fully specificied path
    // remenber table paths created in passing
    private $implicitTables = []; // array[string] of bool
    public $briefExpressions = [];  // populate from Lexer

    // consts for parser expression tables

    const E_BRIEF = 0;
    const E_FULL = 1;
    const E_LSTRING = 2;
    const E_BSTRING = 3;

    private $ts; // so don't need to pass it around all the time
    private $valueStruct;
    //
    // current expression table type, and stack of previous
    private $expSetId;
    private $expStack = [];
    // key value parse
    public $fullExpressions = [];
    public $basicString = [];
    public $literalString = [];

    public function fromRegexTable(array $ids): KeyTable
    {
        $kt = new KeyTable([]);

        foreach ($ids as $idx) {
            $kt[$idx] = Lexer::$Regex[$idx];
        }
        return $kt;
    }

    /**
     * Set the expression set to the previous on the
     * expression set stack
     */
    public function popExpSet(): void
    {
        $value = array_pop($this->expStack);
        $this->setExpSet($value);
    }

    /**
     * Push a known expression set defined by a 
     * constant
     * @param int $value
     */
    public function pushExpSet(int $value): void
    {
        $this->expStack[] = $this->expSetId;
        $this->setExpSet($value);
    }

    private function setExpSet(int $value)
    {
        $this->expSetId = $value;
        switch ($value) {
            case Parser::E_BRIEF:
                $this->ts->setExpList($this->briefExpressions);
                break;
            case Parser::E_BSTRING:
                $this->ts->setExpList($this->basicString);
                break;
            case Parser::E_LSTRING:
                $this->ts->setExpList($this->literalString);
                break;
            case Parser::E_FULL:

            default:
                $this->ts->setExpList($this->fullExpressions);
                break;
        }
    }

    /*
      public function setupTokenize() {
      $this->regex = & Lexer::$Regex;
      $this->token = new Token();
      }
     * 
     */

    /**
     * Everything that must be setup before calling setInput
     */
    public function __construct()
    {

        $this->root = new Table();
        $this->table = $this->root;

        $this->briefExpressions = $this->fromRegexTable(Lexer::$BriefList);
        $this->fullExpressions = $this->fromRegexTable(Lexer::$FullList);
        $this->basicString = $this->fromRegexTable(Lexer::$BasicStringList);
        $this->literalString = $this->fromRegexTable(Lexer::$LiteralStringList);

        $ts = new TokenStream();
        $ts->setSingles(new KeyTable(Lexer::$Singles));
        $ts->setUnknownId(Lexer::T_CHAR);
        $ts->setNewLineId(Lexer::T_NEWLINE);
        $ts->setEOSId(Lexer::T_EOS);
        $this->ts = $ts; // setExpSet requires this
        // point to the base regexp array
        $this->setExpSet(Parser::E_BRIEF);

        $this->valueStruct = new class() {

            public $value;
            public $type;
        };
    }

    /**
     * Reads string from specified file path and parses it as TOML.
     *
     * @param (string) File path
     *
     * @return (array) Toml::parse() result
     */
    public static function parseFile($path)
    {
        if (!is_file($path)) {
            throw new Exception('Invalid file path');
        }

        $toml = file_get_contents($path);

        // Remove BOM if present
        $toml = preg_replace('/^' . pack('H*', 'EFBBBF') . '/', '', $toml);

        $parser = new Parser(new Lexer());
        return $parser->parse($toml);
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $input): array
    {
        if (preg_match('//u', $input) === false) {
            throw new XArrayable('The TOML input does not appear to be valid UTF-8.');
        }

        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $input = str_replace("\t", ' ', $input);

        // this function does or dies

        $this->ts->setInput($input);

        $this->implementation($this->ts);
        return $this->root->toArray();
    }

    /**
     * {@inheritdoc}
     */
    protected function implementation(TokenStream $ts)
    {
        try {
            $this->resetWorkArrayToResultArray();


            $this->processExpressions($ts);
        } finally {
            foreach ($this->refAOT as $key => $value) {
                $value->unlink();
            }
        }
    }

    /**
     * Process an expression
     *
     * @param TokenStream $ts The token stream
     */
    private function processExpressions(TokenStream $ts): void
    {
        $tokenId = $ts->moveNextId();
        while ($tokenId !== Lexer::T_EOS) {
            //$tokenName = Lexer::tokenName($tokenId);
            switch ($tokenId) {
                case Lexer::T_HASH :
                    $tokenId = $this->parseComment($ts);
                    break;
                case Lexer::T_QUOTATION_MARK:
                case Lexer::T_UNQUOTED_KEY:
                case Lexer::T_APOSTROPHE :
                case Lexer::T_INTEGER :
                    $tokenId = $this->parseKeyValue($ts);
                    break;
                case Lexer::T_LEFT_SQUARE_BRACE:
                    $tokenId = $this->parseTablePath($ts);
                    break;
                case Lexer::T_SPACE :
                case Lexer::T_NEWLINE:
                    // loop again
                    $tokenId = $ts->moveNextId();
                    break;
                default:
                    //TODO: This message is probably outdated by now
                    // Not general enougy, probably to match test cases.
                    $msg = 'Expected T_HASH or T_UNQUOTED_KEY';
                    $this->unexpectedTokenError($ts->getToken(), $msg);
                    break;
            }
        }
    }

    private function duplicateKey(string $keyName)
    {
        $this->syntaxError("The key \"$keyName\" has already been defined previously.");
    }

    /** 
     * Skip comment, and return next none-comment token or NEWLINE or EOS
     *  
     */
    private function parseComment(TokenStream $ts): int
    {
        $tokenId = $ts->getTokenId();
        if ($tokenId != Lexer::T_HASH) {
            $this->throwTokenError($ts->getToken(), $tokenId);
        }
        // parsing a comment so use basic string expression set
        $this->pushExpSet(Parser::E_BSTRING);
        while (true) {
            $tokenId = $ts->moveNextId();
            if ($tokenId === Lexer::T_NEWLINE || $tokenId === Lexer::T_EOS) {
                break;
            }
        }
        $this->popExpSet();
        return $tokenId;
    }

    /**
     * Return next none-space token id
     * @param TokenStream $ts
     * @return int
     */
    private function skipSpace(TokenStream $ts): int
    {
        $tokenId = $ts->getTokenId();
        if ($ts->getTokenId() === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }
        return $tokenId;
    }

    private function parseKeyValue(TokenStream $ts, bool $isFromInlineTable = false): int
    {
        $keyName = $this->parseKeyName($ts);
        if ($this->useKeyStore) {
            $this->mustBeUnique($this->currentKeyPrefix . $keyName);
        } else {
            if ($this->table->offsetExists($keyName)) {
                $this->duplicateKey($keyName);
            }
        }

        // get next none-space token
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }
        if ($tokenId !== Lexer::T_EQUAL) {
            $this->throwTokenError($ts->getToken(), Lexer::T_EQUAL);
        }
        $this->pushExpSet(Parser::E_FULL);

        $tokenId = $ts->moveNextId();//clear EQUAL
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }

        if ($tokenId === Lexer::T_LEFT_SQUARE_BRACE) {
            $this->table[$keyName] = $this->parseArray($ts);
        } elseif ($tokenId === Lexer::T_LEFT_CURLY_BRACE) {
            $this->parseInlineTable($ts, $keyName);
        } else {
            $this->table[$keyName] = $this->parseSimpleValue($ts)->value;
        }
        $this->popExpSet();
        $tokenId = $ts->moveNextId(); // clear value parse ends
        if (!$isFromInlineTable) {
            return $this->finishLine($ts);
        } else {
            return $tokenId;
        }
    }

    private function parseKeyName(TokenStream $ts, bool $stripQuote = true): string
    {
        $tokenId = $ts->getTokenId();
        switch ($tokenId) {
            case Lexer::T_UNQUOTED_KEY:
                return $ts->getValue();
            case Lexer::T_QUOTATION_MARK:
                return $this->parseBasicString($ts, $stripQuote);
            case Lexer::T_APOSTROPHE:
                return $this->parseLiteralString($ts, $stripQuote);
            case Lexer::T_INTEGER :
                return (string) $this->parseInteger($ts);
            default:
                $this->unexpectedTokenError($ts->getToken(), 'Unexpected token in parseKeyName');
                break;
        }
    }

    /**
     * @return object An object with two public properties: value and type.
     * Returned object must be cloned to keep values of returned instance.
     */
    private function parseSimpleValue(TokenStream $ts)
    {
        // reuse same instance
        $token = $ts->getTokenId();
        $v = $this->valueStruct;
        switch ($token) {
            case Lexer::T_BOOLEAN:
                $v->value = $this->parseBoolean($ts);
                $v->type = 'boolean';
                break;
            case Lexer::T_INTEGER:
                $v->value = $this->parseInteger($ts);
                $v->type = 'integer';
                break;
            case Lexer::T_FLOAT:
                $v->value = $this->parseFloat($ts);
                $v->type = 'float';
                break;
            case Lexer::T_QUOTATION_MARK:
                $v->value = $this->parseBasicString($ts);
                $v->type = 'string';
                break;
            case Lexer::T_3_QUOTATION_MARK:
                $v->value = $this->parseMultilineBasicString($ts);
                $v->type = 'string';
                break;
            case Lexer::T_APOSTROPHE:
                $v->value = $this->parseLiteralString($ts);
                $v->type = 'string';
                break;
            case Lexer::T_3_APOSTROPHE:
                $v->value = $this->parseMultilineLiteralString($ts);
                $v->type = 'string';
                break;
            case Lexer::T_DATE_TIME:
                $v->value = $this->parseDatetime($ts);
                $v->type = 'datetime';
                break;
            default:
                $this->unexpectedTokenError($ts->getToken(), 'Expected boolean, integer, long, string or datetime.'
                );
                break;
        }
        return $v;
    }

    private function parseBoolean(TokenStream $ts): bool
    {
        return $ts->getValue() == 'true' ? true : false;
    }

    private function parseInteger(TokenStream $ts): int
    {
        $value = $ts->getValue();

        if (preg_match('/([^\d]_[^\d])|(_$)/', $value)) {
            $this->syntaxError(
                    'Invalid integer number: underscore must be surrounded by at least one digit.', $ts->getToken()
            );
        }

        $value = str_replace('_', '', $value);

        if (preg_match('/^0\d+/', $value)) {
            $this->syntaxError(
                    'Invalid integer number: leading zeros are not allowed.', $ts->getToken()
            );
        }

        return (int) $value;
    }

    private function parseFloat(TokenStream $ts): float
    {
        $value = $ts->getValue();

        if (preg_match('/([^\d]_[^\d])|_[eE]|[eE]_|(_$)/', $value)) {
            $this->syntaxError(
                    'Invalid float number: underscore must be surrounded by at least one digit.', $ts->getToken()
            );
        }

        $value = str_replace('_', '', $value);

        if (preg_match('/^0\d+/', $value)) {
            $this->syntaxError(
                    'Invalid float number: leading zeros are not allowed.', $ts->getToken()
            );
        }

        return (float) $value;
    }

    /** In path parsing, we may want to keep quotes, because they can be used
     *  to enclose a '.' as a none separator. 
     * @param TokenStream $ts
     * @param type $stripQuote
     * @return string
     */
    private function parseBasicString(TokenStream $ts, $stripQuote = true): string
    {
        $this->pushExpSet(Parser::E_BSTRING);
        
        $tokenId = $ts->getTokenId();
        if ($tokenId !== Lexer::T_QUOTATION_MARK) {
            $this->throwTokenError($this->getToken(), Lexer::T_QUOTATION_MARK);
        }
        $result = $stripQuote ? '' : "\"";

        $tokenId = $ts->moveNextId();
        while ($tokenId !== Lexer::T_QUOTATION_MARK) {
            if (($tokenId === Lexer::T_NEWLINE) || ($tokenId === Lexer::T_EOS) || ($tokenId
                    === Lexer::T_ESCAPE)) {
                // throws
                $this->unexpectedTokenError($ts->getToken(), 'This character is not valid.');
            } elseif ($tokenId === Lexer::T_ESCAPED_CHARACTER) {
                $value = $this->parseEscapedCharacter($ts);
            } else {
                $value = $ts->getValue();
            }
            $result .= $value;
            $tokenId = $ts->moveNextId();
        }
        $this->popExpSet();
        if (!$stripQuote) {
            $result .= "\"";
        }
        return $result;
    }

    private function parseMultilineBasicString(TokenStream $ts): string
    {
        // TODO: inline assert can be dropped in final version
        if (($ts->getTokenId() !== Lexer::T_3_QUOTATION_MARK)) {
            $this->throwTokenError($ts->getToken(), Lexer::T_3_QUOTATION_MARK);
        }
        $this->pushExpSet(Parser::E_BSTRING);

        $result = '';
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
        }
        while (true) {
            switch ($tokenId) {
                case Lexer::T_3_QUOTATION_MARK :
                    $this->popExpSet();
                    break 2;
                case Lexer::T_EOS:
                    $this->throwTokenError($ts->getToken(), Lexer::T_3_QUOTATION_MARK);
                    break;
                case Lexer::T_ESCAPE:
                    do {
                        $tokenId = $ts->moveNextId();
                    } while (($tokenId === Lexer::T_SPACE) || ($tokenId === Lexer::T_NEWLINE) || ($tokenId
                    === Lexer::T_ESCAPE));
                    break;
                case Lexer::T_SPACE:
                    $result .= ' '; // reduce to single space
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_NEWLINE:
                    $result .= "\n";
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_ESCAPED_CHARACTER:
                    $result .= $this->parseEscapedCharacter($ts);
                    $tokenId = $ts->moveNextId();
                    break;
                default:
                    $result .= $ts->getValue();
                    $tokenId = $ts->moveNextId();
                    break;
            }
        }
        return $result;
    }

    /**
     * 
     * @param TokenStream $ts
     * @param bool $stripQuote
     * @return string
     */
    private function parseLiteralString(TokenStream $ts, bool $stripQuote = true): string
    {
        if ($ts->getTokenId() !== Lexer::T_APOSTROPHE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_APOSTROPHE);
        }

        $this->pushExpSet(Parser::E_LSTRING);


        $result = $stripQuote ? '' : "'";
        $tokenId = $ts->moveNextId();

        while ($tokenId !== Lexer::T_APOSTROPHE) {
            if (($tokenId === Lexer::T_NEWLINE) || ($tokenId === Lexer::T_EOS)) {
                $this->unexpectedTokenError($ts->getToken(), 'This character is not valid.');
            }
            $result .= $ts->getValue();
            $tokenId = $ts->moveNextId();
        }
        if (!$stripQuote) {
            $result .= "'";
        }
        $this->popExpSet();
        return $result;
    }

    private function parseMultilineLiteralString(TokenStream $ts): string
    {
        if ($ts->getTokenId() !== Lexer::T_3_APOSTROPHE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_3_APOSTROPHE);
        }
        $this->pushExpSet(Parser::E_LSTRING);


        $result = '';

        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
        }

        while (true) {

            if ($tokenId === Lexer::T_3_APOSTROPHE) {
                break;
            }
            if ($tokenId === Lexer::T_EOS) {
                $this->unexpectedTokenError($ts->getToken(), 'Expected token "T_3_APOSTROPHE".');
            }
            $result .= $ts->getValue();
            $tokenId = $ts->moveNextId();
        }
        $this->popExpSet();

        if ($tokenId !== Lexer::T_3_APOSTROPHE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_3_APOSTROPHE);
        }

        return $result;
    }

    private function parseEscapedCharacter(TokenStream $ts): string
    {
        $value = $ts->getValue();

        switch ($value) {
            case '\b':
                return "\b";
            case '\t':
                return "\t";
            case '\n':
                return "\n";
            case '\f':
                return "\f";
            case '\r':
                return "\r";
            case '\"':
                return '"';
            case '\\\\':
                return '\\';
        }

        if (strlen($value) === 6) {
            return json_decode('"' . $value . '"');
        }

        preg_match('/\\\U([0-9a-fA-F]{4})([0-9a-fA-F]{4})/', $value, $matches);

        return json_decode('"\u' . $matches[1] . '\u' . $matches[2] . '"');
    }

    private function parseDatetime(TokenStream $ts): \Datetime
    {
        $date = $ts->getValue();
        return new \Datetime($date);
    }

    /**
     * Recursive call of itself.
     * @param \Yosymfony\Toml\TokenStream $ts
     * @return array
     */
    private function parseArray(TokenStream $ts): ValueList
    {
        $tokenId = $ts->getTokenId();
        if ($tokenId != Lexer::T_LEFT_SQUARE_BRACE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_LEFT_SQUARE_BRACE);
        }
        $result = new ValueList();
        $tokenId = $ts->moveNextId();
        while ($tokenId === Lexer::T_SPACE || $tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
        }
        if ($tokenId === Lexer::T_HASH) {
            $tokenId = $this->parseCommentsAndSpace($ts);
        }
            
        while ($tokenId !== Lexer::T_RIGHT_SQUARE_BRACE) {
            if ($tokenId === Lexer::T_LEFT_SQUARE_BRACE) {
                $result[] = $this->parseArray($ts);
            } else {
                // Returned value is a singular class instance to pass parameters
                $result[] = $this->parseSimpleValue($ts)->value;
            }
            $tokenId = $ts->moveNextId();
            while ($tokenId === Lexer::T_SPACE || $tokenId === Lexer::T_NEWLINE) {
                $tokenId = $ts->moveNextId();
            }

             if ($tokenId === Lexer::T_HASH) {
                $tokenId = $this->parseCommentsAndSpace($ts);
            }
            
            if ($tokenId === Lexer::T_COMMA) {
                //easy, to another value
                $tokenId = $ts->moveNextId();
            }
            elseif ($tokenId !== Lexer::T_RIGHT_SQUARE_BRACE) {
                // should be finished
                $this->unexpectedTokenError($ts->getToken(),"Expect '.' or ']' after array item");
            }

            while ($tokenId === Lexer::T_SPACE || $tokenId === Lexer::T_NEWLINE) {
                $tokenId = $ts->moveNextId();
            }

            if ($tokenId === Lexer::T_HASH) {
                $tokenId = $this->parseCommentsAndSpace($ts);
            }
        }
        if ($ts->getTokenId() != Lexer::T_RIGHT_SQUARE_BRACE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_RIGHT_SQUARE_BRACE);
        }

        return $result;
    }

    /**
     * Used by parseInlineTable, to push a new Table as a value
     *  and set current work table to it.
     * @param string $keyName
     */
    private function pushWorkTable(string $keyName): void
    {
        // TODO: Else or Assert??
        $work = $this->table;
        if ($work->offsetExists($keyName) === false) {
            $work[$keyName] = new Table();
        }
        // TODO: Else or Assert??

        $this->table = $work[$keyName];
    }

    private function parseInlineTable(TokenStream $ts, string $keyName): void
    {
        if ($ts->getTokenId() !== Lexer::T_LEFT_CURLY_BRACE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_LEFT_CURLY_BRACE);
        }
        $this->pushExpSet(Parser::E_BRIEF); // looking for keys

        $priorTable = $this->table;

        $this->pushWorkTable($keyName);

        if ($this->useKeyStore) {
            $priorcurrentKeyPrefix = $this->currentKeyPrefix;
            $this->currentKeyPrefix = $this->currentKeyPrefix . $keyName . ".";
        }
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }

        if ($tokenId !== Lexer::T_RIGHT_CURLY_BRACE) {
            $tokenId = $this->parseKeyValue($ts, true);
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
        }

        while ($tokenId === Lexer::T_COMMA) {
            $tokenId = $ts->moveNextId();
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
            $tokenId = $this->parseKeyValue($ts, true);
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
        }
        $this->popExpSet();
        if ($tokenId !== Lexer::T_RIGHT_CURLY_BRACE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_RIGHT_CURLY_BRACE);
        }

        if ($this->useKeyStore) {
            $this->currentKeyPrefix = $priorcurrentKeyPrefix;
        }
        $this->table = $priorTable;
    }

    private function parseKeyPath(TokenStream $ts)
    {
        $fullTablePath = [];
        $fullTablePath[] = $this->parseKeyName($ts);
        $tokenId = $ts->getTokenId();
        while ($tokenId === Lexer::T_DOT) {
            $ts->moveNextId();
            $fullTablePath[] = $this->parseKeyName($ts);
            $tokenId = $ts->getTokenId();
        }
        return $fullTablePath;
    }

    /**
     * Nothing more of interest on the line,
     * anything besides a comment is an error
     * Used by parseObjectPath and parseKeyValue
     */
    private function finishLine(TokenStream $ts): int
    {
        $tokenId = $ts->getTokenId();
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }
        if ($tokenId === Lexer::T_HASH) {
            $this->parseComment($ts);
            $tokenId = $ts->getTokenId();  // where are we now?
        }
        if ($tokenId !== Lexer::T_NEWLINE && $tokenId !== Lexer::T_EOS) {
            $this->unexpectedTokenError($ts->getToken(), 'Expected T_NEWLINE or T_EOS.');
        }
        return $tokenId;
    }

    private function tablePathError($msg, Token $token = null)
    {
        if (!is_null($token)) {
            $msg .= ", Line " . $token->line;
        }
        throw new XArrayable($msg);
    }

    private function tablePathClash($orig, $pf)
    {
        $msg = "Table path [" . $pf->key . "] at line " . $pf->line
                . " interferes with path at line " . $orig->line;
        throw new XArrayable($msg);
    }

    /**
     * Convert the path string, into the array with the path of
     * Table and TableList objects indicated.
     * @param TokenStream $ts
     */
    private function parseObjectPath(TokenStream $ts)
    {
        $isAOT = false;
        $parts = []; // collect the objects
        $partsCt = 0;
        $partName = ''; // collect the path string
        $dotCount = 0;
        $hasAOT = false;
        $hasTables = false;
        $AOTLength = 0;
        $pobj = $this->root;
        $hitNew = false;
        $firstNew = -1;
        $testObj = null;

        $pathToken = $ts->getToken();
        $tokenId = $pathToken->id;
        if ($tokenId != Lexer::T_LEFT_SQUARE_BRACE) {
            $this->tablePathError("Path start [ expected", $pathToken);
        }
        $tokenId = $ts->moveNextId();
        while (true) {
            switch ($tokenId) {
                case Lexer::T_HASH:
                    $this->tablePathError("Unexpected '#' in path", $ts->getToken());
                    break;
                case Lexer::T_EQUAL:
                    $this->tablePathError("Unexpected '=' in path", $ts->getToken());
                    break;
                case Lexer::T_SPACE:
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_EOS:
                    break 2;
                case Lexer::T_NEWLINE:
                    $this->tablePathError("New line in unfinished path", $ts->getToken());
                    break;
                case Lexer::T_RIGHT_SQUARE_BRACE:

                    if ($isAOT) {
                        if ($AOTLength == 0) {
                            $this->tablePathError("AOT Segment cannot be empty", $ts->getToken());
                        }
                        $isAOT = false;
                        $AOTLength = 0;
                        $tokenId = $ts->moveNextId();
                        break;
                    } else {
                        $tokenId = $ts->moveNextId();
                        break 2;
                    }
                case Lexer::T_LEFT_SQUARE_BRACE:
                    if ($dotCount < 1 && count($parts) > 0) {
                        $this->tablePathError("Expected a '.' after path key", $ts->getToken());
                    }
                    if ($isAOT) {
                        // one too many
                        $this->tablePathError("Too many consecutive [ in path", $ts->getToken());
                    }
                    $tokenId = $ts->moveNextId();
                    $isAOT = true;

                    break;
                case Lexer::T_DOT;

                    if ($dotCount === 1) {
                        $this->tablePathError("Found '..' in path", $ts->getToken());
                    }
                    $dotCount += 1;
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_QUOTATION_MARK:
                default:
                    $partKey = $this->parseKeyName($ts);
                    $pathSep = $partsCt > 0 ? '.' : '';

                    if (!$isAOT) {
                        $hasTables = true;
                        $section = $partKey;
                    } else {
                        $section = '[' . $partKey . ']';
                        $hasAOT = true;
                        $AOTLength++;
                    }
                    if ($dotCount < 1 && count($parts) > 0) {
                        $this->tablePathError("Expected a '.' after path key", $ts->getToken());
                    }
                    $dotCount = 0;
                    // do we have everything we need to know?
                    $partName .= $pathSep . $section;

                    // does this object exist already?
                    $testObj = $pobj->offsetExists($partKey) ? $pobj[$partKey] : null;
                    if (is_null($testObj)) {
                        if (!$hitNew) {
                            $hitNew = true;
                            $firstNew = $partsCt;
                        }
                        $tag = new PartTag();
                        $tag->isAOT = $isAOT;

                        if ($isAOT) {
                            $testObj = new TableList();
                            // store TableList as part
                            $pobj[$partKey] = $testObj;
                            $pobj = $testObj->getEndTable();
                            $tag->objAOT = true;
                        } else {
                            $testObj = new Table();
                            $pobj[$partKey] = $testObj;
                            $pobj = $testObj;
                            $tag->objAOT = false;
                        }
                        $testObj->setTag($tag);
                    } else {
                        // path exists, must be Arrayable
                        $preMade = ($testObj instanceof \Yosy\Arrayable);
                        if (!$preMade) {
                            throw new XArrayable('Duplicate key path: ' . $partName . ' line ' . $pathToken->line);
                        }
                        $tag = $testObj->getTag();
                        $tag->isAOT = $isAOT;
                        if ($tag->objAOT) {
                            $pobj = $testObj->getEndTable();
                        } else { // found a Table object
                            $pobj = $testObj;
                        }
                    }
                    $parts[] = $testObj; // Table or TableList
                    $partsCt++;
                    $tokenId = $ts->moveNextId();
                    break;
            }
        }
        if (!$hasTables && !$hasAOT) {
            $this->tablePathError('Table path cannot be empty at line ' . $pathToken->line);
        }

        if (!$hitNew) {
            $tag = $testObj->getTag();
            if ($tag->objAOT) {
                if ($tag->isAOT) {
                    $pobj = $testObj->newTable();
                } else {
                    throw new XArrayable('Path mismatch at ' . $partName . ' line ' . $pathToken->line);
                }
            } else {
                // terminates in reused table name?
                // OK if end part was implicit              
                if ($tag->implicit) {
                    // last part no longer implicit
                    $tag->implicit = false;
                } else {
                    throw new XArrayable('Duplicate key path: [' . $partName . '] line ' . $pathToken->line);
                }
            }
        } else {
            // all the parts from the $firstNew and before the last part
            // were created 'implicitly', so use the tag property to store
            // implicit flag
            $lastIdx = count($parts) - 1;
            for ($i = $firstNew; $i < $lastIdx; $i++) {
                $parts[$i]->getTag()->implicit = true;
            }
        }

        $this->table = $pobj;
        $this->currentKeyPrefix = $partName;
    }

    /**
     * @param TokenStream $ts
     * For mixed AOT and table paths, some rules to be followed, or else.
     * For existing paths, a new AOT-T is created if end of path is AOT
     * New path dynamic AOT segments always create an initial AOT-T
     * Existing AOT segments ended with a Table Path are unaltered.
     * 
     * Traverse the path parts and adjust workArray
     * 
     * Would be nice to have a token that says "I am relative" to last path,
     * //TODO: Try relative paths and replacement. Means more tokens to LEXER
     * If first character is a "plus" + , it extends and replaces last path
     * If first character is a "minus" - , it extends last path without replacement
     * 
     */
    private function parseTablePath(TokenStream $ts): int
    {
        $this->parseObjectPath($ts);

        return $this->finishLine($ts);
    }

    private function throwTokenError($token, int $expectedId)
    {
        $tokenName = Lexer::tokenName($expectedId);
        $this->unexpectedTokenError($token, "Expected $tokenName");
    }

    private function parseCommentIfExists(TokenStream $ts): void
    {
        if ($ts->getTokenId() === Lexer::T_HASH) {
            $this->parseComment($ts);
        }
    }

    private function parseSpaceIfExists(TokenStream $ts): void
    {
        if ($ts->getTokenId() === Lexer::T_SPACE) {
            $ts->moveNext();
        }
    }

    /**
     * Return the next tokenId, after skipping any comments and space
     * @param TokenStream $ts
     */
    private function parseCommentsAndSpace(TokenStream $ts): int
    {
        $tokenId = $ts->getTokenId();
        if ($tokenId === Lexer::T_HASH) {
            $tokenId = $this->parseComment($ts);
        }
        while ($tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
            if ($tokenId === Lexer::T_HASH) {
                $tokenId = $this->parseComment($ts);
            }
        }
        return $tokenId;
    }

    private function errorUniqueKey($keyName)
    {
        $this->syntaxError(sprintf(
                        'The key { %s } has already been defined previously.', $keyName
        ));
    }

    /**
     * Runtime check on uniqueness of key
     * Usage is controller by $this->useKeyStore
     * @param string $keyName
     */
    private function mustBeUnique(string $keyName)
    {
        if (!$this->setUniqueKey($keyName)) {
            $this->errorUniqueKey($keyName);
        }
    }

    /**
     * Return true if key was already set
     * Usage controlled by $this->useKeyStore
     * @param string $keyName
     * @return bool
     */
    private function setUniqueKey(string $keyName): bool
    {
        if (isset($this->keys[$keyName])) {
            return false;
        }
        $this->keys[$keyName] = true;
        return true;
    }

    private function resetWorkArrayToResultArray(): void
    {
        if ($this->useKeyStore) {
            $this->currentKeyPrefix = '';
        }
        $this->table = $this->root;
    }

    private function unexpectedTokenError(Token $token, string $expectedMsg): void
    {
        $name = Lexer::tokenName($token->id);
        $line = $token->line;
        $value = $token->value;

        $msg = sprintf('Syntax error: unexpected token %s at line %s', $name, $line);

        if (!$token->isSingle) {
            $msg .= " value { '" . $value . "' }. ";
        } else {
            $msg .= '.';
        }
        if (!empty($expectedMsg)) {
            $msg = $msg . ' ' . $expectedMsg;
        }

        throw new XArrayable($msg);
    }

    private function syntaxError($msg, Token $token = null): void
    {
        if ($token !== null) {
            $name = Lexer::tokenName($token->id);
            $line = $token->line;
            $value = $token->value;
            $tokenMsg = sprintf('Token: "%s" line: %s', $name, $line);
            if (!$token->isSingle) {
                $tokenMsg .= " value { '" . $value . "' }.";
            } else {
                $tokenMsg .= '.';
            }
            $msg .= ' ' . $tokenMsg;
        }
        throw new XArrayable($msg);
    }

}
