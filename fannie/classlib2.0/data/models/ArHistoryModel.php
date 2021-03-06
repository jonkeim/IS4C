<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of Fannie.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

/**
  @class ArHistoryModel
*/
class ArHistoryModel extends BasicModel {

	protected $name = "ar_history";

	protected $columns = array(
	'card_no' => array('type'=>'INT','index'=>True),
	'Charges' => array('type'=>'MONEY'),
	'Payments' => array('type'=>'MONEY'),
	'tdate' => array('type'=>'DATETIME'),
	'trans_num' => array('type'=>'VARCHAR(50)')
	);

	protected $preferred_db = 'trans';

	/* START ACCESSOR FUNCTIONS */

	public function card_no(){
		if(func_num_args() == 0){
			if(isset($this->instance["card_no"]))
				return $this->instance["card_no"];
			elseif(isset($this->columns["card_no"]["default"]))
				return $this->columns["card_no"]["default"];
			else return null;
		}
		else{
			$this->instance["card_no"] = func_get_arg(0);
		}
	}

	public function Charges(){
		if(func_num_args() == 0){
			if(isset($this->instance["Charges"]))
				return $this->instance["Charges"];
			elseif(isset($this->columns["Charges"]["default"]))
				return $this->columns["Charges"]["default"];
			else return null;
		}
		else{
			$this->instance["Charges"] = func_get_arg(0);
		}
	}

	public function Payments(){
		if(func_num_args() == 0){
			if(isset($this->instance["Payments"]))
				return $this->instance["Payments"];
			elseif(isset($this->columns["Payments"]["default"]))
				return $this->columns["Payments"]["default"];
			else return null;
		}
		else{
			$this->instance["Payments"] = func_get_arg(0);
		}
	}

	public function tdate(){
		if(func_num_args() == 0){
			if(isset($this->instance["tdate"]))
				return $this->instance["tdate"];
			elseif(isset($this->columns["tdate"]["default"]))
				return $this->columns["tdate"]["default"];
			else return null;
		}
		else{
			$this->instance["tdate"] = func_get_arg(0);
		}
	}

	public function trans_num(){
		if(func_num_args() == 0){
			if(isset($this->instance["trans_num"]))
				return $this->instance["trans_num"];
			elseif(isset($this->columns["trans_num"]["default"]))
				return $this->columns["trans_num"]["default"];
			else return null;
		}
		else{
			$this->instance["trans_num"] = func_get_arg(0);
		}
	}
	/* END ACCESSOR FUNCTIONS */
}
?>
