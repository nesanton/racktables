<?php

abstract class RackCodeParser
{
	const STATE_ESOTSM              = 301;
	const STATE_READING_KEYWORD     = 302;
	const STATE_READING_TAG_1       = 303;
	const STATE_READING_TAG_2       = 304;
	const STATE_READING_PREDICATE_1 = 305;
	const STATE_READING_PREDICATE_2 = 306;
	const STATE_SKIPPING_COMMENT    = 307;

	protected final function getLexems ($text)
	{
		$ret = array();
		# Add a mock character to aid in synchronization with otherwise correct,
		# but short or odd-terminated final lines.
		$text .= ' ';
		$textlen = mb_strlen ($text);
		$lineno = 1;
		$state = self::STATE_ESOTSM;
		$valid_keywords = array
		(
			'allow'   => RackCodeParseTree::LEX_ALLOW,
			'deny'    => RackCodeParseTree::LEX_DENY,
			'define'  => RackCodeParseTree::LEX_DEFINE,
			'and'     => RackCodeParseTree::LEX_AND,
			'or'      => RackCodeParseTree::LEX_OR,
			'not'     => RackCodeParseTree::LEX_NOT,
			'true'    => RackCodeParseTree::LEX_TRUE,
			'false'   => RackCodeParseTree::LEX_FALSE,
			'context' => RackCodeParseTree::LEX_CONTEXT,
			'clear'   => RackCodeParseTree::LEX_CLEAR,
			'insert'  => RackCodeParseTree::LEX_INSERT,
			'remove'  => RackCodeParseTree::LEX_REMOVE,
			'on'      => RackCodeParseTree::LEX_ON,
		);
		for ($i = 0; $i < $textlen; $i++) :
			$char = mb_substr ($text, $i, 1);
			$newstate = $state;
			switch ($state) :
				case self::STATE_ESOTSM:
					switch (TRUE)
					{
						case ($char == '('):
							$ret[] = array ('type' => RackCodeParseTree::LEX_LBRACE, 'lineno' => $lineno);
							break;
						case ($char == ')'):
							$ret[] = array ('type' => RackCodeParseTree::LEX_RBRACE, 'lineno' => $lineno);
							break;
						case ($char == '#'):
							$newstate = self::STATE_SKIPPING_COMMENT;
							break;
						case (preg_match ('/[\p{L}]/u', $char) == 1):
							$newstate = self::STATE_READING_KEYWORD;
							$buffer = $char;
							break;
						case ($char == "\n"):
							$lineno++; // fall through
						case ($char == ' '):
						case ($char == "\t"):
							// nom-nom...
							break;
						case ($char == '{'):
							$newstate = self::STATE_READING_TAG_1;
							break;
						case ($char == '['):
							$newstate = self::STATE_READING_PREDICATE_1;
							break;
						default:
							throw new RackCodeError ("Invalid character '${char}'", $lineno);
					}
					break;
				case self::STATE_READING_KEYWORD:
					switch (TRUE)
					{
						case (preg_match ('/[\p{L}]/u', $char) == 1):
							$buffer .= $char;
							break;
						case ($char == "\n"):
							$lineno++; // fall through
						case ($char == ' '):
						case ($char == "\t"):
						case ($char == ')'): // this will be handled below
							// got a word, sort it out
							if (! array_key_exists ($buffer, $valid_keywords))
								throw new RackCodeError ("Invalid keyword '${buffer}'", $lineno);
							$ret[] = array ('type' => $valid_keywords[$buffer], 'lineno' => $lineno);
							if ($char == ')')
								$ret[] = array ('type' => RackCodeParseTree::LEX_RBRACE, 'lineno' => $lineno);
							$newstate = self::STATE_ESOTSM;
							break;
						default:
							throw new RackCodeError ("Invalid character '${char}'", $lineno);
					}
					break;
				case self::STATE_READING_TAG_1:
					switch (TRUE)
					{
						case ($char == "\n"):
							$lineno++; // fall through
						case ($char == ' '):
						case ($char == "\t"):
							// nom-nom...
							break;
						case (preg_match ('/[\p{L}0-9\$]/u', $char) == 1):
							$buffer = $char;
							$newstate = self::STATE_READING_TAG_2;
							break;
						default:
							throw new RackCodeError ("Invalid character '${char}'", $lineno);
					}
					break;
				case self::STATE_READING_TAG_2:
					switch (TRUE)
					{
						case ($char == '}'):
							$buffer = rtrim ($buffer);
							if (!validTagName ($buffer, TRUE))
								throw new RackCodeError ("Invalid tag name '${buffer}'", $lineno);
							$ret[] = array ('type' => ($buffer[0] == '$' ? RackCodeParseTree::LEX_AUTOTAG : RackCodeParseTree::LEX_TAG), 'load' => $buffer, 'lineno' => $lineno);
							$newstate = self::STATE_ESOTSM;
							break;
						case (preg_match ('/[\p{L}0-9. _~-]/u', $char) == 1):
							$buffer .= $char;
							break;
						default:
							throw new RackCodeError ("Invalid character '${char}'", $lineno);
					}
					break;
				case self::STATE_READING_PREDICATE_1:
					switch (TRUE)
					{
						case ($char == "\n"):
							$lineno++; // fall through
						case ($char == ' '):
						case ($char == "\t"):
							// nom-nom...
							break;
						case (preg_match ('/[\p{L}0-9]/u', $char) == 1):
							$buffer = $char;
							$newstate = self::STATE_READING_PREDICATE_2;
							break;
						default:
							throw new RackCodeError ("Invalid character '${char}'", $lineno);
					}
					break;
				case self::STATE_READING_PREDICATE_2:
					switch (TRUE)
					{
						case ($char == ']'):
							$buffer = rtrim ($buffer);
							if (!validTagName ($buffer))
								throw new RackCodeError ("Invalid predicate name '${buffer}'", $lineno);
							$ret[] = array ('type' => RackCodeParseTree::LEX_PREDICATE, 'load' => $buffer, 'lineno' => $lineno);
							$newstate = self::STATE_ESOTSM;
							break;
						case (preg_match ('/[\p{L}0-9. _~-]/u', $char) == 1):
							$buffer .= $char;
							break;
						default:
							throw new RackCodeError ("Invalid character '${char}'", $lineno);
					}
					break;
				case self::STATE_SKIPPING_COMMENT:
					switch ($char)
					{
						case "\n":
							$lineno++;
							$newstate = self::STATE_ESOTSM;
						default: // eat char, nom-nom...
							break;
					}
					break;
				default:
					throw new RackTablesError ("Lexical scanner FSM fatal error (state == '${state})'", RackTablesError::INTERNAL);
			endswitch;
			$state = $newstate;
		endfor;
		if ($state != self::STATE_ESOTSM and $state != self::STATE_SKIPPING_COMMENT)
			throw new RackTablesError ("Lexical scanner FSM fatal error (state == '${state})'", RackTablesError::INTERNAL);
		return $ret;
	}
	protected final function getParseTree ($lexems)
	{
		$stack = array(); // subject to array_push() and array_pop()
		$done = 0; // $lexems[$done] is the next item in the tape
		$todo = count ($lexems);

		// Perform shift-reduce processing. The "accept" actions occurs with an
		// empty input tape and the stack holding only one symbol (the start
		// symbol, SYNT_CODETEXT). When reducing, set the "line number" of
		// the reduction result to the line number of the "latest" item of the
		// reduction base (the one on the stack top). This will help locating
		// parse errors, if any.
		while (TRUE)
		{
			$stacktop = $stacksecondtop = $stackthirdtop = $stackfourthtop = array ('type' => 'null');
			$stacksize = count ($stack);
			if ($stacksize >= 1)
			{
				$stacktop = array_pop ($stack);
				// It is possible to run into a S/R conflict, when having a syntaxically
				// correct sentence base on the stack and some "and {something}" items
				// on the input tape, hence let's detect this specific case and insist
				// on "shift" action to make SYNT_AND_EXPR parsing hungry.
				// P.S. Same action is taken for SYNT_EXPR (logical-OR) to prevent
				// premature reduction of "condition" for grant/definition/context
				// modifier sentences. The shift tries to be conservative, it advances
				// by only one token on the tape.
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_AND_EXPR and $done < $todo and $lexems[$done]['type'] == RackCodeParseTree::LEX_AND or
					$stacktop['type'] == RackCodeParseTree::SYNT_EXPR and $done < $todo and $lexems[$done]['type'] == RackCodeParseTree::LEX_OR
				)
				{
					// shift!
					array_push ($stack, $stacktop);
					array_push ($stack, $lexems[$done++]);
					continue;
				}
				if ($stacksize >= 2)
				{
					$stacksecondtop = array_pop ($stack);
					if ($stacksize >= 3)
					{
						$stackthirdtop = array_pop ($stack);
						if ($stacksize >= 4)
						{
							$stackfourthtop = array_pop ($stack);
							array_push ($stack, $stackfourthtop);
						}
						array_push ($stack, $stackthirdtop);
					}
					array_push ($stack, $stacksecondtop);
				}
				array_push ($stack, $stacktop);
				// First detect definition start to save the predicate from being reduced into
				// unary expression.
				// DEFINE ::= define PREDICATE
				if
				(
					$stacktop['type'] == RackCodeParseTree::LEX_PREDICATE and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_DEFINE
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_DEFINE,
							'lineno' => $stacktop['lineno'],
							'load' => $stacktop['load']
						)
					);
					continue;
				}
				// CTXMOD ::= clear
				if
				(
					$stacktop['type'] == RackCodeParseTree::LEX_CLEAR
				)
				{
					// reduce!
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_CTXMOD,
							'lineno' => $stacktop['lineno'],
							'load' => array ('op' => 'clear')
						)
					);
					continue;
				}
				// CTXMOD ::= insert TAG
				if
				(
					$stacktop['type'] == RackCodeParseTree::LEX_TAG and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_INSERT
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_CTXMOD,
							'lineno' => $stacktop['lineno'],
							'load' => array ('op' => 'insert', 'tag' => $stacktop['load'], 'lineno' => $stacktop['lineno'])
						)
					);
					continue;
				}
				// CTXMOD ::= remove TAG
				if
				(
					$stacktop['type'] == RackCodeParseTree::LEX_TAG and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_REMOVE
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_CTXMOD,
							'lineno' => $stacktop['lineno'],
							'load' => array ('op' => 'remove', 'tag' => $stacktop['load'], 'lineno' => $stacktop['lineno'])
						)
					);
					continue;
				}
				// CTXMODLIST ::= CTXMODLIST CTXMOD
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_CTXMOD and
					$stacksecondtop['type'] == RackCodeParseTree::SYNT_CTXMODLIST
				)
				{
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_CTXMODLIST,
							'lineno' => $stacktop['lineno'],
							'load' => array_merge ($stacksecondtop['load'], array ($stacktop['load']))
						)
					);
					continue;
				}
				// CTXMODLIST ::= CTXMOD
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_CTXMOD
				)
				{
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_CTXMODLIST,
							'lineno' => $stacktop['lineno'],
							'load' => array ($stacktop['load'])
						)
					);
					continue;
				}
				// Try "replace" action only on a non-empty stack.
				// If a handle is found for reversing a production rule, do it and start a new
				// cycle instead of advancing further on rule list. This will preserve rule priority
				// in the grammar and keep us from an extra shift action.
				// UNARY_EXPRESSION ::= true | false | TAG | AUTOTAG | PREDICATE
				if
				(
					$stacktop['type'] == RackCodeParseTree::LEX_TAG or // first look for tokens, which are most
					$stacktop['type'] == RackCodeParseTree::LEX_AUTOTAG or // likely to appear in the text
					$stacktop['type'] == RackCodeParseTree::LEX_PREDICATE or // supplied by user
					$stacktop['type'] == RackCodeParseTree::LEX_TRUE or
					$stacktop['type'] == RackCodeParseTree::LEX_FALSE
				)
				{
					// reduce!
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_UNARY_EXPR,
							'lineno' => $stacktop['lineno'],
							'load' => $stacktop
						)
					);
					continue;
				}
				// UNARY_EXPRESSION ::= (EXPRESSION)
				// Useful trick about AND- and OR-expressions is to check, if the
				// node we are reducing contains only 1 argument. In this case
				// discard the wrapper and join the "load" argument into new node directly.
				if
				(
					$stacktop['type'] == RackCodeParseTree::LEX_RBRACE and
					$stacksecondtop['type'] == RackCodeParseTree::SYNT_EXPR and
					$stackthirdtop['type'] == RackCodeParseTree::LEX_LBRACE
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_UNARY_EXPR,
							'lineno' => $stacksecondtop['lineno'],
							'load' => isset ($stacksecondtop['load']) ? $stacksecondtop['load'] : $stacksecondtop
						)
					);
					continue;
				}
				// UNARY_EXPRESSION ::= not UNARY_EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_UNARY_EXPR and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_NOT
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_UNARY_EXPR,
							'lineno' => $stacktop['lineno'],
							'load' => array
							(
								'type' => RackCodeParseTree::SYNT_NOT_EXPR,
								'load' => $stacktop['load']
							)
						)
					);
					continue;
				}
				// AND_EXPRESSION ::= AND_EXPRESSION and UNARY_EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_UNARY_EXPR and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_AND and
					$stackthirdtop['type'] == RackCodeParseTree::SYNT_AND_EXPR
				)
				{
					array_pop ($stack);
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_AND_EXPR,
							'lineno' => $stacktop['lineno'],
							'left' => isset ($stackthirdtop['load']) ? $stackthirdtop['load'] : $stackthirdtop,
							'right' => $stacktop['load']
						)
					);
					continue;
				}
				// AND_EXPRESSION ::= UNARY_EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_UNARY_EXPR
				)
				{
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_AND_EXPR,
							'lineno' => $stacktop['lineno'],
							'load' => $stacktop['load']
						)
					);
					continue;
				}
				// EXPRESSION ::= EXPRESSION or AND_EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_AND_EXPR and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_OR and
					$stackthirdtop['type'] == RackCodeParseTree::SYNT_EXPR
				)
				{
					array_pop ($stack);
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_EXPR,
							'lineno' => $stacktop['lineno'],
							'left' => isset ($stackthirdtop['load']) ? $stackthirdtop['load'] : $stackthirdtop,
							'right' => isset ($stacktop['load']) ? $stacktop['load'] : $stacktop
						)
					);
					continue;
				}
				// EXPRESSION ::= AND_EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_AND_EXPR
				)
				{
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_EXPR,
							'lineno' => $stacktop['lineno'],
							'load' => isset ($stacktop['load']) ? $stacktop['load'] : $stacktop
						)
					);
					continue;
				}
				// GRANT ::= allow EXPRESSION | deny EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_EXPR and
					($stacksecondtop['type'] == RackCodeParseTree::LEX_ALLOW or $stacksecondtop['type'] == RackCodeParseTree::LEX_DENY)
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_GRANT,
							'lineno' => $stacktop['lineno'],
							'decision' => $stacksecondtop['type'],
							'condition' => isset ($stacktop['load']) ? $stacktop['load'] : $stacktop
						)
					);
					continue;
				}
				// DEFINITION ::= DEFINE EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_EXPR and
					$stacksecondtop['type'] == RackCodeParseTree::SYNT_DEFINE
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_DEFINITION,
							'lineno' => $stacktop['lineno'],
							'term' => $stacksecondtop['load'],
							'definition' => isset ($stacktop['load']) ? $stacktop['load'] : $stacktop
						)
					);
					continue;
				}
				// ADJUSTMENT ::= context CTXMODLIST on EXPRESSION
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_EXPR and
					$stacksecondtop['type'] == RackCodeParseTree::LEX_ON and
					$stackthirdtop['type'] == RackCodeParseTree::SYNT_CTXMODLIST and
					$stackfourthtop['type'] == RackCodeParseTree::LEX_CONTEXT
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					array_pop ($stack);
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_ADJUSTMENT,
							'lineno' => $stacktop['lineno'],
							'modlist' => $stackthirdtop['load'],
							'condition' => isset ($stacktop['load']) ? $stacktop['load'] : $stacktop
						)
					);
					continue;
				}
				// CODETEXT ::= CODETEXT GRANT | CODETEXT DEFINITION | CODETEXT ADJUSTMENT
				if
				(
					($stacktop['type'] == RackCodeParseTree::SYNT_GRANT or $stacktop['type'] == RackCodeParseTree::SYNT_DEFINITION or $stacktop['type'] == RackCodeParseTree::SYNT_ADJUSTMENT) and
					$stacksecondtop['type'] == RackCodeParseTree::SYNT_CODETEXT
				)
				{
					// reduce!
					array_pop ($stack);
					array_pop ($stack);
					$stacksecondtop['load'][] = $stacktop;
					$stacksecondtop['lineno'] = $stacktop['lineno'];
					array_push
					(
						$stack,
						$stacksecondtop
					);
					continue;
				}
				// CODETEXT ::= GRANT | DEFINITION | ADJUSTMENT
				if
				(
					$stacktop['type'] == RackCodeParseTree::SYNT_GRANT or
					$stacktop['type'] == RackCodeParseTree::SYNT_DEFINITION or
					$stacktop['type'] == RackCodeParseTree::SYNT_ADJUSTMENT
				)
				{
					// reduce!
					array_pop ($stack);
					array_push
					(
						$stack,
						array
						(
							'type' => RackCodeParseTree::SYNT_CODETEXT,
							'lineno' => $stacktop['lineno'],
							'load' => array ($stacktop)
						)
					);
					continue;
				}
			}
			// The fact we execute here means, that no reduction or early shift
			// has been done. The only way to enter another iteration is to "shift"
			// more, if possible. If shifting isn't possible due to empty input tape,
			// we are facing the final "accept"/"reject" dilemma. In this case our
			// work is done here, so return the whole stack to the calling function
			// to decide depending on what it is expecting.
			if ($done < $todo)
			{
				array_push ($stack, $lexems[$done++]);
				continue;
			}
			// The moment of truth.
			return $stack;
		}
	}
	# Accept a stack and figure out the cause of it not being parsed into a tree.
	# Return the line number or 'unknown'.
	protected function syntaxErrorLineno ($stack)
	{
		# The first SYNT_CODETEXT node, if is present, holds stuff already
		# successfully processed. Its line counter shows, where the last reduction
		# took place (it _might_ be the same line, which causes the syntax error).
		# The next node (it's very likely to exist) should have its line counter
		# pointing to the place, where the first (of 1 or more) error is located.
		if (isset ($stack[0]['type']) and $stack[0]['type'] == RackCodeParseTree::SYNT_CODETEXT)
			unset ($stack[0]);
		foreach ($stack as $node)
			# Satisfy with the first line number met.
			if (isset ($node['lineno']))
				return $node['lineno'];
		return 'unknown';
	}
	# Return nothing, if the given expression can be evaluated against the
	# given predicate list, but throw an exception otherwise. Note, that
	# the predicate list is NOT a predicate table (there are no definitions,
	# only terms).
	public static function assertPredicateConsistency ($plist, $expr)
	{
		$self = __FUNCTION__;
		switch ($expr['type'])
		{
			case RackCodeParseTree::LEX_TRUE:
			case RackCodeParseTree::LEX_FALSE:
			case RackCodeParseTree::LEX_TAG:
			case RackCodeParseTree::LEX_AUTOTAG:
				return;
			case RackCodeParseTree::LEX_PREDICATE:
				if (! in_array ($expr['load'], $plist))
					throw new RackCodeError ('Unknown predicate [' . $expr['load'] . ']', $expr['lineno']);
				return;
			case RackCodeParseTree::SYNT_NOT_EXPR:
				self::$self ($plist, $expr['load']);
				return;
			case RackCodeParseTree::SYNT_EXPR: # OR-expression
			case RackCodeParseTree::SYNT_AND_EXPR:
				self::$self ($plist, $expr['left']);
				self::$self ($plist, $expr['right']);
				return;
			default:
				throw new RackTablesError ('unexpected expression type', RackTablesError::INTERNAL);
		}
	}
}

