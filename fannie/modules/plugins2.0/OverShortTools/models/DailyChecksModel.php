<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of IT CORE.

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

class DailyChecksModel extends BasicModel {

	protected $name = 'dailyChecks';

	protected $columns = array(
	'date' => array('type'=>'VARCHAR(10)'),
	'emp_no' => array('type'=>'SMALLINT'),
	'checks' => array('type'=>'TEXT'),
	'id' => array('type'=>'INT','primary_key'=>True,'increment'=>True)
	);

	protected $unique = array('date','emp_no');

	/* START ACCESSOR FUNCTIONS */

	public function date(){
		if(func_num_args() == 0){
			if(isset($this->instance["date"]))
				return $this->instance["date"];
			elseif(isset($this->columns["date"]["default"]))
				return $this->columns["date"]["default"];
			else return null;
		}
		else{
			$this->instance["date"] = func_get_arg(0);
		}
	}

	public function emp_no(){
		if(func_num_args() == 0){
			if(isset($this->instance["emp_no"]))
				return $this->instance["emp_no"];
			elseif(isset($this->columns["emp_no"]["default"]))
				return $this->columns["emp_no"]["default"];
			else return null;
		}
		else{
			$this->instance["emp_no"] = func_get_arg(0);
		}
	}

	public function checks(){
		if(func_num_args() == 0){
			if(isset($this->instance["checks"]))
				return $this->instance["checks"];
			elseif(isset($this->columns["checks"]["default"]))
				return $this->columns["checks"]["default"];
			else return null;
		}
		else{
			$this->instance["checks"] = func_get_arg(0);
		}
	}

	public function id(){
		if(func_num_args() == 0){
			if(isset($this->instance["id"]))
				return $this->instance["id"];
			elseif(isset($this->columns["id"]["default"]))
				return $this->columns["id"]["default"];
			else return null;
		}
		else{
			$this->instance["id"] = func_get_arg(0);
		}
	}
	/* END ACCESSOR FUNCTIONS */
}
