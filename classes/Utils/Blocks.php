<?php
/**
 * Block utilities.
 *
 * @since       1.14
 * @package     PUM
 * @copyright   Copyright (c) 2020, Code Atlantic LLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PUM_Utils_Blocks
 */
class PUM_Utils_Blocks {

	/**
	 * Find blocks with matching name in given list of blocks.
	 *
	 * @param array  $blocks Array of blocks.
	 * @param string $searchName Block name to look for.
	 *
	 * @return array
	 */
	public static function find_blocks( $blocks, $searchName = 'pum/*' ) {
		$foundBlocks = [];

		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'], [ 'core/columns', 'core/column' ], true ) ) {
				$foundBlocks = array_merge( $foundBlocks, self::find_blocks( $block['innerBlocks'] ) );
			}

			if ( $searchName === $block['blockName'] ) {
				$foundBlocks[] = $block;
			}
		}

		return $foundBlocks;
	}

}