class RackCodeFilterParser extends RackCodeParser
{
	protected $pList;
	function __construct ($pList)
	{
		$this->pList = $pList;
	}
	public function parse ($text)
	{
		$stack = self::getParseTree (self::getLexems (dos2unix ($text)));
		if (count ($stack) != 1 or $stack[0]['type'] != RackCodeParseTree::SYNT_EXPR)
			throw new RackCodeError ('syntax error', self::syntaxErrorLineno ($stack));
		$ret = array_key_exists ('load', $stack[0]) ? $stack[0]['load'] : $stack[0];
		unset ($stack);
		self::assertPredicateConsistency ($this->pList, $ret);
		return $ret;
	}
}

class RackCodePermissionsParser extends RackCodeParser
{
	public static function parse ($text)
	{
		if (!mb_strlen ($text))
			throw new RackCodeError ('empty input text');
		$stack = self::getParseTree (self::getLexems (dos2unix ($text)));
		if (count ($stack) != 1 or $stack[0]['type'] != RackCodeParseTree::SYNT_CODETEXT)
			throw new RackCodeError ('syntax error', self::syntaxErrorLineno ($stack));
		# An empty sentence list is semantically valid, yet senseless,
		# so checking intermediate result once more won't hurt.
		if (! count ($stack[0]['load']))
			throw new RackCodeError ('empty parse tree');
		# code below used to be in assertSemanticFilter()
		$predicatelist = array();
		foreach ($stack[0]['load'] as $sentence)
			switch ($sentence['type'])
			{
				case RackCodeParseTree::SYNT_DEFINITION:
					# A predicate can only be defined once.
					if (in_array ($sentence['term'], $predicatelist))
						throw new RackCodeError ("duplicate declaration of [${sentence['term']}]", $sentence['lineno']);
					# Check below makes sure, that definitions are built from already existing
					# tokens. This also makes recursive definitions impossible.
					self::assertPredicateConsistency ($predicatelist, $sentence['definition']);
					$predicatelist[] = $sentence['term'];
					break;
				case RackCodeParseTree::SYNT_GRANT:
					self::assertPredicateConsistency ($predicatelist, $sentence['condition']);
					break;
				case RackCodeParseTree::SYNT_ADJUSTMENT:
					# Only condition part gets tested, because it's normal to set
					# (or even to unset) something, that's not set.
					self::assertPredicateConsistency ($predicatelist, $sentence['condition']);
					break;
				default:
					throw new RackTablesError ('unknown sentence type', RackTablesError::INTERNAL);
			}
		return $stack[0]['load']; # payload of SYNT_CODETEXT is always here
	}
}

?>
