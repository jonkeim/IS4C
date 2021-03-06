<?php
/*******************************************************************************

    Copyright 2010 Whole Foods Co-op

    This file is part of Fannie.

    Fannie is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    Fannie is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

include('../../config.php');
include($FANNIE_ROOT.'classlib2.0/FannieAPI.php');

$upc = FormLib::get_form_value('upc');
$action = FormLib::get_form_value('action','unknown');
switch($action){
case 'addVarPricing':
	$prep = $dbc->prepare_statement("UPDATE prodExtra SET variable_pricing=1 WHERE upc=?");
	$dbc->exec_statement($prep,array($upc));
	break;
case 'delVarPricing':
	$prep = $dbc->prepare_statement("UPDATE prodExtra SET variable_pricing=0 WHERE upc=?");
	$dbc->exec_statement($prep,array($upc));
	break;
case 'newPrice':
	$vid = FormLib::get_form_value('vendorID');
	$bid = FormLib::get_form_value('batchID');
	$sid = FormLib::get_form_value('superID',0);
	if ($sid == 99) $sid = 0;
	$sid = FormLib::get_form_value('price',0);
	$sP = $dbc->prepare_statement("UPDATE vendorSRPs SET srp=? WHERE upc=? AND vendorID=?");
	$dbc->exec_statement($sP,array($price,$upc,$vid));
	$bP = $dbc->prepare_statement("UPDATE batchList SET salePrice=? WHERE upc=? AND batchID=?");
	$dbc->exec_statement($bP,array($price,$upc,$bid));
	$bP = $dbc->prepare_statement("UPDATE shelftags SET normal_price=? WHERE upc=? AND id=?");
	$dbc->exec_statement($bP,array($price,$upc,$sid));
	echo "New Price Applied";
	break;
case 'batchAdd':
	$vid = FormLib::get_form_value('vendorID');
	$bid = FormLib::get_form_value('batchID');
	$sid = FormLib::get_form_value('superID',0);
	if ($sid == 99) $sid = 0;
	$sid = FormLib::get_form_value('price',0);

	/* add to batch */
	$batchQ = $dbc->prepare_statement("INSERT INTO batchList (upc,batchID,salePrice,active)
		VALUES (?,?,?,0)");
	$batchR = $dbc->exec_statement($batchQ,array($upc,$bid,$price));

	/* get shelftag info */
	$infoQ = $dbc->prepare_statement("SELECT p.description,v.brand,v.sku,v.size,v.units,b.vendorName
		FROM products AS p LEFT JOIN vendorItems AS v ON p.upc=v.upc AND
		v.vendorID=? LEFT JOIN vendors AS b ON v.vendorID=b.vendorID
		WHERE p.upc=?");
	$info = $dbc->fetch_row($dbc->exec_statement($infoQ,array($vid,$upc)));
	$ppo = PriceLib:;pricePerUnit($price,$info['size']);
	
	/* create a shelftag */
	$stQ = $dbc->prepare_statement("DELETE FROM shelftags WHERE upc=? AND id=?");
	$stR = $dbc->exec_statement($stQ,array($upc,$sid));
	$addQ = $dbc->prepare_statement("INSERT INTO shelftags VALUES (?,?,?,?,?,?,?,?,?,?)");
	$args = array($sid,$upc,$info['description'],$price,
			$info['brand'],$info['sku'],
			$info['size'],$info['units'],$info['vendorName'],
			$ppo);
	$addR = $dbc->exec_statement($addQ,$args);

	break;
case 'batchDel':
	$vid = FormLib::get_form_value('vendorID');
	$bid = FormLib::get_form_value('batchID');
	$sid = FormLib::get_form_value('superID',0);
	if ($sid == 99) $sid = 0;

	$batchQ = $dbc->prepare_statement("DELETE FROM batchList WHERE batchID=? AND upc=?");
	$batchR = $dbc->exec_statement($batchQ,array($bid,$upc));

	$stQ = $dbc->prepare_statement("DELETE FROM shelftags WHERE upc=? AND id=?");
	$stR = $dbc->exec_statement($stQ,array($upc,$sid));

	break;
}

?>
