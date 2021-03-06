<?php
/*******************************************************************************

    Copyright 2012 Whole Foods Co-op

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
  @class CreditCardTender
  Tender module for credit cards
*/
class CreditCardTender extends TenderModule {

	/**
	  Check for errors
	  @return True or an error message string
	*/
	function ErrorCheck(){
		global $CORE_LOCAL;
	
		if (($this->amount > ($CORE_LOCAL->get("amtdue") + 0.005)) && $CORE_LOCAL->get("amtdue") >= 0){ 
			return DisplayLib::xboxMsg(_("tender cannot exceed purchase amount"));
		}

		return True;
	}
	
	/**
	  Set up state and redirect if needed
	  @return True or a URL to redirect
	*/
	function PreReqCheck(){
		global $CORE_LOCAL;
		if ($this->tender_code == 'CC' && $CORE_LOCAL->get('store') == 'wfc')
			$CORE_LOCAL->set('kickOverride',True);
		return True;
	}
}

?>
