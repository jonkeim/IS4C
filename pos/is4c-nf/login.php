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

if (!class_exists("AutoLoader")) include("lib/AutoLoader.php");

$CORE_LOCAL->set("parse_chain",'');
$CORE_LOCAL->set("preparse_chain",'');

AutoLoader::LoadMap();

CoreState::initiate_session();

if ($CORE_LOCAL->get("SessionFirstRun") == "")
	$CORE_LOCAL->set("SessionFirstRun",1);

$my_url = MiscLib::base_url();
header("Location: {$my_url}gui-modules/login2.php");
?>

