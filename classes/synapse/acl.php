<?php defined('SYSPATH') or die('No direct access allowed.');
/**
 * ACL
 *
 * @package    ACL
 * @author     Synapse Studios
 * @author     Jeremy Lindblom <jeremy@synapsestudios.com>
 * @copyright  (c) 2010 Synapse Studios
 */
class Synapse_ACL {

	/**
	 * Factory method for creating a chainable instance
	 *
	 * @chainable
	 * @static
	 * @return ACL
	 */
	public static function factory(ACL_Rule_List $rules)
	{
		return new ACL($rules);
	}

	/**
	 * @var  ACL_Rule_List  The list of rules for ACL
	 */
	protected $_rules;

	/**
	 * Constructs a new ACL object for a request
	 *
	 * @param   Request  The request
	 * @return  void
	 */
	protected function __construct(ACL_Rule_List $rules)
	{
		// The rules for ACL
		$this->_rules = $rules;
	}

	/**
	 * Check if a user is allowed to the request based on the ACL rules
	 *
	 *     $rules = new ACL_Rule_list;
	 *     $user = Auth::instance()->get_user();
	 *     $request = Request::factory('account/upgrade');
	 *     $allowed = ACL::factory($rules)->is_authorized($user, $request);
	 *
	 * @param   Model_ACL_User  The user to authorize
	 * @param   ACL_Request  The request to authorize the user for
	 * @return  boolean
	 */
	public function is_authorized(Model_ACL_User $user, ACL_Request $request)
	{
		// Compile the rules
		$rule = $this->_rules->compile($request);

		// Check if this user has access to this request
		return $rule->user_is_authorized($user);
	}

	/**
	 * This is the procedural method that executes ACL logic and responses
	 *
	 * @param   Model_ACL_User  The user to authorize
	 * @param   ACL_Request  The request to authorize the user for
	 * @return  void
	 */
	public function authorize(Model_ACL_User $user, ACL_Request $request)
	{
		// Only run checks if the rule list has rules
		if ($this->_rules->is_empty())
			return;

		// Compile the rules
		$rule = $this->_rules->compile($request);

		// Check if this user has access to this request
		if ( ! $rule->user_is_authorized($user))
		{
			// Execute the callback (if any) from the compiled rule
			if ($callback = $rule->callback_for_user($user))
			{
				call_user_func_array($callback['function'], $callback['args']);
			}

			// Throw a 401 exception (if the callback has altered program flow
			throw new HTTP_Exception_401;
		}
	}

}
