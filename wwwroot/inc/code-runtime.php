<?php

abstract class RackCodeParseTree
{
	const LEX_LBRACE      = 100;
	const LEX_RBRACE      = 101;
	const LEX_ALLOW       = 102;
	const LEX_DENY        = 103;
	const LEX_DEFINE      = 104;
	const LEX_TRUE        = 105;
	const LEX_FALSE       = 106;
	const LEX_NOT         = 107;
	const LEX_TAG         = 108;
	const LEX_AUTOTAG     = 109;
	const LEX_PREDICATE   = 110;
	const LEX_AND         = 111;
	const LEX_OR          = 112;
	const LEX_CONTEXT     = 113;
	const LEX_CLEAR       = 114;
	const LEX_INSERT      = 115;
	const LEX_REMOVE      = 116;
	const LEX_ON          = 117;
	const SYNT_UNARY_EXPR = 201; # an unary expression
	const SYNT_NOT_EXPR   = 202; # 1 arg in "load"
	const SYNT_AND_EXPR   = 203; # two args in "left" and "right"
	const SYNT_EXPR       = 204; # idem, in fact it's boolean OR, but we keep the naming for compatibility
	const SYNT_DEFINE     = 205; # 1 arg in "term"
	const SYNT_DEFINITION = 206; # +1 arg in "definition"
	const SYNT_GRANT      = 207; # 2 args in "decision" and "condition"
	const SYNT_CTXMOD     = 208;
	const SYNT_CTXMODLIST = 209;
	const SYNT_ADJUSTMENT = 210; # context modifier with action(s) and condition
	const SYNT_CODETEXT   = 211; # list of sentences in "load"

	protected $code;
	public function __construct ($code)
	{
		$this->code = $code;
	}
	protected function eval_expression ($expr, $tagchain, $ptable)
	{
		$self = __FUNCTION__;
		switch ($expr['type'])
		{
			// Return true, if given tag is present on the tag chain.
			case self::LEX_TAG:
			case self::LEX_AUTOTAG:
				foreach ($tagchain as $tagInfo)
					if ($expr['load'] == $tagInfo['tag'])
						return TRUE;
				return FALSE;
			case self::LEX_PREDICATE: // Find given predicate in the symbol table and evaluate it.
				# RackCodeFilterParser::parse does not detect this condition
				if (! array_key_exists ($expr['load'], $ptable))
					throw new RackCodeError ('Unknown predicate [' . $expr['load'] . ']', $expr['lineno']);
				return self::$self ($ptable[$expr['load']], $tagchain, $ptable);
			case self::LEX_TRUE:
				return TRUE;
			case self::LEX_FALSE:
				return FALSE;
			case self::SYNT_NOT_EXPR:
				$tmp = self::$self ($expr['load'], $tagchain, $ptable);
				if ($tmp === TRUE)
					return FALSE;
				elseif ($tmp === FALSE)
					return TRUE;
				else
					throw new RackTablesError ('Malformed SYNT_NOT_EXPR node', RackTablesError::INTERNAL);
			case self::SYNT_AND_EXPR: # binary AND
				if (FALSE == self::$self ($expr['left'], $tagchain, $ptable))
					return FALSE; # early failure
				return self::$self ($expr['right'], $tagchain, $ptable);
			case self::SYNT_EXPR: # binary OR
				if (TRUE == self::$self ($expr['left'], $tagchain, $ptable))
					return TRUE; # early success
				return self::$self ($expr['right'], $tagchain, $ptable);
			default:
				throw new RackTablesError ("Unknown expression type '${expr['type']}'", RackTablesError::INTERNAL);
		}
	}
}

class RackCodeFilter extends RackCodeParseTree
{
	protected $pTable;
	function __construct ($code, $pTable)
	{
		parent::__construct ($code);
		$this->pTable = $pTable;
	}
	public function fits ($cell)
	{
		return self::eval_expression
		(
			$this->code,
			array_merge
			(
				$cell['etags'],
				$cell['itags'],
				$cell['atags']
			),
			$this->pTable
		);
	}
	public function filterList ($list_in)
	{
		# A special thing about expressions is that an empty expression is
		# assumed to be equal to "true" (an empty filter means no filter).
		if (!count ($this->code))
			return $list_in;
		$list_out = array();
		foreach ($list_in as $item_key => $item_value)
			if (self::fits ($item_value))
				$list_out[$item_key] = $item_value;
		return $list_out;
	}
}

class RackCodePermissions extends RackCodeParseTree
{
	protected $pTable;
	function __construct ($code)
	{
		parent::__construct ($code);
		$this->pTable = array();
		foreach ($this->code as $sentence)
			if ($sentence['type'] == self::SYNT_DEFINITION)
				$this->pTable[$sentence['term']] = $sentence['definition'];
	}
	# Process a context adjustment request, update given chain accordingly,
	# return TRUE on any changes done.
	# The request is a sequence of clear/insert/remove requests exactly as cooked
	# for each SYNT_CTXMODLIST node.
	protected function processAdjustmentSentence ($modlist, &$chain)
	{
		$didChanges = FALSE;
		foreach ($modlist as $mod)
			switch ($mod['op'])
			{
				case 'insert':
					foreach ($chain as $etag)
						if ($etag['tag'] == $mod['tag']) # already there, next request
							break 2;
					if (NULL === $search = getTagByName ($mod['tag'])) # skip martians silently
						break;
					$chain[] = $search;
					$didChanges = TRUE;
					break;
				case 'remove':
					foreach ($chain as $key => $etag)
						if ($etag['tag'] == $mod['tag']) # delete first match and return
						{
							unset ($chain[$key]);
							$didChanges = TRUE;
							break 2;
						}
					break;
				case 'clear':
					$chain = array();
					$didChanges = TRUE;
					break;
				default:
					throw new RackTablesError ('invalid structure', RackTablesError::INTERNAL);
			}
		return $didChanges;
	}
	public function gotClearance ($expl_tags, $impl_tags, $const_base)
	{
		# Below is an own copy of predicate table to make it possible to detect
		# "used-before-declaration" semantic errors. FIXME: this specific check
		# seems to be already performed at parse time.
		$ptable = array();
		foreach ($this->code as $sentence)
		{
			switch ($sentence['type'])
			{
				case self::SYNT_DEFINITION:
					$ptable[$sentence['term']] = $sentence['definition'];
					break;
				case self::SYNT_GRANT:
					if (self::eval_expression ($sentence['condition'], array_merge ($const_base, $expl_tags, $impl_tags), $ptable))
						switch ($sentence['decision'])
						{
							case self::LEX_ALLOW:
								return TRUE;
							case self::LEX_DENY:
								return FALSE;
							default:
								throw new RackTablesError ("Condition match for unknown grant decision '${sentence['decision']}'", RackTablesError::INTERNAL);
						}
					break;
				case self::SYNT_ADJUSTMENT:
					if
					(
						self::eval_expression ($sentence['condition'], array_merge ($const_base, $expl_tags, $impl_tags), $ptable) and
						processAdjustmentSentence ($sentence['modlist'], $expl_tags)
					) # recalculate implicit chain only after actual change, not just on matched condition
						$impl_tags = getImplicitTags ($expl_tags);
					break;
				default:
					throw new RackTablesError ("Can't process sentence of unknown type '${sentence['type']}'", RackTablesError::INTERNAL);
			}
		}
		# default policy is to deny
		return FALSE;
	}
	public function createFilter ($text)
	{
		$parser = new RackCodeFilterParser (array_keys ($this->pTable));
		return new RackCodeFilter ($parser->parse ($text), $this->pTable);
	}
}

?>
