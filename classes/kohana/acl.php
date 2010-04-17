<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * ACL library
 *
 * @package    ACL
 * @author     Synapse Studios
 * @copyright  (c) 2010 Synapse Studios
 */
class Kohana_ACL {

	const CALLBACK_DEFAULT = '{default}';
	const KEY_WILDCARD = '?';
	const KEY_SEPARATOR = '|';

	/**
	 * @var  array  contains the instances (by request) of ACL
	 */
	protected static $_instances = array();

	/**
	 * @var  array  contains all the ACL rules
	 */
	protected static $_rules = array();

	/**
	 * Creates/Retrieves an instance of ACL based on the request. The first time
	 * this is called it also creates the default rule for ACL.
	 *
	 * @param   Request  The Kohana request object
	 * @return  ACL
	 */
	public static function instance(Request $request = NULL)
	{
		// Set the default rule when creating the first instance
		if ( ! isset(self::$_rules[ACL::KEY_SEPARATOR.ACL::KEY_SEPARATOR]))
		{
			// Create and add a default rule
			ACL::add_rule(ACL::rule());
		}

		// If no request was specified, then use the current, main request
		if ($request === NULL)
		{
			$request = Request::instance();
		}

		// Find the key for this request
		$key = ACL::key($request->directory, $request->controller, $request->action);

		// Register the instance if it doesn't exist
		if ( ! isset(self::$_instances[$key]))
		{
			self::$_instances[$key] = new self($request);
		}

		return self::$_instances[$key];
	}

	/**
	 * Factory for an ACL rule
	 *
	 * @return  ACL_Rule
	 */
	public static function rule()
	{
		// Return an ACL rule
		return new ACL_Rule;
	}

	/**
	 * Validates and adds an ACL_Rule to the rules array
	 *
	 * @param   ACL_Rule  The rule to add
	 * @return  void
	 */
	public static function add_rule(ACL_Rule $rule)
	{
		// Check if the rule is valid, if not throw an exception
		if ( ! $rule->valid())
			throw new ACL_Exception('The ACL Rule was invalid and could not be added.');

		// Find the rule's key and add it to the array of rules
		$key = $rule->key();
		self::$_rules[$key] = $rule;
	}
	
	/**
	 * Remove all previously-added rules
	 *
	 * @return  void
	 */
	public static function clear_rules()
	{
		// Remove all rules
		self::$_rules = array();
		
		// Decompile existing rules for ACL instances
		ACL::clear_compiled_rules();
		
		// Re-add a default rule
		ACL::add_rule(ACL::rule());
	}
	
	/**
	 * Decompile existing rules for ACL instances
	 *
	 * @return  void
	 */
	public static function clear_compiled_rules()
	{
		foreach (self::$_instances as $acl)
		{
			$acl->initialize_rule();
		}
	}

	/**
	 * Creates a unique key from an array of 3 parts representing a rule's scope
	 *
	 * @param   mixed  A part or an array of scope parts
	 * @return  string
	 */
	public static function key($directory, $controller = NULL, $action = NULL)
	{
		// Get the parts (depends on the arguments)
		if (is_array($directory) AND count($directory) === 3)
		{
			$parts = $directory;
		}
		else
		{
			$parts = compact('directory', 'controller', 'action');
		}

		// Create the key
		$key = implode(ACL::KEY_SEPARATOR, $parts);

		return $key;
	}

	/**
	 * This method resolves any wildcards in ACL rules that are created when
	 * using the `for_current_*()` methods to the actual values from the current
	 * request.
	 *
	 * @param   array  An array of the 3 scope parts
	 * @return  void
	 */
	protected static function resolve_rules($scope)
	{
		$resolved = array();

		// Loop through the rules and resolve all wildcards
		foreach (self::$_rules as $key => $rule)
		{
			if (strpos($key, ACL::KEY_WILDCARD) !== FALSE)
			{
				// Separate the key into its parts
				$parts = explode(ACL::KEY_SEPARATOR, $key);

				// Resolve the directory
				if ($parts[0] == ACL::KEY_WILDCARD)
				{
					$parts[0] = $scope['directory'];
				}

				// Resolve the controller
				if ($parts[1] == ACL::KEY_WILDCARD)
				{
					$parts[1] = $scope['controller'];
				}

				// Resolve the action
				if ($parts[2] == ACL::KEY_WILDCARD)
				{
					$parts[2] = $scope['action'];
				}

				// Put the key back together
				$rule_key = ACL::key($parts);
				
				// Create a key for the scope
				$scope_key = ACL::key($scope);
				
				// If the rule is in auto mode and it applies to the current scope, resolve the capability name
				if ($rule->in_auto_mode() AND $rule_key === $scope_key)
				{
					$rule->auto_capability($scope['controller'], $scope['action']);
				}
			}

			$resolved[$rule_key] = $rule;
		}

		// Replace the keys with the resolved ones
		self::$_rules = $resolved;
	}



	/**
	 * @var  Request  The request object to which this instance of ACL is for
	 */
	protected $request = NULL;

	/**
	 * @var  Model_User  The current use as retreived by the Auth module
	 */
	protected $user = NULL;

