<?php

/*
 * This file is part of the Yosymfony\Toml package.
 *
 * (c) YoSymfony <http://github.com/yosymfony>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 * 
 *  Michael Rynn <https://github.com/betrixed/toml>
 *  Modified as preparation for attempt at Zephir Version. 
 */

namespace Toml;

/**
 * Parser for TOML strings (specification version 0.4.0).
 *
 * @author Victor Puertas <vpgugr@vpgugr.com>
 */
class Parser
{

    // consts for parser expression tables

    const E_KEY = 0;
    const E_VALUE = 1;
    const E_LSTRING = 2;
    const E_BSTRING = 3;
    const E_ALL = 4;

    private $root; // root Table object
    private $table; // dyanamic reference to current Table object
    // For regex table stack
    private $ts;
    // Stack of regex sets as KeyTable
    private $expSetId;
    private $expStack;
    private $stackTop;
    // key value regular expression sets (KeyTable)
    static private $keyRegex;
    static private $valRegex;
    static private $regBasic;
    static private $regLiteral;

    /**
     * Set the expression set to the previous on the
     * expression set stack
     */
    public function popExpSet(): void
    {
        $stack = $this->expStack;
        $top = $this->stackTop;
        if ($top > 0) {
            $top -= 1;
            $value = $stack->offsetGet($top);
            $this->setExpSet($value);
            $this->stackTop = $top;
            return;
        }
        throw new XArrayable("popExpSet on empty stack");
    }

    /**
     * Push a known expression set defined by a 
     * constant
     * @param int $value
     */
    public function pushExpSet(int $value): void
    {
        $stack = $this->expStack;
        $top = (int) $this->stackTop; // top is insertion index
        $ct = $stack->getSize();
        if ($ct <= $top) {
            // expand
            $stack->setSize($top + 16);
        }
        $stack->offsetSet($top, $this->expSetId);
        $this->stackTop = $top + 1;
        $this->setExpSet($value);
    }

    public static function getExpSet(int $value): KeyTable
    {
        switch ($value) {
            case Parser::E_KEY:
                $result = Parser::$keyRegex;
                if (empty($result)) {
                    $result = Lexer::getExpSet(Lexer::$BriefList);
                    Parser::$keyRegex = $result;
                }
                break;
            case Parser::E_BSTRING:
                $result = Parser::$regBasic;
                if (empty($result)) {
                    $result = Lexer::getExpSet(Lexer::$BasicStringList);
                    Parser::$regBasic = $result;
                }
                break;
            case Parser::E_LSTRING:
                $result = Parser::$regLiteral;
                if (empty($result)) {
                    $result = Lexer::getExpSet(Lexer::$LiteralStringList);
                    Parser::$regLiteral = $result;
                }
                break;
            case Parser::E_VALUE:
                $result = Parser::$valRegex;
                if (empty($result)) {
                    $result = Lexer::getExpSet(Lexer::$FullList);
                    Parser::$valRegex = $result;
                }
                break;
            case Parser::E_ALL:
                $result = Lexer::getAllRegex();
                break;
            default:
                throw new XArrayable("Not a defined table constant for getExpSet");
        }
        return $result;
    }

    private function setExpSet(int $value): void
    {
        $this->expSetId = $value;
        switch ($value) {
            case Parser::E_KEY:
                $this->ts->setExpList(Parser::$keyRegex);
                break;
            case Parser::E_BSTRING:
                $this->ts->setExpList(Parser::$regBasic);
                break;
            case Parser::E_LSTRING:
                $this->ts->setExpList(Parser::$regLiteral);
                break;
            case Parser::E_VALUE:
            default:
                $this->ts->setExpList(Parser::$valRegex);
                break;
        }
    }

