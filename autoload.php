<?php
/**
 * Loads all required classes
 *
 * Uses classmap autoloader and WPCS autoloader.
 *
 * @link              http://example.com
 * @since             1.0.0
 * @package           brianhenryie/bh-wp-support-candy-to-kb-support-migrator
 */

namespace BrianHenryIE\WP_Support_Candy_KB_Support_Migrator;

use BrianHenryIE\WP_Support_Candy_KB_Support_Migrator\Alley_Interactive\Autoloader\Autoloader;

// Load strauss classes after autoload-classmap.php so classes can be substituted.
require_once __DIR__ . '/vendor-prefixed/autoload.php';

Autoloader::generate(
	'BrianHenryIE\WP_Support_Candy_KB_Support_Migrator',
	__DIR__ . '/src',
)->register();