	/**
	 * @var  array  Contains the compiled rule that will apply to the user
	 */
	protected $rule = NULL;

	/**
	 * Constructs a new ACL object for a request
	 *
	 * @param   Request  The request object
	 * @return  void
	 */
	protected function __construct(Request $request)
	{
		// Store the request for this instance
		$this->request = $request;
		
		// Get the user (via Auth)
		$this->user = Auth::instance()->get_user();
		if ( ! $this->user)
		{
			$this->user = ORM::factory('user');
		}

		// Initialize the rule
		$this->initialize_rule();
	}

	/**
	 * Returns the "scope" of this request. These values help determine which
	 * ACL rule applies to the user
	 *
	 * @return  array
	 */
	public function scope()
	{
		return array
		(
			'directory'  => $this->request->directory,
			'controller' => $this->request->controller,
			'action'     => $this->request->action,
		);
	}

	/**
	 * This is the procedural method that executes ACL logic and responds
	 *
	 * @return  void
	 */
	public function authorize()
	{
		// Compile the rules
		$this->compile();
			
		// Check if this user has access to this request
		if ($this->user_authorized())
			return TRUE;

		// Set the HTTP status to 403 - Access Denied
		$this->request->status = 403;

		// Execute the callback (if any) from the compiled rule
		$this->perform_callback();

		// Throw a 403 Exception if no callback has altered program flow
		throw new Kohana_Request_Exception('You are not authorized to access this resource.', NULL, 403);
	}
	
	/**
	 * Initialize the compiled rule to be empty
	 *
	 * @return  ACL
	 */
	public function initialize_rule()
	{
		$this->rule = array
		(
			'roles'        => array(),
			'capabilities' => array(),
			'users'        => array(),
			'callbacks'    => array(),
		);
		
		return $this;
	}

	/**
	 * Determines if a user is authorized based on the compiled rule. It
	 * examines things in the following order:
     *
	 * 1. Does the user have the super role?
	 * 2. Is the user's ID in the allow list?
	 * 3. Does the user have all of the required capabilities?
	 * 4. Does the user have at least one of the required roles?
	 *
	 * @return  boolean
	 */
	protected function user_authorized()
	{
		// If the user has the super role, then allow access
		$super_role = Kohana::config('acl.super_role');
		if ($super_role AND in_array($super_role, $this->user->roles_list()))
			return TRUE;
		// If the user is in the user list, then allow access
		if (in_array($this->user->id, $this->rule['users']))
			return TRUE;
			
		// If the user has all (AND) the capabilities, then allow access
		$difference = array_diff($this->rule['capabilities'], $this->user->capabilities_list());
		if ( ! empty($this->rule['capabilities']) AND empty($difference))
			return TRUE;

		// If there were no capabilities allowed, check the roles
		if (empty($this->rule['capabilities']))
		{
			// If the user has one (OR) the roles, then allow access
			$intersection = array_intersect($this->rule['roles'], $this->user->roles_list());
			if ( ! empty($intersection))
				return TRUE;
		}
		
		return FALSE;
	}

	/**
	 * Performs a matching callback as defined in he compiled rule. It looks at
	 * all the callbacks and executes the first one that matches the user's
	 * role or the default callback if defined. Otherwise, it does nothing.
	 *
	 * @return  void
	 */
	protected function perform_callback()
	{		
		// Loop through the callbacks
		foreach ($this->rule['callbacks'] as $role => $callback)
		{
			// If the user matches the role (or it's a default), execute it
			if ($role === ACL::CALLBACK_DEFAULT OR $this->user->is_a($role))
			{
				call_user_func_array($callback['function'], $callback['args']);
				return;
			}
		}
	}

	/**
	 * Compiles the rules based on the scope into a single rule.
	 *
	 * @return  void
	 */
	protected function compile()
	{
		// Initialize an array for the applicable rules
		$applicable_rules = array();
		
		// Get the scope for this instance of ACL
		$scope = $this->scope();

		// Resolve rules that currently have wildcards
		ACL::resolve_rules($scope);
		
		// Re-index the scope array with numbers for looping
		$scope = array_values($scope);
		
		// Get all the rules that could apply to this request
		for ($i = 2; $i >= 0; $i--)
		{
			// Get the key for the scope
			$key = ACL::key($scope);
			
			// Look in the rules array for a rule matching the key
			if ($rule = Arr::get(self::$_rules, $key, FALSE))
			{
				$applicable_rules[$key] = $rule;
			}

			// Remove part of the scope so the next iteration can cascade to another rule
			$scope[$i] = '';
		}
		
		// Get default rule
		$default_key = ACL::KEY_SEPARATOR.ACL::KEY_SEPARATOR;
		$applicable_rules[$default_key] = Arr::get(self::$_rules, $default_key);

		// Reverse the rules. Compile from the bottom up
		$applicable_rules = array_reverse($applicable_rules);
		
		echo Kohana::debug($applicable_rules); die;

		// Compile the rule
		foreach ($applicable_rules as $rule)
		{
			$this->rule = Arr::overwrite($this->rule, $rule->as_array());
		}
	}

} // End ACL

// ACL Exception
class ACL_Exception extends Kohana_Exception {}