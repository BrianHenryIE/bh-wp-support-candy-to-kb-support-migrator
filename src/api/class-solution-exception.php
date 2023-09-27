<?php
/**
 * An Exception that suggests the solution.
 *
 * @package brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\API;

use Exception;
use Throwable;

/**
 * A simple extension of Exception.
 */
class Solution_Exception extends Exception {

	/**
	 * A hint/description of the solution.
	 */
	protected string $solution;

	/**
	 * Constructor.
	 *
	 * @param string     $message A message to print when the error occurs.
	 * @param string     $solution A hint or suggested solution.
	 * @param int        $code A uid.
	 * @param ?Throwable $previous Earlier caught exceptions.
	 */
	public function __construct( $message = '', $solution = '', $code = 0, Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );

		$this->solution = $solution;
	}

	/**
	 * Get the description for the solution.
	 */
	public function get_solution(): string {
		return $this->solution;
	}
}
