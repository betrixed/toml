<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Toml;

use Pun\Pun8;
use Pun\Re8map;
use Pun\Recap8;

/**
 * Description of TokenStream
 * Gets same token object each time, from the current parse point
 * Go back , and look ahead, will require array of token clones, or tokenIds,
 * @author Michael Rynn
 */
class TokenStream
{
    
    protected $input; // Pun8 class
    protected $id;
    protected $caps;  // Recap8 class for matches
    protected $value;
    protected $flagLine;
    protected $tokenLine;  // line number of current token
    protected $isSingle; // was found in single character list
    protected $regex; // PHP Array of integer key Ids to regular expressions
    protected $singles; // Reference to single character lookup for tokenId
    protected $unknownId; // int value to represent single character not in singles.
    protected $newLineId; //
    protected $eosId;
    protected $token;  // The one and only token instance

    // topLevel parse

    public function __construct()
    {
        $this->caps = new Recap8();
        $this->input = new Pun8();
        
        $this->flagLine = false;
        $this->tokenLine = 0;
        $this->token = new Token();
    }

    /**
     * Token id to be returned if no more text is available
     * @param int $id
     */
    public function setEOSId(int $id)
    {
        $this->eosId = $id;
    }
    /**
     * Token id to be returned if newline character is parsed
     * @param int $id
     */
    public function setNewLineId(int $id)
    {
        $this->newLineId = $id;
    }

    /** The unknown id is returned if no match in 
     *  the Singles character table.
     * @param int $id
     */
    public function setUnknownId(int $id)
    {
        $this->unknownId = $id;
    }

   

    /**
     * Return current expression array
     */
    public function getExpSet() : array {
        return $this->regex;
    }
    
     /**
     * Reference to list integer keys to PCRE2
     * @param array $ref
     */
    public function setExpSet(array $ref)
    {
        $this->regex = $ref;
    }
    /**
     * For lookup of tokenId of single character string
     * Argument is reference to associative array[string] of int 
     * @param array $ref
     */
    public function setSingles(array $ref)
    {
        $this->singles = $ref;
    }

    public function setRe8Map($remap) {
        $this->input->setRe8map($remap);
    }
    public function setInput(string $input)
    {
        $this->input->setString($input);
        $this->flagLine = true;
        $this->tokenLine = 0;
    }

    public function hasPendingTokens(): bool
    {
        return ($this->id !== $this->eosId);
    }

    /**
     * Get all token details of parse step
     * @return \Toml\Token
     */
    public function getToken(): Token
    {
        $token = $this->token;
        $token->set($this->value, $this->id, $this->tokenLine, $this->isSingle);
        return $token;
    }

