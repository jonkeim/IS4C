<?php
/*******************************************************************************

    Copyright 2009 Whole Foods Co-op

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

require('../../config.php');
require($FANNIE_ROOT.'src/mysql_connect.php');

$layout = (isset($_REQUEST['layout']))?$_REQUEST['layout']:$FANNIE_DEFAULT_PDF;
$layout = str_replace(" ","_",$layout);
$offset = (isset($_REQUEST['offset']) && is_numeric($_REQUEST['offset']))?$_REQUEST['offset']:0;
$data = array();

if (isset($_REQUEST['id'])){
	$query = "SELECT s.*,p.scale FROM shelftags AS s
		INNER JOIN products AS p ON s.upc=p.upc
		WHERE s.id=".$_REQUEST['id']." ORDER BY
		p.department,s.upc";
	$result = $dbc->query($query);

	while($row = $dbc->fetch_row($result)){
		$myrow = array(
		'normal_price' => $row['normal_price'],
		'description' => $row['description'],
		'brand' => $row['brand'],
		'units' => $row['units'],
		'size' => $row['size'],
		'sku' => $row['sku'],
		'pricePerUnit' => $row['pricePerUnit'],
		'upc' => $row['upc'],
		'vendor' => $row['vendor'],
		'scale' => $row['scale']
		);			
		$data[] = $myrow;
	}
}
elseif (isset($_REQUEST['batchID'])){
	$batchIDList = '';
	foreach($_GET['batchID'] as $x)
		$batchIDList .= $x.',';
	$batchIDList = substr($batchIDList,0,strlen($batchIDList)-1);
	$testQ = "select b.*,p.scale
		FROM batchBarcodes as b INNER JOIN products AS p
		ON b.upc=p.upc WHERE batchID in ($batchIDList) and b.description <> ''
		ORDER BY batchID";
	$result = $dbc->query($testQ);
	while($row = $dbc->fetch_row($result)){
		$myrow = array(
		'normal_price' => $row['normal_price'],
		'description' => $row['description'],
		'brand' => $row['brand'],
		'units' => $row['units'],
		'size' => $row['size'],
		'sku' => $row['sku'],
		'pricePerUnit' => '',
		'upc' => $row['upc'],
		'vendor' => $row['vendor'],
		'scale' => $row['scale']
		);			
		$data[] = $myrow;
	}

}

include("pdf_layouts/".$layout.".php");
$layout($data,$offset);

?>
