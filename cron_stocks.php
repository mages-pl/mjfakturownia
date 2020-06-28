<?php
/**
 * Module Mjfakturownia
 * @author MAGES Michał Jendraszczyk
 * @copyright (c) 2020, MAGES Michał Jendraszczyk
 * @license http://mages.pl MAGES Michał Jendraszczyk
 */

include_once '../../config/config.inc.php';
include_once 'mjfakturownia.php';

$fakturownia = new Mjfakturownia();
$fakturownia->syncQty(2);
echo "OK (time ".time()."s)";