    public function __construct()
    {

        $this->root = new KeyTable();
        $this->table = $this->root;

        $this->expStack = new \SplFixedArray();
        $this->stackTop = 0;

        Parser::$keyRegex = $this->getExpSet(Parser::E_KEY);
        Parser::$valRegex = $this->getExpSet(Parser::E_VALUE);
        Parser::$regBasic = $this->getExpSet(Parser::E_BSTRING);
        Parser::$regLiteral = $this->getExpSet(Parser::E_LSTRING);

        $ts = new TokenStream();
        $ts->setSingles(Lexer::getAllSingles());
        $ts->setUnknownId(Lexer::T_CHAR);
        $ts->setNewLineId(Lexer::T_NEWLINE);
        $ts->setEOSId(Lexer::T_EOS);
        $this->ts = $ts; // setExpSet requires this
        // point to the base regexp array
        $this->setExpSet(Parser::E_KEY);
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

    private function prepareInput(string $input): void
    {
        if (preg_match("//u", $input) === false) {
            throw new XArrayable('The TOML input does not appear to be valid UTF-8.');
        }

        $input = str_replace(["\r\n", "\r"], "\n", $input);
        $input = str_replace("\t", " ", $input);

        // this function does or dies

        $this->ts->setInput($input);
    }

    public function getRoot(): KeyTable
    {
        return $this->root;
    }

    /**
     * {@inheritdoc}
     */
    public function parse(string $input): array
    {
        $this->prepareInput($input);
        $this->implementation($this->ts);
        return $this->root->toArray();
    }

    /**
     * Process all tokens until T_EOS
     * @param TokenStream $ts
     */
    private function implementation(TokenStream $ts): void
    {
        $this->table = $this->root;
        $tokenId = $ts->moveNextId();
        while ($tokenId !== Lexer::T_EOS) {
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
                    $this->unexpectedTokenError($ts->getToken(), 'Expect Key = , [Path] or # Comment');
                    break;
            }
        }
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
        if ($this->table->offsetExists($keyName)) {
            $this->errorUniqueKey($keyName);
        }

        // get next none-space token
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }
        if ($tokenId !== Lexer::T_EQUAL) {
            $this->throwTokenError($ts->getToken(), Lexer::T_EQUAL);
        }
        $this->pushExpSet(Parser::E_VALUE);

