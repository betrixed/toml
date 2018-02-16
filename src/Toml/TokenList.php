<?php

namespace Toml;
/**
 * Description of TokenArray
 * Just enough to satisfy LexerText
 * @author Michael Rynn
 * 
 */
class TokenList
{
    protected $tokens;
    protected $ct;
    protected $index;
    protected $token; // token at current position
    /**
     * Constructor
     * Need to call moveNextId or setOffset to set first location,
     * 
     * @param Token[] List of tokens
     */
    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->ct = count($tokens);
        $this->index = -1;
        $this->moveNextId();
    }

    /**
     * Get the current token offset
     * @return int
     */
    public function getOffset() : int {
        return $this->index;
    }
    /** 
     * Set current array of tokens position 
     */
    public function setOffset(int $index) : int{
        if ($index < $this->ct) {
            $this->index = $index;
            $this->token = $this->tokens[$index];
            return $this->token->id;
        }
        return 0;
    }
/**
     * Checks if the following tokens in the stream match with the sequence of tokens
     *
     * @param int[] $tokenIds Sequence of token ids
     *
     * @return bool
     */
    public function isNextSequence(array $tokenIds) : bool
    {
        $base = $this->index;
        foreach ($tokenIds as $idx => $id) {
            $offset = $idx + $base;
            if ($offset < $this->ct) {
                if ($this->tokens[$offset]->id != $id)
                    return false;
            }
        }
        return true;
    }    
    
    /**
     * Moves the pointer one token forward
     *
     * @return positive token id, or 0 (false)
     */
    public function moveNextId() : int
    {
        $next = $this->index + 1;
        $this->index = $next;
        if ($next < $this->ct) {
            $this->token = $this->tokens[$next];
            return $this->token->id;
        }
        return 0;
    }
    
    /**
     * Token from current parse position
     * @return \Toml\Token
     */
    public function getToken() : ?Token {
        return $this->token;
    }
    /** 
     * Token id from current parse position
     *  
     */
    public function getTokenId() : int {
        return (!is_null($this->token)) ? $this->token->id : 0;
    }
    
    /** 
     * Token value from current parse position
     *  
     */
    public function getValue()  {
        return (!is_null($this->token)) ? $token->value : null;
    }
}
