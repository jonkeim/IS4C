<?php
/*******************************************************************************

    Copyright 2010 Whole Foods Co-op

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

/**
  @class Authenticate
  Functions for user authentication
*/
class Authenticate extends LibraryClass {
 

/**
  Authenticate an employee by password
  @param $password password from employee table
  @param $activity activity identifier to log
  @return True or False

  If no one is currently logged in, any valid
  password will be accepted. If someone is logged
  in, then only passwords for that user <i>or</i>
  a user with frontendsecurity >= 30 in the
  employee table will be accepted.
*/
static public function check_password($password,$activity=1){
	global $CORE_LOCAL;

	$CORE_LOCAL->set("away",1);
	MiscLib::rePoll();
	$CORE_LOCAL->set("training",0);

	$password = strtoupper($password);
	$password = str_replace("'", "", $password);
	$password = str_replace(",", "", $password);
	$paswword = str_replace("+", "", $password);

	if ($password == "TRAINING") $password = 9999; // if password is training, change to '9999'

	if (!is_numeric($password)) return False; // if password is non-numeric, not a valid password
	elseif ($password < 1) return False; // if password is less than 1, not a valid password

	$query_g = "select LoggedIn,CashierNo from globalvalues";
	$db_g = Database::pDataConnect();
	$result_g = $db_g->query($query_g);
	$row_g = $db_g->fetch_array($result_g);
	$password = $db_g->escape($password);

	if ($row_g["LoggedIn"] == 0) {
		$query_q = "select emp_no, FirstName, LastName, "
			.$db_g->yeardiff($db_g->now(),'birthdate')." as age "
			."from employees where EmpActive = 1 "
			."and CashierPassword = '".$password."'";
		$result_q = $db_g->query($query_q);
		$num_rows_q = $db_g->num_rows($result_q);

		if ($num_rows_q > 0) {
			$row_q = $db_g->fetch_array($result_q);

			Database::loadglobalvalues();

			$transno = Database::gettransno($row_q["emp_no"]);
			$globals = array(
				"CashierNo" => $row_q["emp_no"],
				"Cashier" => $row_q["FirstName"]." ".substr($row_q["LastName"], 0, 1).".",
				"TransNo" => $transno,
				"LoggedIn" => 1
			);
			Database::setglobalvalues($globals);

			CoreState::cashier_login($transno, $row_q['age']);

			if ($transno == 1) TransRecord::addactivity($activity);
			
		} elseif ($password == 9999) {
			Database::loadglobalvalues();

			$transno = Database::gettransno(9999);
			$globals = array(
				"CashierNo" => 9999,
				"Cashier" => "Training Mode",
				"TransNo" => $transno,
				"LoggedIn" => 1
			);
			Database::setglobalvalues($globals);

			CoreState::cashier_login($transno, 0);
		}
		else return False;
	}
	else {
		// longer query but simpler. since someone is logged in already,
		// only accept password from that person OR someone with a high
		// frontendsecurity setting
		$query_a = "select emp_no, FirstName, LastName, "
			.$db_g->yeardiff($db_g->now(),'birthdate')." as age "
			."from employees "
			."where EmpActive = 1 and "
			."(frontendsecurity >= 30 or emp_no = ".$row_g["CashierNo"].") "
			."and (CashierPassword = '".$password."' or AdminPassword = '".$password."')";

		$result_a = $db_g->query($query_a);	

		$num_rows_a = $db_g->num_rows($result_a);

		if ($num_rows_a > 0) {

			Database::loadglobalvalues();
			$row = $db_g->fetch_row($result_a);
			CoreState::cashier_login(False, $row['age']);
		}
		elseif ($row_g["CashierNo"] == "9999" && $password == "9999"){
			Database::loadglobalvalues();
			CoreState::cashier_login(False, 0);
		}
		else return False;
	}

	if ($CORE_LOCAL->get("LastID") != 0 && $CORE_LOCAL->get("memberID") != "0" && $CORE_LOCAL->get("memberID") != "") {
		$CORE_LOCAL->set("unlock",1);
		/* not sure why this is here; andy 13Feb13 */
		/* don't want to clear member info via this call */
		//PrehLib::memberID($CORE_LOCAL->get("memberID"));
	}
	$CORE_LOCAL->set("inputMasked",0);

	return True;
}

/**
  Authentication function for Wedge NoSale page
  @param $password the password
  @return True or False
  @deprecated
*/
static public function ns_check_password($password){
	global $CORE_LOCAL;
	$CORE_LOCAL->set("away",1);

	$password = strtoupper(trim($password));
	if ($password == "TRAINING") 
		$password = 9999;

	if (!is_numeric($password)) 
		return False;
	elseif ($password > "9999" || $password < "1") 
		return False;
	elseif (empty($password))
		return False;

	$db = Database::pDataConnect();
	$query2 = "select emp_no, FirstName, LastName from employees where empactive = 1 and "
		."frontendsecurity >= 11 and (cashierpassword = ".$password." or adminpassword = "
		.$password.")";
	$result2 = $db->query($query2);
	$num_row2 = $db->num_rows($result2);

	if ($num_row2 > 0) {
		ReceiptLib::drawerKick();
		return True;
	}
	return False;
}

} // end class Authenticate

?>
