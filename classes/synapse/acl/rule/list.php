<?php defined('SYSPATH') or die('No direct script access.');
/**
 * ACL Rule List
 *
 * @package    ACL
 * @author     Synapse Studios
 * @author     Jeremy Lindblom <jeremy@synapsestudios.com>
 * @copyright  (c) 2010 Synapse Studios
 */
class Synapse_ACL_Rule_List implements Iterator, Countable, Serializable {

	public static function factory()
	{
		return new ACL_Rule_List;
	}

	public static function from_array(array $array)
	{
		$rules = new ACL_Rule_List;
		return $rules;
	}

	protected $_rules = array();

	public function add(ACL_Rule $rule)
	{
		$this->_rules[] = $rule;

		return $this;
	}

	/**
	 * Compiles the rule from all applicable rules to this request
	 *
	 * @return  ACL_Rule  The compiled rule
	 */
	public function compile(ACL_Request $request)
	{
		// Resolve and separate multi-action rules
		$resolved_rules = array();
		foreach ($this->_rules as $rule)
		{
			$resolved_rules = array_merge($resolved_rules, $rule->resolve_for_request($request));
		}

		// Create a blank, base rule to compile down to
		$compiled_rule = new ACL_Rule;

		// Merge rules together that apply to this request
		foreach ($resolved_rules as $rule)
		{
			if ($rule->applies_to_request($request))
			{
				$compiled_rule = $compiled_rule->merge($rule);
			}
		}

		return $compiled_rule;
	}

	public function as_array()
	{
		return $this->_rules;
	}

	public function count()
	{
		return count($this->_rules);
	}

	public function current()
	{
		return current($this->_rules);
	}

	public function key()
	{
		return key($this->_rules);
	}

	public function next()
	{
		next($this->_rules);

		return $this;
	}

	public function rewind()
	{
		reset($this->_rules);

		return $this;
	}

	public function valid()
	{
		return (current($this->_rules) !== FALSE);
	}

	public function is_empty()
	{
		return (bool) count($this->_rules);
	}

	public function clear()
	{
		$this->_rules = array();

		return $this;
	}

	public function serialize()
	{
		return serialize($this->_rules);
	}

	public function unserialize($serialized)
	{
		$this->_rules = unserialize($serialized);
	}

}