        $tokenId = $ts->moveNextId(); //clear EQUAL
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }

        if ($tokenId === Lexer::T_LEFT_SQUARE_BRACE) {
            $this->table[$keyName] = $this->parseArray($ts);
        } elseif ($tokenId === Lexer::T_LEFT_CURLY_BRACE) {
            $this->parseInlineTable($ts, $keyName);
        } else {
            $this->table[$keyName] = $this->parseSimpleValue($ts);
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
                $this->unexpectedTokenError($ts->getToken(), 'Improper key');
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

        switch ($token) {
            case Lexer::T_BOOLEAN:
                return $this->parseBoolean($ts);
            case Lexer::T_INTEGER:
                return $this->parseInteger($ts);
            case Lexer::T_FLOAT:
                return $this->parseFloat($ts);
            case Lexer::T_QUOTATION_MARK:
                return $this->parseBasicString($ts);
            case Lexer::T_3_QUOTATION_MARK:
                return $this->parseMultilineBasicString($ts);
            case Lexer::T_APOSTROPHE:
                return $this->parseLiteralString($ts);
            case Lexer::T_3_APOSTROPHE:
                return $this->parseMultilineLiteralString($ts);
            case Lexer::T_DATE_TIME:
                return $this->parseDatetime($ts);
            default:
                $this->unexpectedTokenError($ts->getToken(), 'Value expected: boolean, integer, string or datetime'
                );
                break;
        }
    }

    private function parseBoolean(TokenStream $ts): bool
    {
        return $ts->getValue() == 'true' ? true : false;
    }

    private function parseInteger(TokenStream $ts): int
    {
        $value = $ts->getValue();

        if (preg_match("/([^\\d]_[^\\d])|(_$)/", $value)) {
            $this->syntaxError(
                    "Invalid integer number: underscore must be surrounded by at least one digit", $ts->getToken()
            );
        }

        $value = str_replace("_", "", $value);

        if (preg_match("/^0\\d+/", $value)) {
            $this->syntaxError(
                    "Invalid integer number: leading zeros are not allowed.", $ts->getToken()
            );
        }

        return (int) $value;
    }

    private function parseFloat(TokenStream $ts): float
    {
        $value = $ts->getValue();

        if (preg_match("/([^\\d]_[^\\d])|_[eE]|[eE]_|(_$)/", $value)) {
            $this->syntaxError(
                    "Invalid float number: underscore must be surrounded by at least one digit", $ts->getToken()
            );
        }

        $value = str_replace("_", "", $value);

        if (preg_match("/^0\\d+/", $value)) {
            $this->syntaxError(
                    "Invalid float number: leading zeros are not allowed.", $ts->getToken()
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

        $result = "";
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
        }
        $doLoop = true;
        while ($doLoop) {
            switch ($tokenId) {
                case Lexer::T_3_QUOTATION_MARK :
                    $this->popExpSet();
                    $doLoop = false;
                    break;
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
    private function parseLiteralString(TokenStream $ts): string
    {
        if ($ts->getTokenId() !== Lexer::T_APOSTROPHE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_APOSTROPHE);
        }

        $this->pushExpSet(Parser::E_LSTRING);


        $result = "";
        $tokenId = $ts->moveNextId();

        while ($tokenId !== Lexer::T_APOSTROPHE) {
            if (($tokenId === Lexer::T_NEWLINE) || ($tokenId === Lexer::T_EOS)) {
                $this->unexpectedTokenError($ts->getToken(), 'This character is not valid.');
            }
            $result .= $ts->getValue();
            $tokenId = $ts->moveNextId();
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
        $matches = null;
        preg_match("/\\\\U([0-9a-fA-F]{4})([0-9a-fA-F]{4})/", $value, $matches);

        return json_decode('"\u' . $matches[1] . '\u' . $matches[2] . '"');
    }

    private function parseDatetime(TokenStream $ts): \Datetime
    {
        $date = $ts->getValue();
        return new \Datetime($date);
    }

    /**
     * Recursive call of itself.
     * @param \Toml\TokenStream $ts
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
        $rct = 0;
        while ($tokenId !== Lexer::T_RIGHT_SQUARE_BRACE) {
            if ($tokenId === Lexer::T_LEFT_SQUARE_BRACE) {
                $value = $this->parseArray($ts);
            } else {
                // Returned value is a singular class instance to pass parameters
                $value = $this->parseSimpleValue($ts);
            }
            try {
                $result->offsetSet($rct, $value);
                $rct += 1;
            } catch (XArrayable $x) {
                throw new XArrayable($x->getMessage() . " at line " . $ts->getLine());
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
            } elseif ($tokenId !== Lexer::T_RIGHT_SQUARE_BRACE) {
                // should be finished
                $this->unexpectedTokenError($ts->getToken(), "Expect '.' or ']' after array item");
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
            $pushed = new KeyTable();
            $work->offsetSet($keyName, $pushed);
            $this->table = $pushed;
            return;
        }
        // TODO: Else or Assert??

        $this->table = $work->offsetGet($keyName);
    }

    private function parseInlineTable(TokenStream $ts, string $keyName): void
    {
        if ($ts->getTokenId() !== Lexer::T_LEFT_CURLY_BRACE) {
            $this->throwTokenError($ts->getToken(), Lexer::T_LEFT_CURLY_BRACE);
        }
        $this->pushExpSet(Parser::E_KEY);  // looking for keys

        $priorTable = $this->table;

        $this->pushWorkTable($keyName);

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

        $this->table = $priorTable;
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
            $tokenId = $this->parseComment($ts);
        }
        if ($tokenId !== Lexer::T_NEWLINE && $tokenId !== Lexer::T_EOS) {
            $this->unexpectedTokenError($ts->getToken(), 'Expected T_NEWLINE or T_EOS.');
        }
        return $tokenId;
    }

    private function tablePathError($msg)
    {
        $token = $this->ts->getToken();
        $msg .= " at line " . $token->line;
        throw new XArrayable($msg);
    }

    private function tablePathClash($orig, $pf)
    {
        $msg = "Table path [" . $pf->key . "] at line " . $pf->line
                . " interferes with path at line " . $orig->line;
        throw new XArrayable($msg);
    }

    /**
     * From array of Table/TableList generate its key path
     * using the object tags
     * @param array of parts
     * @return string
     */
    private function getPathName(array $parts, bool $withIndex = true): string
    {
        $result = '';
        foreach ($parts as $idx => $p) {
            $tag = $p->getTag();
            if ($tag->objAOT) {
                $bit = '[' . $tag->partKey;
                if ($withIndex) {
                    $bit .= '/' . $p->getEndIndex();
                }
                $bit .= ']';
            } else {
                $bit = '{' . $tag->partKey . '}';
            }
            if ($idx === 0) {
                $result = $bit;
            } else {
                $result .= $bit;
            }
        }
        return $result;
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
        $dotCount = 0;
        $AOTLength = 0;
        $pobj = $this->root;
        $hitNew = false;
        $firstNew = -1;
        $testObj = null;

        $pathToken = $ts->getToken();
        $tokenId = $pathToken->id;
        if ($tokenId != Lexer::T_LEFT_SQUARE_BRACE) {
            $this->tablePathError("Path start [ expected");
        }
        $tokenId = $ts->moveNextId();
        $doLoop = true;
        while ($doLoop) {
            switch ($tokenId) {
                case Lexer::T_HASH:
                    $this->tablePathError("Unexpected '#' in path");
                    break;
                case Lexer::T_EQUAL:
                    $this->tablePathError("Unexpected '=' in path");
                    break;
                case Lexer::T_SPACE:
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_EOS:
                    $doLoop = false;
                    break;
                case Lexer::T_NEWLINE:
                    $this->tablePathError("New line in unfinished path");
                    break;
                case Lexer::T_RIGHT_SQUARE_BRACE:

                    if ($isAOT) {
                        if ($AOTLength == 0) {
                            $this->tablePathError("AOT Segment cannot be empty");
                        }
                        $isAOT = false;
                        $AOTLength = 0;
                        $tokenId = $ts->moveNextId();
                        break;
                    } else {
                        $tokenId = $ts->moveNextId();
                        $doLoop = false;
                        break;
                    }
                case Lexer::T_LEFT_SQUARE_BRACE:
                    if ($dotCount < 1 && count($parts) > 0) {
                        $this->tablePathError("Expected a '.' after path key");
                    }
                    if ($isAOT) {
                        // one too many
                        $this->tablePathError("Too many consecutive [ in path");
                    }
                    $tokenId = $ts->moveNextId();
                    $isAOT = true;

                    break;
                case Lexer::T_DOT;

                    if ($dotCount === 1) {
                        $this->tablePathError("Found '..' in path");
                    }
                    $dotCount += 1;
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_QUOTATION_MARK:
                default:
                    $partKey = $this->parseKeyName($ts);
                    if ($dotCount < 1 && count($parts) > 0) {
                        $this->tablePathError("Expected a '.' after path key");
                    }
                    $dotCount = 0;
                    $testObj = $pobj->offsetExists($partKey) ? $pobj[$partKey] : null;
                    if (is_null($testObj)) {
                        // create object and tags
                        if (!$hitNew) {
                            $hitNew = true;
                            $firstNew = $partsCt;
                        }
                        $tag = new PartTag($partKey, $isAOT);
                        if ($isAOT) {
                            $AOTLength++;
                            $testObj = new TableList();
                            // store TableList as part
                            $pobj[$partKey] = $testObj;
                            $pobj = $testObj->getEndTable();
                        } else {
                            $testObj = new KeyTable();
                            $pobj[$partKey] = $testObj;
                            $pobj = $testObj;
                        }
                        $testObj->setTag($tag);
                    } else {
                        // Must be Arrayable, had all parts so far
                        $preMade = ($testObj instanceof \Toml\Arrayable);
                        if (!$preMade) {
                            $path = $this->getPathName($parts) . '.' . $partKey;
                            throw new XArrayable('Duplicate key path: ' . $path . ' line ' . $pathToken->line);
                        }
                        $tag = $testObj->getTag();
                        $tag->isAOT = $isAOT;
                        // TOM04 allows path inconsistancy, isAOT ?? objAOT 
                        if ($tag->objAOT) {
                            $AOTLength++;
                            $pobj = $testObj->getEndTable();
                        } else { // found a Table object
                            $pobj = $testObj;
                        }
                    }
                    $parts[] = $testObj; // Table or TableList
                    $partsCt++;
                    // because TOM04 allows for path inconsistany
                    // 

                    $tokenId = $ts->moveNextId();
                    break;
            }
        }
        // check the rules
        if ($partsCt == 0) {
            $this->tablePathError('Table path cannot be empty');
        }

        if (!$hitNew) {
            $tag = $testObj->getTag();
            if ($tag->objAOT) {
                if ($tag->isAOT) {
                    $pobj = $testObj->newTable();
                } else {
                    throw new XArrayable('Table path mismatch with ' . $this->getPathName($parts, false) . ' line ' . $pathToken->line);
                }
            } else {
                // terminates in reused table name?
                // OK if end part was implicit              
                if ($tag->implicit) {
                    // last part no longer implicit
                    $tag->implicit = false;
                } else {
                    throw new XArrayable('Duplicate key path: [' . $this->getPathName($parts, false) . '] line ' . $pathToken->line);
                }
            }
        } else {
            // all the parts from the $firstNew and before the last part
            // were created 'implicitly', so use the tag property to store
            // implicit flag
            $partsCt -= 1;
            $i = $firstNew;
            while ($i < $partsCt) {
                $testObj = $parts[$i];
                $tag = $testObj->getTag();
                $tag->implicit = true;
                $i += 1;
            }
        }

        $this->table = $pobj;
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
            $tokenMsg = sprintf('Token: %s line: %s', $name, $line);
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
