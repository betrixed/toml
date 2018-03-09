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

use Pun\Re8map;
use Pun\IdRex8;
use Pun\Pun8;
use Pun\Recap8;
use Pun\Token8Stream;
use Pun\Token8;
use Pun\ValueList;
use Pun\KeyTable;

class ParserRex {
    public $spacedEquals;
    public $anyValue;
    public $comment;
    public $spaceComment;
    
    public $keyRegex;
    public $valRegex;
    // 1 or 3 "quote"  has escapes, 3 is multi line 
    public $regEString; 
    // one line, single quote string
    public $regLString; 
    // multiline, triple quote string
    public $regMLString;   
    
    public $reMap;
     // php retains an array insertion order, so order of these is signficant, 
    // and if it wasn't , it would need to be enforced by iterating these directly


    public function __construct() {
        
        $this->keyRegex = [
            Lexer::T_SPACE,
            Lexer::T_UNQUOTED_KEY,
            Lexer::T_INTEGER
        ];
        
        $this->valRegex = [
            Lexer::T_BOOLEAN, Lexer::T_DATE_TIME,
            Lexer::T_FLOAT_EXP, Lexer::T_FLOAT, Lexer::T_INTEGER
        ];
        
        $this->regEString = [
            Lexer::T_SPACE, Lexer::T_BASIC_UNESCAPED,
            Lexer::T_ESCAPED_CHARACTER, Lexer::T_3_QUOTATION_MARK
        ];
        
        $this->regLString = [
            Lexer::T_LITERAL_STRING
        ];
        
        $this->regMLString = [
            Lexer::T_LITERAL_STRING, Lexer::T_3_APOSTROPHE
        ];
        
        $this->spacedEquals = new IdRex8(1,"^(\\h*=\\h*)");
        $this->anyValue = new IdRex8(1,"^([^\\s\\]\\},]+)");
        $this->comment = new IdRex8(1,"^(\\V*)");
        $this->spaceComment = new IdRex8(1,"^(\\h*#\\V*|\\h*)");
        
        $all = Lexer::getAllRegex();
        
        $map = new Re8map();
        $map->addMapIds($all,$this->keyRegex);
        $map->addMapIds($all,$this->valRegex);
        $map->addMapIds($all,$this->regEString);
        $map->addMapIds($all,$this->regLString);
        $map->addMapIds($all,$this->regMLString);
        
        $this->reMap = $map;
    }
}

class PathTrack {
    public $obj;
    public $path; // String key, path part
    public $isAOT; // bool: Update in parse for TOM04 checks
}
/**
 * Parser for TOML strings (specification version 0.4.0).
 *
 * @author Victor Puertas <vpgugr@vpgugr.com>
 */
class Parser
{

    // consts for parser expression tables

    const E_KEY = 0;
    const E_SCALER = 1;
    const E_LSTRING = 2;
    const E_MLSTRING = 3;
    const E_BSTRING = 4;
    const E_ALL = 5;
  
    private $root; // root Table object
    private $table; // dyanamic reference to current Table object
    // For regex table stack
    private $ts;
    private $token;
    // Stack of regex sets as KeyTable
    private $expSetId;
    private $expStack;
    // some individual reg expressions not in Id map

    // Spare Pun8 for testing values
    private $valueText;
    private $cap;
    // key value regular expression sets (list of integer)

    // cacheable globals, because compiling regular expressions
    // is expensive.
    static private $global;
    
