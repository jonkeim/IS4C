<?php
/*
View: ar_history_today

Columns:
	card_no int
	Charges dbms currency
	Payments dbms currency
	tdate datetime
	trans_num varchar

Depends on:
	dlog (view)

Use:
In-store charge account activity for
the current day. Combine with ar_history
for a "live" view of account status
*/
$dlist = ar_departments();

$CREATE['trans.ar_history_today'] = "
	CREATE VIEW ar_history_today AS
	SELECT card_no,
	SUM(CASE WHEN trans_subtype='MI' THEN -total ELSE 0 END) AS charges,
	SUM(CASE WHEN department IN $dlist THEN total ELSE 0 END) AS payments,
	MAX(tdate) AS tdate,trans_num
	FROM dlog
	WHERE trans_subtype='MI' OR department IN $dlist
	AND ".$con->datediff($con->now(),'tdate')."=0
	GROUP BY card_no,trans_num
";
?>