    /**
     * Return line associated with current token
     */
    public function getLine() : int {
        return $this->tokenLine;
    }
    /**
     * Return the current parse step value
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /** 
     * Fetch internal token id.
     */
    public function getTokenId(): int
    {
        return $this->id;
    }

    
    /** Return the single character at the front of the parse. 
     *  Does not alter this objects internal state values,
     *  except for properties of Token, which must be treated as read-only. 
     *  Maybe can use this to 'predict' the next expression set to use.
     *  Return "value" and single character TokenId in the Token.
     *  Cannot return tokenId for multi-character regular expressions,
     *  which is the whole idea.
     */
    public function peekToken() : Token {
        // Put next characters in $test, not altering TokenStream state.
        
        $input = $this->input;
        $offset = $input->getOffset();
        $text = $input->nextChar();
        $token = $this->token;
        
        if (!$this->isData($text)) {
            $token->value = $this->value;
            $token->id = $this->id;
            $token->line = $this->tokenLine;
            $token->isSingle = $this->isSingle;
            $input->setOffset($offset); // reset original position
            return $token;
        }
        $token->line = $this->tokenLine;
        $token->value = $text;
        if (strlen($text) === 1) {
            $token->id = $this->singles[$text] ?? $this->unknownId;
        }
        $token->isSingle = ($this->id !== $this->unknownId);
       
        $input->setOffset($offset);// reset original position
        return $token;
    }
    /**
     * Try regular expression in the expression map
     * Assumes previous peekToken returned a known token.
     * Leaves tokenId as unknownId
     * @param string $regex
     */
    public function moveRegId($id) : bool {
        $input = $this->input;
        $ct = $input->matchMapId($id, $this->caps);
        if ($ct > 1)
         {
            $cap = $this->caps;
            $this->value = $cap->getCap(1);
            $this->isSingle = false;
            $this->id = $this->unknownId;
            $takeOff = strlen($cap->getCap(0));
            $this->input->addOffset($takeOff);
            return true;
        }   
        return false;
    }
    /**
     * Try regular expression not in the expression map
     * Assumes previous peekToken returned a known token.
     * Leaves tokenId as unknownId
     * @param string $regex
     */
    public function moveRegex($pcre) : bool {
        $input = $this->input;
        $ct = $input->matchIdRex8($pcre, $this->caps);
        if ($ct > 1)
         {
            $cap = $this->caps;
            $this->value = $cap->getCap(1);
            $this->isSingle = false;
            $this->id = $this->unknownId;
            $takeOff = strlen($cap->getCap(0));
            $this->input->addOffset($takeOff);
            return true;
        }   
        return false;
    }
    /**
     * If a peekNextChar has been done, this uses internal Token values to 
     * advance the parse (namely the string length of the value),
     * on the current line. It is important that token values have not been altered,
     * and parse position has not been altered prior to calling this method.
     * 
     * A call to getToken, will still return same values as the Token;
     */
    public function acceptToken()  {
        if ($this->flagLine) {
            $this->flagLine = false;
            $this->tokenLine += 1;
        }
        $token = $this->token;
        $this->isSingle = $token->isSingle;
        $this->id = $token->id;
        $this->value = $token->value;
        
        if ($token->id === $this->eosId) {
            return;
        }
        elseif ($token->id === $this->newLineId) {
            $this->input->addOffset(1);
            return;
        }
        $takeoff = strlen($token->value);
        $this->input->addOffset($takeoff);
        return;
    }
    
    private function setEOL()  {
         $this->value = "";
         $this->id = $this->newLineId;
         $this->isSingle = true;
    }
    private function setEOS() {
         $this->value = "";
         $this->id = $this->eosId;
         $this->isSingle = true;
    }
    
    private function isData($text) : bool {
        if ($text === false) {
            $this->setEOS();
            return false;
        }
        $input = $this->input;
        $code = $input->getCode();
        if ($code === 13) {
            $text = $input->nextChar();
            if (empty($test)) {
                $this->setEOS();
                return false;
            }
            $code = $input->getCode();
            if ($code !== 10) {
                throw new XArrayable("EOL? chr(13) but no chr(10)");
            }
        }
        if ($code === 10) {
            $this->flagLine = true; 
            $this->setEOL();
            return false;
        }  
        return true;
    }
    
    /**
     * Return a tokenId, for a pattern, or character match.
     * 
     * @param \Toml\KeyTable $patterns
     * @return int
     */
    public function moveNextId() : int
    {
        if ($this->flagLine) {
            $this->flagLine = false;
            $this->tokenLine += 1;
        }
        $input = $this->input;
        $offset = $input->getOffset();
        $text = $input->nextChar();
        
        if (!$this->isData($text)) {
            return $this->id;
        }
        
        $caps = $this->caps;
        $input->setOffset($offset);
        
        $id = $input->firstMatch($this->regex, $caps);
        // Not EOL or EOS, use original offset for pattern testing
        
        if ($caps->count() > 1) {
            $this->id = $id;
            $this->value = $caps->getCap(1);
            $this->isSingle = false;
            $takeOff = strlen($caps->getCap(0));
            $input->addOffset($takeOff);
            return $id;
        }
        $this->value = $text;
        $ct = strlen($text);
        
        if ($ct === 1) {
            $id = $this->singles[$text] ?? $this->unknownId;
        }
        $this->isSingle = ($this->id !== $this->unknownId);
        $this->id = $id;
        $input->addOffset($ct);
        return $id;
    }

}
