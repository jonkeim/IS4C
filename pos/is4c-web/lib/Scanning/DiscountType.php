<?php
/*******************************************************************************

    Copyright 2010 Whole Foods Co-op

    This file is part of IS4C.

    IS4C is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IS4C is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IS4C; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

class DiscountType {

	var $savedRow;
	var $savedInfo;

	function priceInfo($row,$quantity=1){
		return array(
			"regPrice"=>0,
			"unitPrice"=>0,
			"discount"=>0,
			"memDiscount"=>0
		);
	}

	function addDiscountLine(){

	}

	function isSale(){
		return false;
	}

	function isMemberOnly(){
		return false;
	}

	function isStaffOnly(){
		return false;
	}

}

?>