    /**
     * Set the expression set to the previous on the
     * expression set stack
     */
    public function popExpSet(): void
    {
        $stack = $this->expStack;
        $top = $stack->size();
        if ($top > 0) {
            $value = $stack->back();
            $this->setExpSet($value);
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
        $this->expStack->pushBack($this->expSetId);
        $this->setExpSet($value);
    }

    private function setExpSet(int $value): void
    {
        $this->expSetId = $value;
        switch ($value) {
            case Parser::E_KEY:
                $obj = self::$global->keyRegex;
                break;
            case Parser::E_SCALER:
                $obj = self::$global->valRegex;
                break;
            case Parser::E_LSTRING:
                $obj = self::$global->regLString;
                break;
            case Parser::E_BSTRING:
                $obj = self::$global->regEString;
                break;
            case Parser::E_MLSTRING:
                $obj = self::$global->regMLString;
                break;
            default:
                throw new XArrayable("Invalid expression set constant " . $value);
                break;
        }
        $this->ts->setExpSet($obj);
    }

    public function __construct()
    {
        if (empty(self::$global)) {
            self::$global = new ParserRex();
        }
        $this->root = new KeyTable();
        $this->table = $this->root;

        $this->expStack = new ValueList();
        $this->stackTop = 0;

        
        
        $ts = new Token8Stream();
        
        $ts->setSingles(Lexer::getAllSingles());
        $ts->setUnknownId(Lexer::T_CHAR);
        $ts->setEOLId(Lexer::T_NEWLINE);
        $ts->setEOSId(Lexer::T_EOS);
    
        
        $ts->setRe8map(self::$global->reMap);
        
        $this->ts = $ts; 
        $this->token = new Token8();
        
        $this->valueText = new Pun8();
        $this->valueText->setRe8map(self::$global->reMap);
        $this->cap = new Recap8();
        
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


        // this function does or dies

        $this->ts->setInput($input);
    }

    public function acceptToken()
    {
        $this->ts->acceptToken($this->token);
    }
    public function peekToken() : Token8
    {
        return $this->ts->peekToken($this->token);
    }
    
    public function getToken() : Token8
    {
        return $this->ts->getToken($this->token);
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
    private function implementation(): void
    {   
        try {
            $ts = $this->ts;
            $this->table = $this->root;
            $tokenId = $ts->moveNextId();

            while ($tokenId !== Lexer::T_EOS) {            
                switch ($tokenId) {
                    case Lexer::T_HASH :
                        $tokenId = $this->parseComment();
                        break;
                    case Lexer::T_QUOTATION_MARK:
                    case Lexer::T_UNQUOTED_KEY:
                    case Lexer::T_APOSTROPHE :
                    case Lexer::T_INTEGER :
                        $tokenId = $this->parseKeyValue();
                        break;
                    case Lexer::T_LEFT_SQUARE_BRACE:
                        $tokenId = $this->parseTablePath();
                        break;
                    case Lexer::T_SPACE :
                    case Lexer::T_NEWLINE:
                        // loop again
                        $tokenId = $ts->moveNextId();
                        break;
                    default:
                        $this->syntaxError("Expect Key = , [Path] or # Comment");
                        break;
                }
            }
        }
        catch(\Exception $any) {
            throw new XArrayable($any->getMessage() . " at line " . $this->ts->getLine());
        }
    }

    /**
     * Skip comment, and return next none-comment token or NEWLINE or EOS
     * This exits with T_NEWLINE or T_EOS, and T_NEWLINE can be ignored 
     */
    private function parseComment(): int
    {
        $ts = $this->ts;
        $ts->moveRegex(self::$global->comment);
        $tokenId = $ts->moveNextId();
        return $tokenId;
    }
    
    /** To be valid, the value expression must match full string length */
    private function checkFullMatch($target, $cap)
    {
        if (strlen($cap) < strlen($target)) {
            $this->syntaxError("Value { " . $cap . " } is not full match");
        }
    }
    /**
     *  predict and setup parse for simple value, using guess $tokenId
     */
    private function getSimpleValue(int $tokenId) {  
        $ts = $this->ts;
        if ($tokenId === Lexer::T_APOSTROPHE) {
            // 3 or 1 quote? [\\x{27}]{3,3}
            if ($ts->moveRegId(Lexer::T_3_APOSTROPHE) ){
                $value = $this->parseMLString($ts);
            }
            else {
                $this->acceptToken();
                $value = $this->parseLiteralString($ts);
            }
            return $value;
        } elseif ($tokenId === Lexer::T_QUOTATION_MARK) {
            // 3 or 1 quote? [\\x{22}]{3,3}
            if ($ts->moveRegId(Lexer::T_3_QUOTATION_MARK) ) {
                $value = $this->parseMLEscapeString($ts);
            }
            else {
                $this->acceptToken();
                $value = $this->parseEscapeString($ts);
            }
            return $value;
        }
        // These values are at least white space bound.
        // with maybe a comma, ] or } or EOL following
        // Forced to do this, because otherwise 
        // preg_match may search entire remaining input
        if ($ts->moveRegex(self::$global->anyValue) ){
            $text = $ts->getValue();
        }
        else {
            $this->syntaxError("No value after = ");
        }    
        $valueText = $this->valueText;
        $cap = $this->cap;
        
        $valueText->setString($text);
        
        if ($valueText->matchMapId(Lexer::T_BOOLEAN, $cap) > 1) {
            $value = $cap->getCap(1) == "true" ? true : false;
        }
        elseif ($valueText->matchMapId(Lexer::T_DATE_TIME, $cap) > 1)
        {
            $match = $cap->getCap(1);
            $this->checkFullMatch($text, $match);
            $value = $this->parseDatetime($match);
        }
        elseif ($valueText->matchMapId(Lexer::T_FLOAT_EXP, $cap) > 1)
        {
            $value = $this->parseFloatExp($cap);
        }
        elseif ($valueText->matchMapId(Lexer::T_FLOAT, $cap) > 1)
        {
            $value = $this->parseFloat($cap);
        }
        elseif ($valueText->matchMapId(Lexer::T_INTEGER, $cap) > 1)
        {
            $match = $cap->getCap(1);
            $this->checkFullMatch($text, $match);
            $value = $this->parseInteger($match);
        }
        else {
            $this->syntaxError("Value type expected");
        }
        // reject token Id and process regular expressions
        return $value;
        
    }
    
    static private function valueWrap(string $s) : string {
        //$s = addslashes($s);
        return ". Value { " . $s . " }.";
    }
    /**
     * A call to expected regular expression failed,
     * so find out what was there by using a more general 
     * expression of space / something on rest of line
     * @param string $msg
     */
    private function regexError(string $msg) {
        $ts = $this->ts;
        $msg .= " on line " . $ts->getLine();
        if ($ts->moveRegex(self::$global->comment)) {
            $value = $ts->getValue();
            throw new XArrayable($msg . self::valueWrap($value));
        }
        else {
            $tokenId = $ts->moveNextId();
            $value = $ts->getValue();
            $name = Lexer::tokenName($tokenId);
            throw new XArrayable($msg . ". Got " . $name . self::valueWrap($value));
        }
    }
    private function parseKeyValue(bool $isFromInlineTable = false): int
    {
        $keyName = $this->parseKeyName();
        if ($this->table->hasK($keyName)) {
            $this->syntaxError("Duplicate key");
        }
        // Get an equals sign
        $ts = $this->ts;
        if (!$ts->moveRegex(self::$global->spacedEquals))
        {
            // nothing moved what is actually there?
            $this->regexError('Expected T_EQUAL (=)');
        }
        // E_SCALER has a lot of regular expressions in fixed order.
        // Predict a smaller set to use, in micro - management style.
        $token = $this->peekToken();
        $tokenId = $token->getId();
        if ($tokenId === Lexer::T_LEFT_SQUARE_BRACE) {
            $this->acceptToken();
            // have to push, because a pop comes later
            $vlist = new ValueList();
            $this->table[$keyName] = $vlist;
            $this->parseArray($vlist);
        } elseif ($tokenId === Lexer::T_LEFT_CURLY_BRACE) {
            $this->acceptToken();
            
            $this->parseInlineTable($keyName);
        } else {
            // Do not accept the token, but use its value to 
            // limit the expression set possibilities
            $this->table->setKV($keyName, $this->getSimpleValue($tokenId));
            
        }

        if (!$isFromInlineTable) {
            return $this->finishLine();
        } else {
            return $ts->moveNextId();
        }
    }

    private function parseKeyName()
    {
        $tokenId = $this->ts->getId();
        switch ($tokenId) {
            case Lexer::T_UNQUOTED_KEY:
                $value = $this->ts->getValue();
                break;
            case Lexer::T_QUOTATION_MARK:
                $value = $this->parseEscapeString();
                break;
            case Lexer::T_APOSTROPHE:
                $value = $this->parseLiteralString();
                break;
            case Lexer::T_INTEGER :
                $value = $this->parseInteger();
                break;
            default:
                $this->syntaxError("Improper key");
                break;
        }
        return $value;
    }

    private function parseInteger(string $value): int
    {
        if (preg_match("/([^\\d]_[^\\d])|(_$)/", $value)) {
            $this->syntaxError(
                    "Invalid integer number: underscore must be surrounded by at least one digit"
            );
        }

        $value = str_replace("_", "", $value);

        if (preg_match("/^0\\d+/", $value)) {
            $this->syntaxError(
                    "Invalid integer number: leading zeros are not allowed"
            );
        }

        return (int) $value;
    }

    private function parseFloatExp(Recap8 $cap): float
    {
        $value = $cap->getCap(1);
        if (preg_match("/([^\\d]_[^\\d])|_[eE]|[eE]_|(_$)/", $value)) {
            $this->syntaxError(
                    "Invalid float number: underscore must be surrounded by at least one digit"
            );
        }
        $value = str_replace("_", "", $value);

        if (preg_match("/^0\\d+/", $value)) {
            $this->syntaxError(
                    "Invalid float number: leading zeros are not allowed"
            );
        }

        return (float) $value;
    }
    private function parseFloat(Recap8 $cap): float
    {
        $value = $cap->getCap(1);
        if ($cap->count() < 5)
        {
             $this->syntaxError(
                    "Weird Float Capture" 
            );
        }
        $dec = $cap->getCap(4);
        if (strlen($dec) <= 1) {
            $this->syntaxError(
                    "Float number needs one digit after decimal point"
            );
        }
        
        if (preg_match("/([^\\d]_[^\\d])|(_$)/", $value)) {
            $this->syntaxError(
                    "Invalid float number: underscore must be surrounded by at least one digit"
            );
        }
        $value = str_replace("_", "", $value);

        if (preg_match("/^0\\d+/", $value)) {
            $this->syntaxError(
                    "Invalid float number: leading zeros are not allowed"
            );
        }

        return (float) $value;
    }

    /** In path parsing, we may want to keep quotes, because they can be used
     *  to enclose a '.' as a none separator. 
     * @param TokenStream $ts
     * @return string
     */
    private function parseEscapeString(): string
    {
        // Removed assert got a 1 quotation mark
        $this->pushExpSet(Parser::E_BSTRING);
        $ts = $this->ts;
        $tokenId = $ts->moveNextId();
        $result = "";
        while ($tokenId !== Lexer::T_QUOTATION_MARK) {
            if (($tokenId === Lexer::T_NEWLINE) || ($tokenId === Lexer::T_EOS) || ($tokenId
                    === Lexer::T_ESCAPE)) {
                // throws
                $this->syntaxError("Unfinished string value");
            } elseif ($tokenId === Lexer::T_ESCAPED_CHARACTER) {
                $value = $this->parseEscapedCharacter();
            } else {
                $value = $ts->getValue();
            }
            $result .= $value;
            $tokenId = $ts->moveNextId();
        }
        $this->popExpSet();

        return $result;
    }

    private function parseMLEscapeString(): string
    {
        // removed assert for is T_3_QUOTATION_MARK
        $this->pushExpSet(Parser::E_BSTRING);

        $result = "";
        $ts = $this->ts;
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
        }
        $doLoop = true;
        while ($doLoop) {
            switch ($tokenId) {
                case Lexer::T_3_QUOTATION_MARK :
                    $doLoop = false;
                    break;
                case Lexer::T_EOS:
                    $this->throwTokenError($this->getToken(), Lexer::T_3_QUOTATION_MARK);
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
        $this->popExpSet();
        return $result;
    }

    /**
     * 
     * @param TokenStream $ts
     * @return string
     */
    private function parseLiteralString(): string
    {
        //Removed assert current token is Lexer::T_APOSTROPHE
        $result = "";
        $ts = $this->ts;
        $token = $this->peekToken();
        $id = $token->getId();
        while ($id !== Lexer::T_APOSTROPHE) {
            if (($id === Lexer::T_NEWLINE) || ($id === Lexer::T_EOS)) {
                $this->syntaxError("Incomplete literal string");
            }
            if ($ts->moveRegId(Lexer::T_LITERAL_STRING)) {
                $result .= $ts->getValue();
            }
            else {
                $this->syntaxError("Bad literal string value");
            }
            
            $token = $this->peekToken();
            $id = $token->getId();
        }
        $this->acceptToken();
        return $result;
    }

    private function parseMLString(): string
    {
        // Remoted assert for T_3_APOSTROPHE
        $result = '';
        $this->pushExpSet(Parser::E_MLSTRING);
        $ts = $this->ts;
        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_NEWLINE) {
            $tokenId = $ts->moveNextId();
        }
        $doLoop = true;
        while ($doLoop) {
            switch($tokenId) {
                case Lexer::T_NEWLINE:
                    $result .= "\n";
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_3_APOSTROPHE:
                    $doLoop = false;
                    break;
                case Lexer::T_EOS:
                    $this->syntaxError("Expected token { ''' }");
                    break;
                default:
                    $result .= $ts->getValue();
                    $tokenId = $ts->moveNextId();
                    break;
            }  
        }
        $this->popExpSet();
        return $result;
    }

    private function parseEscapedCharacter(): string
    {
        $ts = $this->ts;
        $value = $ts->getValue();

        if (strlen($value) == 2) 
        {
            $value = substr($value,1,1);
            switch ($value) {
                case "n":
                    $result =  "\n";
                    break;              
                case "t":
                    $result =  "\t";
                    break;
                case "r":
                    $result =  "\r";
                    break;
                case "b":
                    $result = chr(8);
                    break;
                case "f":
                    $result =  chr(12);
                    break;
                case "\"":
                    $result =  "\"";
                    break;
                case "\\":
                    $result = "\\";
                    break;
                default:
                    throw new XArrayable("Invalid escape line " . $ts->getLine() . " \\" . $value);
            }
        }
        elseif (strlen($value) === 6) {
            $result = json_decode('"' . $value . '"');
        }
        else {
            $matches = null;
            if (preg_match("/^\\\\U([0-9a-fA-F]{4})([0-9a-fA-F]{4})/", $value, $matches)) {
                $result = json_decode('"\u' . $matches[1] . '\u' . $matches[2] . '"');
            }
            else {
                throw new XArrayable("Fail unicode match " . $ts->getLine() . " \\" . $value);
            }
        }
        return $result;
    }

    private function parseDatetime(string $value): \Datetime
    {

        return new \Datetime($value);
    }

    /**
     * Recursive call of itself.
     * @param \Toml\TokenStream $ts
     * @return array
     */
    private function parseArray(ValueList $vlist) 
    {
        $ts = $this->ts;
        $tokenId = $ts->getId();
        if ($tokenId != Lexer::T_LEFT_SQUARE_BRACE) {
            $this->throwTokenError($this->getToken(), Lexer::T_LEFT_SQUARE_BRACE);
        }
        // E_SCALER no longer does space, so micro-manage space stuff
        $token = $this->peekToken();
        $doLoop = true;
        while($doLoop) {
            switch($token->getId()) {
                case Lexer::T_SPACE:
                    // swallow immediate space
                    $ts->moveRegId(Lexer::T_SPACE);
                    $token = $this->peekToken();
                    break;
                case Lexer::T_NEWLINE:
                    $this->acceptToken();
                    $token = $this->peekToken();
                    break;
                case Lexer::T_HASH:
                    $this->acceptToken();
                    $this->parseComment($ts); 
                    $token = $this->peekToken();
                    break;
                case Lexer::T_EOS:
                    $this->acceptToken();
                    throw new XArrayable("Unfinished array");
                case Lexer::T_RIGHT_SQUARE_BRACE:
                    // empty array
                    $this->acceptToken();
                default:
                    $doLoop = false;
                    break;
            }
        }
        // left loop with predicted , but not accepted token

        $id = $token->getId();
        while ($id !== Lexer::T_RIGHT_SQUARE_BRACE) {
            if ($id === Lexer::T_LEFT_SQUARE_BRACE) {
                $this->acceptToken();
                $subList = new ValueList();
                $vlist->pushBack($subList);
                $this->parseArray($subList);
            } else {
                $value = $this->getSimpleValue($id);
                $vlist->pushBack($value);
            }

            // micro-manage simple stuff. Expect , ] # with spaces & newlines
            $token = $this->peekToken();
            $gotComma = false;
            $doLoop = true;
            
            while($doLoop)
            {
                switch($token->getId()) {
                    case Lexer::T_SPACE:
                        // swallow immediate space
                        $ts->moveRegId(Lexer::T_SPACE);
                        $token = $this->peekToken();
                        break;
                    case Lexer::T_NEWLINE:
                        $this->acceptToken();
                        $token = $this->peekToken();
                        break;
                    case Lexer::T_HASH:
                        $this->acceptToken();
                        $this->parseComment($ts); 
                        $token = $this->peekToken();
                        break;
                    case Lexer::T_COMMA:
                         if ($gotComma) {
                            throw new XArrayable("No value between commas");
                        }
                        else {
                            $gotComma = true;
                        }
                        $this->acceptToken();
                        $token = $this->peekToken();
                        break;
                    case Lexer::T_RIGHT_SQUARE_BRACE:
                        $this->acceptToken();
                    default:
                        $doLoop = false;
                        break;
                } 
            }
            $id = $token->getId();
        }

        if ($id !== Lexer::T_RIGHT_SQUARE_BRACE) {
            $this->throwTokenError($token, Lexer::T_RIGHT_SQUARE_BRACE);
        }
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
        if ($work->hasK($keyName) === false) {
            $pushed = new KeyTable();
            $work->setKV($keyName, $pushed);
            $this->table = $pushed;
            return;
        }
        // TODO: Else or Assert??

        $this->table = $work->offsetGet($keyName);
    }

    private function parseInlineTable(string $keyName): void
    {
        $ts = $this->ts;

        $this->pushExpSet(Parser::E_KEY);  // looking for keys

        $priorTable = $this->table;

        $this->pushWorkTable($keyName);

        $tokenId = $ts->moveNextId();
        if ($tokenId === Lexer::T_SPACE) {
            $tokenId = $ts->moveNextId();
        }

        if ($tokenId !== Lexer::T_RIGHT_CURLY_BRACE) {
            $tokenId = $this->parseKeyValue(true);
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
        }

        while ($tokenId === Lexer::T_COMMA) {
            $tokenId = $ts->moveNextId();
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
            $tokenId = $this->parseKeyValue( true);
            if ($tokenId === Lexer::T_SPACE) {
                $tokenId = $ts->moveNextId();
            }
        }
        $this->popExpSet();
        if ($tokenId !== Lexer::T_RIGHT_CURLY_BRACE) {
            $this->throwTokenError($this->getToken(), Lexer::T_RIGHT_CURLY_BRACE);
        }

        $this->table = $priorTable;
    }

    /**
     * Nothing more of interest on the line,
     * anything besides a comment is an error
     * Used by parseObjectPath and parseKeyValue
     */
    private function finishLine(): int
    {
        // prior to this, the next token hasn't been consumed,
        // so what is it?
        $ts = $this->ts;
        $ts->moveRegex(self::$global->spaceComment);
        $tokenId = $ts->moveNextId(); 
        
        if ($tokenId !== Lexer::T_NEWLINE && $tokenId !== Lexer::T_EOS) {
            $this->syntaxError("Expected NEWLINE or EOS");
        }
        return $tokenId;
    }

    private function tablePathError($msg)
    {
        $token = $this->getToken();
        $msg .= " at line " . $token->getLine();
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
            $obj = $p->obj;
            $tag = $obj->getTag();
            if ($tag->objAOT) {
                $bit = '[' . $p->path;
                if ($withIndex) {
                    $bit .= '/' . $obj->getEndIndex();
                }
                $bit .= ']';
            } else {
                $bit = '{' . $p->path . '}';
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
    private function parseObjectPath()
    {
        $isAOT = false;
        $parts = []; // TomlPartTag collection
        $partsCt = 0;
        $dotCount = 0;
        $AOTLength = 0;
        $pobj = $this->root;
        $hitNew = false;
        $firstNew = -1;
        $testObj = null;
        
        $pathToken = $this->getToken();
        $tokenId = $pathToken->getId();
        if ($tokenId != Lexer::T_LEFT_SQUARE_BRACE) {
            $this->tablePathError("Path start [ expected");
        }
        $ts = $this->ts;
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
                        //$tokenId = $ts->moveNextId();
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
                     // always create a PathTrack
                    $track = new PathTrack();
                    $track->path = $this->parseKeyName();
                    $track->isAOT = $isAOT;
                   
                    
                    
                    if ($dotCount < 1 && count($parts) > 0) {
                        $this->tablePathError("Expected a '.' after path key");
                    }
                    $dotCount = 0;
                    $testObj = $pobj->hasK($track->path) ? $pobj->getV($track->path) : null;
                    if (is_null($testObj)) {
                        // create object and tags
                        if (!$hitNew) {
                            $hitNew = true;
                            $firstNew = $partsCt;
                        }
                        $tag = new PartTag();
                        $tag->objAOT = $isAOT;
                        $tag->implicit = true; 
                        if ($isAOT) {
                            $AOTLength++;
                            $testObj = new ValueList();
                            // store TableList as part
                            $newTable = new KeyTable();
                            $testObj->pushBack($newTable);
                            $pobj[$track->path] = $testObj;
                            $pobj = $newTable;
                        } else {
                            $testObj = new KeyTable();
                            $pobj[$track->path] = $testObj;
                            $pobj = $testObj;
                        }
                        
                        $testObj->setTag($tag);
                    } else {
                        // Must be Arrayable, had all parts so far
                        $tag = null;
                        if (is_object($testObj)) {
                            $className = get_class($testObj);
                            if ($className === "Pun\\KeyTable" || $className === "Pun\\ValueList") {
                                $tag = $testObj->getTag();
                            }
                        }
                        if (empty($tag)) {
                            $path = $this->getPathName($parts) . '.' . $track->path;
                            throw new XArrayable('Duplicate key path: ' . $path . ' line ' . $pathToken->getLine());
                        }
                        if ($tag->objAOT) {
                            $AOTLength++;
                            $pobj = $testObj->back();
                        } else { // found a Table object
                            $pobj = $testObj;
                        }
                        
                    }
                    $track->obj = $testObj;
                    $parts[] = $track; // Table or TableList
                    $partsCt++;
                    $tokenId = $ts->moveNextId();
                    break;
            }
        }
        // check the rules
        if ($partsCt == 0) {
            $this->tablePathError('Table path cannot be empty');
        }
        $tag = $testObj->getTag();
        if (!$hitNew) {
            // check last part
            
            $track = $parts[$partsCt - 1];
            if ($tag->objAOT) {
                if ($track->isAOT) {
                    // another terminal AOT
                    $newTable = new KeyTable();
                    $testObj->pushBack($newTable);
                    $pobj = $newTable;
                } else {
                    // Inconsistant, cannot have table here as well
                    throw new XArrayable('Table path mismatch with ' . $this->getPathName($parts, false) . ' line ' . $pathToken->getLine());
                }
            } else {
                // KeyTable, OK if last time was implicit              
                if ($tag->implicit) {
                    // Not allowed next time
                    $tag->implicit = false;
                } else {
                    throw new XArrayable('Duplicate key path: [' . $this->getPathName($parts, false) . '] line ' . $pathToken->getLine());
                }
            }
        } else {
            // all the parts from the $firstNew
            // were tagged as 'implicit', but last part cannot be implicit.
            // Go back and undo last implicit
            $tag->implicit = false;
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
    private function parseTablePath(): int
    {
        $this->parseObjectPath();

        return $this->finishLine();
    }

    private function throwTokenError($token, int $expectedId)
    {
        $tokenName = Lexer::tokenName($expectedId);
        $this->syntaxError("Expected " . $tokenName);
    }

    /**
     * Return the next tokenId, after skipping any comments and space
     * @param TokenStream $ts
     */
    private function parseCommentsAndSpace(): int
    {
        $ts = $this->ts;
        $tokenId = $ts->getId();
        $doLoop = true;
        while($doLoop) {
            switch($tokenId) {
                case Lexer::T_HASH:
                    $tokenId = $this->parseComment($ts);
                    break;
                case Lexer::T_NEWLINE:
                    $tokenId = $ts->moveNextId();
                    break;
                case Lexer::T_SPACE:
                    $tokenId = $ts->moveNextId();
                    break;
                default:
                    $doLoop = false;
                    break;
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

    private function syntaxError($msg): void
    {
        $token = $this->getToken();
        $line = $token->getLine();
        $value = $token->getValue();

        $msg = "Error line " . $line . ": " . $msg;

        if (strlen($value) > 0) {
             $msg = $msg . self::valueWrap($value);
        }
        else {
            $msg .= '.';
        }

        throw new XArrayable($msg);
    }

}
