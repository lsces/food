<?php
/**
 * Food package front page — for now, just the day-picker/Calendar nav bar (mirrors
 * health/index.php's General-tab bar) so Food Day and Food Calendar are reachable
 * from one place. Further dashboard content can grow here later, same way health's
 * did, starting from just this bar.
 *
 * @package food
 */

namespace Bitweaver\Food;

use Bitweaver\KernelTools;

require_once '../kernel/includes/setup_inc.php';

global $gBitSystem, $gBitSmarty;

$gBitSystem->verifyPackage( 'food' );
$gBitSystem->verifyPermission( 'p_food_view' );

$gBitSystem->display( 'bitpackage:food/index.tpl', KernelTools::tra( 'Food' ) );
