<?php defined('SYSPATH') or die('No direct access allowed.');
/**
 * ORM Auth driver.
 *
 * @package    Kohana/Auth
 * @author     Kohana Team
 * @copyright  (c) 2007-2010 Kohana Team
 * @license    http://kohanaframework.org/license
 */
class Kohana_Auth_ORM extends Auth {

	public function hash_password($password, $salt = FALSE)
	{
		$hash = $this->hash($password);
		return $hash;
	}

	public function hash($str)
	{
		return $this->_enc->hash($str);
	}	
	
	
	public function login($username, $password, $remember = FALSE)
	{
		if (empty($password))
			return FALSE;
			
		return $this->_login($username, $password, $remember);
	}
	
	protected function _login($email, $password, $remember)
	{
		if ( ! is_object($user))
		{
			$username = $user;

			// Load the user
			$user = ORM::factory('user');
			$user->where('email', '=', $email)->find();
		}

		// If the passwords match, perform a login
		// if ($user->has('roles', ORM::factory('role', array('name' => 'login'))) AND 
		// 	$this->_enc->compare_hash($password, $user->password)
		// )
		if($this->_enc->compare_hash($password, $user->password_hash))
		{
			if ($remember === TRUE)
			{
				// Create a new autologin token
				$token = ORM::factory('user_token');

				// Set token data
				$token->user_id = $user->id;
				$token->expires = time() + $this->_config['lifetime'];
				$token->save();

				// Set the autologin cookie
				Cookie::set('authautologin', $token->token, $this->_config['lifetime']);
			}

			// Finish the login
			$this->complete_login($user);

			return TRUE;
		}

		// Login failed
		return FALSE;
	}
	

	/**
	 * Gets the currently logged in user from the session.
	 * Returns FALSE if no user is currently logged in.
	 *
	 * @return  mixed
	 */
	public function get_user()
	{
		$user = $this->_session->get($this->_config['session_key'], FALSE);
		if(false!==$user)
		{
			$user = ORM::factory('User', $user);
		}else{
			$user = $this->auto_login();
		}
		return $user;
	}
	
	protected function complete_login($user)
	{
		$user->complete_login();
		// Regenerate session_id
		$this->_session->regenerate();

		// Store username in session
		$this->_session->set($this->_config['session_key'], $user->pk());

		return TRUE;
	}
	/**
	 * Checks if a session is active.
	 *
	 * @param   mixed    role name string, role ORM object, or array with role names
	 * @return  boolean
	 */
	public function logged_in($role = NULL)
	{
		$status = FALSE;

		// Get the user from the session
		$user = $this->get_user();

		if (is_object($user) AND $user instanceof Model_User AND $user->loaded())
		{
			// Everything is okay so far
			$status = TRUE;

			if ( ! empty($role))
			{
				// Multiple roles to check
				if (is_array($role))
				{
					// Check each role
					foreach ($role as $_role)
					{
						if ( ! is_object($_role))
						{
							$_role = ORM::factory('role', array('name' => $_role));
						}

						// If the user doesn't have the role
						if ( ! $user->has('roles', $_role))
						{
							// Set the status false and get outta here
							$status = FALSE;
							break;
						}
					}
				}
				// Single role to check
				else
				{
					if ( ! is_object($role))
					{
						// Load the role
						$role = ORM::factory('role', array('name' => $role));
					}

					// Check that the user has the given role
					$status = $user->has('roles', $role);
				}
			}
		}

		return $status;
	}



	/**
	 * Forces a user to be logged in, without specifying a password.
	 *
	 * @param   mixed    username string, or user ORM object
	 * @param   boolean  mark the session as forced
	 * @return  boolean
	 */
	public function force_login($user, $mark_session_as_forced = FALSE)
	{
		if ( ! is_object($user))
		{
			$username = $user;

			// Load the user
			$user = ORM::factory('user');
			$user->where($user->unique_key($username), '=', $username)->find();
		}

		if ($mark_session_as_forced === TRUE)
		{
			// Mark the session as forced, to prevent users from changing account information
			$this->_session->set('auth_forced', TRUE);
		}

		// Run the standard completion
		$this->complete_login($user);
	}

	/**
	 * Logs a user in, based on the authautologin cookie.
	 *
	 * @return  mixed
	 */
	public function auto_login()
	{
		if ($token = Cookie::get('authautologin'))
		{
			// Load the token and user
			$token = ORM::factory('user_token', array('token' => $token));

			if ($token->loaded() AND $token->user->loaded())
			{
				if ($token->user_agent === sha1(Request::$user_agent))
				{
					// Save the token to create a new unique token
					$token->save();

					// Set the new token
					Cookie::set('authautologin', $token->token, $token->expires - time());

					// Complete the login with the found data
					$this->complete_login($token->user);

					// Automatic login was successful
					return $token->user;
				}

				// Token is invalid
				$token->delete();
			}
		}

		return FALSE;
	}


	/**
	 * Log a user out and remove any autologin cookies.
	 *
	 * @param   boolean  completely destroy the session
	 * @param	boolean  remove all tokens for user
	 * @return  boolean
	 */
	public function logout($destroy = FALSE, $logout_all = FALSE)
	{
		// Set by force_login()
		$this->_session->delete('auth_forced');

		if ($token = Cookie::get('authautologin'))
		{
			// Delete the autologin cookie to prevent re-login
			Cookie::delete('authautologin');

			// Clear the autologin token from the database
			$token = ORM::factory('user_token', array('token' => $token));

			if ($token->loaded() AND $logout_all)
			{
				ORM::factory('user_token')->where('user_id', '=', $token->user_id)->delete_all();
			}
			elseif ($token->loaded())
			{
				$token->delete();
			}
		}

		return parent::logout($destroy);
	}

	/**
	 * Get the stored password for a username.
	 *
	 * @param   mixed   username string, or user ORM object
	 * @return  string
	 */
	public function password($user)
	{
		if ( ! is_object($user))
		{
			$user = ORM::factory('user', $user);
		}

		return $user->password_hash;
	}


	/**
	 * Compare password with original (hashed). Works for current (logged in) user
	 *
	 * @param   string  $password
	 * @return  boolean
	 */
	public function check_password($password)
	{
		$user = $this->get_user();

		if ($user === FALSE)
		{
			// nothing to compare
			return FALSE;
		}

		$hash = $this->hash_password($password);

		return $hash == $user->password_hash;
	}

} // End Auth ORM