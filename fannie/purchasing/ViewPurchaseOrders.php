<?php
/*******************************************************************************

    Copyright 2013 Whole Foods Co-op

    This file is part of CORE-POS.

    CORE-POS is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    CORE-POS is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

use COREPOS\Fannie\API\lib\Store;
use COREPOS\Fannie\API\data\pipes\OutgoingEmail;

include(dirname(__FILE__) . '/../config.php');
if (!class_exists('FannieAPI')) {
    include_once(__DIR__ . '/../classlib2.0/FannieAPI.php');
}

class ViewPurchaseOrders extends FannieRESTfulPage 
{
    protected $header = 'Purchase Orders';
    protected $title = 'Purchase Orders';

    public $description = '[View Purchase Orders] lists pending orders and completed invoices.';

    protected $must_authenticate = true;

    private $show_all = true;
    protected $debug_routing = false;

    public function preprocess()
    {
        $this->addRoute(
            'get<pending>',
            'get<placed>',
            'post<id><setPlaced>',
            'get<id><export>',
            'get<id><sendAs>',
            'get<id><receive>',
            'get<id><receiveAll>',
            'get<id><sku>',
            'get<id><recode>',
            'post<id><sku><recode>',
            'post<id><sku><qty><cost>',
            'post<id><sku><upc><brand><description><orderQty><orderCost><receiveQty><receiveCost>',
            'post<id><sku><qty><receiveAll>',
            'post<id><note>',
            'post<id><sku><isSO>',
            'post<id><sku><adjust>',
            'post<id><ignore>',
            'get<merge>',
            'get<id><receiveDone>'
        );
        if (FormLib::get('all') === '0')
            $this->show_all = false;
        return parent::preprocess();
    }

    protected function get_id_receiveDone_handler()
    {
        $prep = $this->connection->prepare("UPDATE
            PurchaseOrderItems
            SET receivedQty=0,
                receivedTotalCost=0,
                receivedDate=?
            WHERE orderID=?
                AND receivedQty IS NULL");
        $this->connection->execute($prep, array(
            date('Y-m-d H:i:s'),
            $this->id,
        ));

        $dbc = $this->connection;
        $storeP = $dbc->prepare("SELECT storeID FROM PurchaseOrder WHERE orderID=?");
        $store = $dbc->getValue($storeP, array($this->id));
        $upcP = $dbc->prepare("SELECT internalUPC FROM PurchaseOrderItems WHERE orderID=?");
        $upcR = $dbc->execute($upcP, array($this->id));
        $upcs = array();
        while ($upcW = $dbc->fetchRow($upcR)) {
            $upcs[] = $upcW['internalUPC'];
        }
        $uid = FannieAuth::getUID($this->current_user);
        $this->newToInventory($this->id, $uid, $upcs);
        $dbc->startTransaction();
        $cache = new InventoryCacheModel($dbc);
        foreach ($upcs as $upc) {
            $cache->recalculateOrdered($upc, $store);
        }
        $dbc->commitTransaction();

        return 'ViewPurchaseOrders.php?id=' . $this->id;
    }

    protected function post_id_ignore_handler()
    {
        $prep = $this->connection->prepare('UPDATE PurchaseOrder SET inventoryIgnore=? WHERE orderID=?');
        $res = $this->connection->execute($prep, array($this->ignore, $this->id));

        $selfP = $this->connection->prepare('SELECT i.internalUPC AS upc, o.storeID FROM PurchaseOrderItems AS i
            INNER JOIN PurchaseOrder AS o ON i.orderID=o.orderID
            WHERE i.orderID=?
                AND i.isSpecialOrder=0');
        $selfR = $this->connection->execute($selfP, array($this->id));
        $this->connection->startTransaction();
        $model = new InventoryCacheModel($this->connection);
        while ($row = $this->connection->fetchRow($selfR)) {
            $model->recalculateOrdered($row['upc'], $row['storeID']);
        }
        $this->connection->commitTransaction();

        return false;
    }

    /**
      Merge a set of purchase orders into one
      The highest ID order is retained. Items and notes from
      lower ID orders are added to the highest ID order then
      the lower ID orders are deleted.
    */
    protected function get_merge_handler()
    {
        $this->connection->selectDB($this->config->get('OP_DB'));
        $dbc = $this->connection;
        sort($this->merge);
        $mergeID = array_pop($this->merge);
        $moveP = $dbc->prepare('UPDATE PurchaseOrderItems SET orderID=? WHERE orderID=?');
        $noteP = $dbc->prepare('SELECT notes FROM PurchaseOrderNotes WHERE orderID=?');
        $delP = $dbc->prepare('DELETE FROM PurchaseOrder WHERE orderID=?');
        $delNoteP = $dbc->prepare('DELETE FROM PurchaseOrderNotes WHERE orderID=?');
        $mergeNotes = $dbc->getValue($noteP, array($mergeID));
        foreach ($this->merge as $orderID) {
            $moved = $dbc->execute($moveP, array($mergeID, $orderID));
            if ($moved) {
                $note = $dbc->getValue($noteP, array($orderID));
                $mergeNotes .= (strlen($mergeNotes) > 0 ? "\n" : "") . $note;
                $dbc->execute($delP, array($orderID));
                $dbc->execute($delNoteP, array($orderID));
            }
        }
        $upP = $dbc->prepare('UPDATE PurchaseOrderNotes SET notes=? WHERE orderID=?');
        $dbc->execute($upP, array($mergeNotes, $mergeID));

        return 'ViewPurchaseOrders.php?init=pending';
    }

    protected function post_id_sku_adjust_handler()
    {
        $this->connection->selectDB($this->config->get('OP_DB'));
        $halfP = $this->connection->prepare('
            SELECT halfCases FROM PurchaseOrder AS o INNER JOIN vendors AS v ON o.vendorID=v.vendorID WHERE o.orderID=?'
        );
        $halved = $this->connection->getValue($halfP, array($this->id));
        if ($halved) {
            $this->adjust /= 2;
        }
        $item = new PurchaseOrderItemsModel($this->connection);
        $item->orderID($this->id);
        $item->sku($this->sku);
        $item->load();
        $next = $item->quantity() + $this->adjust;
        if ($next < 0) {
            $next = 0;
        }
        $item->quantity($next);
        $item->save();
        echo json_encode(array('qty' => $next));

        return false;
    }

    /**
      Callback: save item's isSpecialOrder setting
    */
    protected function post_id_sku_isSO_handler()
    {
        $this->connection->selectDB($this->config->get('OP_DB'));
        $item = new PurchaseOrderItemsModel($this->connection);
        $item->orderID($this->id);
        $item->sku($this->sku);
        $item->isSpecialOrder($this->isSO);
        $item->save();

        return false;
    }

    /**
      Callback: save notes associated with order
    */
    protected function post_id_note_handler()
    {
        $this->connection->selectDB($this->config->get('OP_DB'));
        $note = new PurchaseOrderNotesModel($this->connection);
        $note->orderID($this->id);
        $note->notes(trim($this->note));
        if ($note->notes() === '') {
            $note->delete();
        } else {
            $note->save();
        }

        return false;
    }

    protected function get_id_export_handler()
    {
        if (!file_exists('exporters/'.$this->export.'.php'))
            return $this->unknown_request_handler();
        include_once('exporters/'.$this->export.'.php');    
        if (!class_exists($this->export))
            return $this->unknown_request_handler();

        $exportObj = new $this->export();
        $exportObj->send_headers();
        $exportObj->export_order($this->id);
        return false;
    }

    private function csvToHtml($csv)
    {
        $lines = explode("\r\n", $csv);
        $ret = "<table border=\"1\">\n";
        $para = '';
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) == 1) {
                $para .= $row[0] . '<br />';
            } elseif (count($row) > 1) {
                $ret .= "<tr>\n";
                $rowEmpty = true;
                $trow = '';
                foreach ($row as $entry) {
                    if (trim($entry) !== '') {
                        $rowEmpty = false;
                    }
                    $trow .= '<td>' . trim($entry) . '</td>';
                }
                if (!$rowEmpty) {
                    $ret .= $trow;
                }
                $ret .= "</tr>\n";
            }
        }
        $ret .= "</table>\n";
        if (strlen($para) > 0) {
            $ret .= '<p>' . $para . '</p>';
        }

        return $ret;
    }

    protected function get_id_sendAs_handler()
    {
        if (!file_exists('exporters/'.$this->sendAs.'.php')) {
            return $this->unknownRequestHandler();
        }
        include_once('exporters/'.$this->sendAs.'.php');    
        if (!class_exists($this->sendAs)) {
            return $this->unknownRequestHandler();
        }
        if (!class_exists('WfcPoExport')) {
            include('exporters/WfcPoExport.php');
        }

        $csvObj = new WfcPoExport();
        $exported = $csvObj->exportString($this->id);

        $html = $this->csvToHtml($exported);
        $nonHtml = str_replace("\r", "", $exported);

        $dbc = FannieDB::get($this->config->get('OP_DB'));
        $place = $dbc->prepare("UPDATE PurchaseOrder SET placed=1, placedDate=? WHERE orderID=?");

        $order = new PurchaseOrderModel($dbc);
        $order->orderID($this->id);
        $order->load();
        $vendor = new VendorsModel($dbc);
        $vendor->vendorID($order->vendorID());
        $vendor->load();
        $multipleAddrs = array();
        if (str_contains($vendor->email(), ",")) {
            $allValid = true;
            $emails = explode(",", $vendor->email());
            foreach ($emails as $email) {
                $email = str_replace(" ", "", $email);
                $multipleAddrs[] = $email;
                if (filter_var($email, FILTER_VALIDATE_EMAIL) == false) {
                    $allValid = false;
                }
            }
            if ($allValid == false) {
                return $this->unknownRequestHandler();
            }
        } else {
            if (!filter_var($vendor->email(), FILTER_VALIDATE_EMAIL)) {
                return $this->unknownRequestHandler();
            }
        }

        $userP = $dbc->prepare("SELECT email, real_name FROM Users WHERE name=?");
        $userInfo = $dbc->getRow($userP, array($this->current_user));
        $userEmail = $userInfo['email'];
        $userRealName = $userInfo['real_name'];

        $mail = OutgoingEmail::get();
        $mail->isSMTP();
        $mail->Host = '127.0.0.1';
        $mail->Port = 25;
        $mail->SMTPAuth = false;
        $mail->SMTPAutoTLS = false;
        $mail->From = $this->config->get('PO_EMAIL');
        $mail->FromName = $this->config->get('PO_EMAIL_NAME');
        $mail->isHTML = true;
        if (count($multipleAddrs) == 0) {
            $mail->addAddress($vendor->email());
        } else {
            foreach ($multipleAddrs as $email) {
                $mail->addAddress($email);
            }
        }
        if ($this->config->get('COOP_ID') == 'WFC_Duluth' && $order->storeID() == 2) {
            $mail->From = 'dbuyers@wholefoods.coop';
            $mail->FromName = 'Whole Foods Co-op Denfeld';
            $mail->addCC('dbuyers@wholefoods.coop');
        } elseif ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addCC($userEmail);
            $mail->addReplyTo($userEmail);
            $mail->From = $userEmail;
            if (!empty($userRealName)) {
                $mail->FromName = $userRealName;
            }
        }
        $mail->Subject = 'Purchase Order ' . date('Y-m-d');
        $mail->Body = 'The same order information is also attached. Reply to this email to reach the person who sent it.';
        $mail->AltBody = $mail->Body;
        $mail->Body = '<p>' . $mail->Body . '</p>' . $html;
        $mail->AltBody .= $nonHtml;
        $exportObj = new $this->sendAs();
        $attachment = $exportObj->exportString($this->id);
        $mail->addStringAttachment(
            $attachment,
            'Order ' . date('Y-m-d') . '.' . $exportObj->extension,
            'base64',
            $exportObj->mime_type
        );
        $sent = $mail->send();
        if ($sent) {
            $dbc->execute($place, array(date('Y-m-d H:i:s'), $this->id));
            $order->placed(1);
            $order->placedDate(date('Y-m-d H:i:s'));
            $order->save();
        } else {
            echo "Failed to send email! Do not assume the order was placed.";
            exit;
        }
    
        return 'ViewPurchaseOrders.php?id=' . $this->id;
    }

    protected function post_id_setPlaced_handler()
    {
        $this->connection->selectDB($this->config->get('OP_DB'));
        $model = new PurchaseOrderModel($this->connection);
        $model->orderID($this->id);
        $model->load();
        $model->placed($this->setPlaced);
        if ($this->setPlaced == 1) {
            $model->placedDate(date('Y-m-d H:m:s'));
        } else {
            $model->placedDate(0);
        }
        $model->save();

        $poi = new PurchaseOrderItemsModel($this->connection);
        $poi->orderID($this->id);
        $cache = new InventoryCacheModel($this->connection);
        if (!class_exists('SoPoBridge')) {
            include(__DIR__ . '/../ordering/SoPoBridge.php');
        }
        $bridge = new SoPoBridge($this->connection, $this->config);
        foreach ($poi->find() as $item) {
            $cache->recalculateOrdered($item->internalUPC(), $model->storeID());
            if ($this->setPlaced ==1 && $poi->isSpecialOrder()) {
                $soID = substr($poi->internalUPC(), 0, 9);
                $transID = substr($poi->internalUPC(), 9);
                $bridge->markAsPlaced($soID, $transID);
            }
        }
        echo ($this->setPlaced == 1) ? $model->placedDate() : 'n/a';

        return false;
    }

    protected function get_pending_handler()
    {
        echo $this->get_orders(0);
        return false;
    }

    protected function get_placed_handler()
    {
        echo $this->get_orders(1);
        return false;
    }

    protected function get_orders($placed, $store=0, $month=0, $year=0)
    {
        $dbc = $this->connection;
        $store = FormLib::get('store', $store);

        $month = FormLib::get('month', $month);
        $year = FormLib::get('year', $year);
        if ($month == 'Last 30 days') {
            $start = date('Y-m-d', strtotime('30 days ago'));
            $end = date('Y-m-d 23:59:59');
        } else {
            $start = date('Y-m-01 00:00:00', mktime(0, 0, 0, $month, 1, $year));
            $end = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));
        }
        $args = array($start, $end);
        
        $query = 'SELECT p.orderID, p.vendorID, MIN(creationDate) as creationDate,
                MIN(placedDate) as placedDate, COUNT(i.orderID) as records,
                SUM(i.unitCost*i.caseSize*i.quantity) as estimatedCost,
                SUM(i.receivedTotalCost) as receivedCost, v.vendorName,
                MAX(i.receivedDate) as receivedDate,
                MAX(p.vendorInvoiceID) AS vendorInvoiceID,
                MAX(s.description) AS storeName,
                MAX(p.placed) AS placed,
                SUM(CASE WHEN isSpecialOrder THEN i.quantity ELSE 0 END) AS soFlag
            FROM PurchaseOrder as p
                LEFT JOIN PurchaseOrderItems AS i ON p.orderID = i.orderID
                LEFT JOIN vendors AS v ON p.vendorID=v.vendorID
                LEFT JOIN Stores AS s ON p.storeID=s.storeID
            WHERE creationDate BETWEEN ? AND ? ';
        if (!$this->show_all) {
            $query .= 'AND userID=? ';
        }
        if ($store != 0) {
            $query .= ' AND p.storeID=? ';
            $args[] = $store;
        }
        $query .= 'GROUP BY p.orderID, p.vendorID, v.vendorName 
                   ORDER BY MIN(creationDate) DESC';
        if (!$this->show_all) $args[] = FannieAuth::getUID($this->current_user);

        $prep = $dbc->prepare($query);
        $result = $dbc->execute($prep, $args);

        $ret = '<ul class="nav nav-tabs" role="tablist">
                <li role="presentation" class="' . ($placed ? '' : 'active') . '">
                    <a href="#pending-pane" aria-controls="pending-pane" role="tab" data-toggle="tab">Pending</a>
                </li>
                <li role="presentation" class="' . ($placed ? 'active' : '') . '">
                    <a href="#placed-pane" aria-controls="placed-pane" role="tab" data-toggle="tab">Placed</a>
                </li>
                </ul>
                <div class="tab-content">';

        $tPending = '<div id="pending-pane" class="tab-pane table-responsive ' . ($placed ? '' : 'active') . '">
            <table class="table table-striped table-bordered tablesorter table-float">';
        $tPlaced = '<div id="placed-pane" class="tab-pane table-responsive ' . ($placed ? 'active' : '') . '">
            <table class="table table-striped table-bordered tablesorter table-float">';
        $headers = '<thead style="background: #fff;"><tr>
            <th class="thead">Created</th>
            <th class="thead hidden-xs">Invoice#</th>
            <th class="thead hidden-xs">Store</th>
            <th class="thead">Vendor</th>
            <th class="thead"># Items</th>
            <th class="thead hidden-xs">Est. Cost</th>
            <th class="thead hidden-xs">Placed</th>
            <th class="thead hidden-xs">Received</th>
            <th class="thead hidden-xs">Rec. Cost</th></tr></thead><tbody>';
        $tPending .= $headers;
        $tPlaced .= $headers;
        $mergable = array();
        while ($row = $dbc->fetchRow($result)) {
            if ($row['placed']) {
                $tPlaced .= $this->orderRowToTable($row, 1);
            } else {
                $tPending .= $this->orderRowToTable($row, $placed);
                if (!isset($mergable[$row['vendorID']])) {
                    $mergable[$row['vendorID']] = array('orders'=>array(), 'name'=>$row['vendorName']);
                }
                $mergable[$row['vendorID']]['orders'][] = $row['orderID'];
            }
        }
        $tPlaced .= '</tbody></table></div>';
        $mergable = array_filter($mergable, function($i) { return count($i['orders']) > 1; });
        $tPending .= '</tbody></table>';
        foreach ($mergable as $m) {
            $idStr = implode('&', array_map(function($i) { return 'merge[]=' . $i; }, $m['orders']));
            $tPending .= sprintf('<a href="ViewPurchaseOrders.php?%s">Merge %s Orders</a><br />', $idStr, $m['name']);
        }
        $tPending .= '</div>';

        $ret .= $tPending . $tPlaced . '</div>';

        return $ret;
    }

    private function orderRowToTable($row, $placed)
    {
        list($date, $time) = explode(' ', $row['creationDate']);
        return sprintf('<tr %s><td><a href="ViewPurchaseOrders.php?id=%d">%s <span class="hidden-xs">%s</span></a></td>
                <td class="hidden-xs">%s</td>
                <td class="hidden-xs">%s</td>
                <td><a href="VendorPoPage.php?id=%d">%s</a></td>
                <td>%d</td><td class="hidden-xs">%.2f</td>
                <td class="hidden-xs">%s</td><td class="hidden-xs">%s</td><td class="hidden-xs">%.2f</td></tr>',
                ($row['soFlag'] ? 'class="success" title="Contains special order(s)" ' : ''),
                $row['orderID'],
                $date, $time, $row['vendorInvoiceID'], $row['storeName'],
                $row['vendorID'], $row['vendorName'],
                $row['records'], $row['estimatedCost'],
                ($placed == 1 ? $row['placedDate'] : '&nbsp;'),
                (!empty($row['receivedDate']) ? $row['receivedDate'] : '&nbsp;'),
                (!empty($row['receivedCost']) ? $row['receivedCost'] : 0.00)
        );
    }

    protected function delete_id_handler()
    {
        global $FANNIE_OP_DB;
        $dbc = FannieDB::get($FANNIE_OP_DB);

        $order = new PurchaseOrderModel($dbc);
        $order->orderID($this->id);
        $order->load();
        $ids = array($this->id);
        if ($order->transferID()) {
            $ids[] = abs($order->transferID());
        }
        $this->deleteOrders($dbc, $ids);

        echo 'deleted';

        return false;
    }

    private function deleteOrders($dbc, $ids)
    {
        foreach ($ids as $id) {
            $order = new PurchaseOrderModel($dbc);
            $order->orderID($id);
            $order->delete();
            $items = new PurchaseOrderItemsModel($dbc);
            $items->orderID($id);
            foreach ($items->find() as $item) {
                $item->delete();
            }
        }
    }

    /**
     * Add items to inventory if they're not currently perpetual
     * @param $orderID [int] purchase order ID
     * @param $uid [int] user ID
     * @param $data [array] UPC => inital quantity
     */
    private function newToInventory($orderID, $uid, $data)
    {
        $order = new PurchaseOrderModel($this->connection);
        $order->orderID($orderID);
        $order->load();
        list($inStr, $args) = $this->connection->safeInClause(array_keys($data));
        $prodP = $this->connection->prepare("SELECT upc
            FROM products
            WHERE upc IN ({$inStr})
                AND store_id=?");
        $args[] = $order->storeID();
        $prodR = $this->connection->execute($prodP, $args);
        $this->connection->startTransaction();
        $invP = $this->connection->prepare("SELECT upc FROM InventoryCounts WHERE upc=? AND storeID=?");
        $insP = $this->connection->prepare("INSERT INTO InventoryCounts (upc, storeID, count, countDate, mostRecent, uid, par)
            VALUES (?, ?, ?, ?, 1, ?, ?)");
        while ($prodW = $this->connection->fetchRow($prodR)) {
            $found = $this->connection->getValue($invP, array($prodW['upc'], $order->storeID()));
            if ($found === false && isset($data[$prodW['upc']]) && $prodW['upc'] != '0000000000000') {
                $this->connection->execute($insP, array($prodW['upc'], $order->storeID(),
                    $data[$prodW['upc']], date('Y-m-d H:i:s'), $uid, $data[$prodW['upc']]));
            }
        }
        $this->connection->commitTransaction();
    }

    protected function post_id_sku_qty_receiveAll_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $model = new PurchaseOrderItemsModel($dbc);
        $model->orderID($this->id);
        $re_date = FormLib::get('re-date', false);
        $uid = FannieAuth::getUID($this->current_user);
        $upcs = array();
        $dbc->startTransaction();
        for ($i=0; $i<count($this->sku); $i++) {
            $model->sku($this->sku[$i]);
            $model->load();
            $model->receivedQty($this->qty[$i]);
            $model->receivedBy($uid);
            $model->receivedTotalCost($model->receivedQty()*$model->unitCost());
            if ($model->receivedDate() === null || $re_date) {
                $model->receivedDate(date('Y-m-d H:i:s'));
            }
            if (!$model->isSpecialOrder() && $this->qty[$i] > 0) {
                $upcs[BarcodeLib::padUPC($model->internalUPC())] = $this->qty[$i];
            }
            $model->save();
        }
        $dbc->commitTransaction();

        $this->newToInventory($this->id, $uid, $upcs);

        $prep = $dbc->prepare('
            SELECT o.storeID, i.internalUPC
            FROM PurchaseOrder AS o
                INNER JOIN PurchaseOrderItems AS i ON o.orderID=i.orderID
            WHERE o.orderID=?');
        $res = $dbc->execute($prep, array($this->id));
        $dbc->startTransaction();
        $cache = new InventoryCacheModel($dbc);
        while ($row = $dbc->fetchRow($res)) {
            $cache->recalculateOrdered($row['internalUPC'], $row['storeID']);
        }
        $dbc->commitTransaction();

        return 'ViewPurchaseOrders.php?id=' . $this->id;
    }

    protected function post_id_sku_recode_handler()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $model = new PurchaseOrderItemsModel($dbc);
        $model->orderID($this->id);

        for ($i=0; $i<count($this->sku); $i++) {
            if (!isset($this->recode[$i])) {
                continue;
            }
            $model->sku($this->sku[$i]);
            $model->salesCode($this->recode[$i]);
            $model->save();
        }

        return filter_input(INPUT_SERVER, 'PHP_SELF') . '?id=' . $this->id;
    }

    protected function get_id_recode_view()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));
        $model = new PurchaseOrderItemsModel($dbc);
        $model->orderID($this->id);

        $ret = '<form method="post" action="' . filter_input(INPUT_SERVER, 'PHP_SELF') . '">
            <input type="hidden" name="id" value="' . $this->id . '" />
            <table class="table table-striped">
            <tr>
                <td><input type="text" placeholder="Change All" class="form-control" 
                    onchange="if (this.value != \'\') { $(\'.recode-sku\').val(this.value); }" /></td>
                <th>SKU</th>
                <th>UPC</th>
                <th>Brand</th>
                <th>Description</th>
            </tr>';
        $accounting = $this->config->get('ACCOUNTING_MODULE');
        if (!class_exists($accounting)) {
            $accounting = '\COREPOS\Fannie\API\item\Accounting';
        }
        foreach ($model->find() as $item) {
            $ret .= sprintf('<tr>
                <td><input class="form-control recode-sku" type="text" 
                    name="recode[]" value="%s" required /></td>
                <td>%s<input type="hidden" name="sku[]" value="%s" /></td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                </tr>',
                $accounting::toPurchaseCode($item->salesCode()),
                $item->sku(), $item->sku(),
                $item->internalUPC(),
                $item->brand(),
                $item->description()
            );
        }
        $ret .= '</table>
            <p><button type="submit" class="btn btn-default">Save Codings</button>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <a href="ViewPurchaseOrders.php?id=' . $this->id . '" class="btn btn-default">Back to Order</a>
            </p>
        </form>';

        return $ret;
    }

    private $empty_vendor = array(
        'vendorName'=>'',
        'phone'=>'',
        'fax'=>'',
        'email'=>'',
        'address'=>'',
        'city'=>'',
        'state'=>'',
        'zip'=>'',
        'notes'=>'',
    );

    private function transferHeader($order, $store, $vendor)
    {
        if (!$order->transferID()) {
            return '';
        }

        $first = 'Receiving';
        $second = 'Sending';
        $otherID = $order->transferID();
        $link = 'Credit';
        $self = 'Invoice';
        if ($order->transferID() < 0) {
            $first = 'Sending';
            $second = 'Receiving';
            $otherID = -1 * $order->transferID();
            $link = 'Invoice';
            $self = 'Credit';
        }

        return <<<HTML
<div class="alert alert-info">
Transfer {$self} |
{$first}: {$store} |
{$second}: {$vendor} |
<a href="ViewPurchaseOrders.php?id={$otherID}">Matching {$link}</a>
</div>
HTML;
    }

    protected function get_id_view()
    {
        $dbc = $this->connection;
        $dbc->selectDB($this->config->get('OP_DB'));

        $order = new PurchaseOrderModel($dbc);
        $order->orderID($this->id);
        $order->load();
        $orderObj = $order->toStdClass();
        $orderObj->placedDate = $orderObj->placed ? $orderObj->placedDate : 'n/a';
        $placedCheck = $orderObj->placed ? 'checked' : '';
        $notInv = $orderObj->inventoryIgnore ? 'checked' : '';
        $init = $orderObj->placed ? 'init=placed' : 'init=pending';
        $pendingOnlyClass = 'pending-only' . ($orderObj->placed ? ' collapse' : '');
        $placedOnlyClass = 'placed-only' . ($orderObj->placed ? '' : ' collapse');
        $sentDate = new DateTime($order->creationDate());
        $today = new DateTime();
        // ban adjustment to placed orders after 90 days
        if ($today->diff($sentDate)->format('%a') >= 90) {
            $placedOnlyClass .= ' collapse';
        }
    
        $notes = $dbc->prepare('SELECT notes FROM PurchaseOrderNotes WHERE orderID=?');
        $notes = $dbc->getValue($notes, $this->id);
        $vname = $dbc->prepare('SELECT * FROM vendors WHERE vendorID=?');
        $vendor = $dbc->getRow($vname, array($orderObj->vendorID));
        if ($vendor) {
            $vendor['notes'] = nl2br($vendor['notes']);
        } else {
            $vendor = $this->empty_vendor;
        }
        $sname = $dbc->prepare('SELECT description FROM Stores WHERE storeID=?');
        $sname = $dbc->getValue($sname, array($orderObj->storeID));
        $xferHeader = $this->transferHeader($order, $sname, $vendor['vendorName']);

        $batchStart = date('Y-m-d', strtotime('+30 days'));
        $batchP = $dbc->prepare("
            SELECT b.batchName, b.startDate, b.endDate
            FROM batchList AS l
                INNER JOIN batches AS b ON l.batchID=b.batchID
                INNER JOIN StoreBatchMap AS m ON l.batchID=m.batchID
            WHERE l.upc=?
                AND m.storeID=?
                AND b.startDate <= ?
                AND b.endDate >= " . $dbc->curdate() . "
                AND b.discounttype > 0
        ");

        $invP = $dbc->prepare("SELECT onHand FROM InventoryCache WHERE upc=? AND storeID=?");

        $exportOpts = '';
        foreach (COREPOS\Fannie\API\item\InventoryLib::orderExporters() as $class => $name) {
            $selected = $class === $this->config->get('DEFAULT_PO_EXPORT') ? 'selected' : '';
            $exportOpts .= '<option ' . $selected . ' value="'.$class.'">'.$name.'</option>';
        }
        $exportEmail = '';
        if (!$orderObj->placed && !str_contains($vendor['email'], ",")  && filter_var($vendor['email'], FILTER_VALIDATE_EMAIL)) {
            $exportEmail = '<button type="submit" class="btn btn-default btn-sm" onclick="doSend(' . $this->id . ');
                return false;" title="Email order to ' . $vendor['email'] . '" >Send via Email</button>';
        } elseif (!$orderObj->placed && str_contains($vendor['email'], ",")) {
            $allValid = true;
            $emails = explode(",", $vendor['email']);
            foreach ($emails as $email) {
                $email = str_replace(" ", "", $email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) == false) {
                    $allValid = false;
                }
            }
            if ($allValid) {
                $exportEmail = '<button type="submit" class="btn btn-default btn-sm" onclick="doSend(' . $this->id . ');
                    return false;" title="Email order to ' . $vendor['email'] . '" >Send via Email</button>';
            }
        }
        $uname = FannieAuth::getName($order->userID());
        if (!$uname) {
            $uname = 'n/a';
        }
        $receivedP = $dbc->prepare("SELECT DISTINCT u.name FROM PurchaseOrderItems AS p INNER JOIN Users AS u ON p.receivedBy=u.uid WHERE p.orderID=?");
        $receivers = array();
        $receivedR = $dbc->execute($receivedP, array($this->id));
        while ($row = $dbc->fetchRow($receivedR)) {
            $receivers[] = $row['name'];
        }
        $uname .= count($receivers) > 0 ? '<br /><b>Received by</b>: ' . implode(',', $receivers) : '';

        $ret = <<<HTML
<p>
    <div class="form-inline">
        <b>Store</b>: {$sname}
        &nbsp;&nbsp;&nbsp;&nbsp;
        <b>Vendor</b>: <a href="../item/vendors/VendorIndexPage.php?vid={$orderObj->vendorID}">{$vendor['vendorName']}</a>
        &nbsp;&nbsp;&nbsp;&nbsp;
        <b>Created</b>: {$orderObj->creationDate}
        &nbsp;&nbsp;&nbsp;&nbsp;
        <b>Placed</b>: <span id="orderPlacedSpan">{$orderObj->placedDate}</span>
        <input type="checkbox" {$placedCheck} id="placedCheckbox"
                onclick="togglePlaced({$this->id});" />
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
        Export as: <select id="exporterSelect" class="form-control input-sm">
            {$exportOpts}
        </select> 
        <button type="submit" class="btn btn-default btn-sm" onclick="doExport({$this->id});return false;">Export</button>
        {$exportEmail}
        &nbsp;&nbsp;&nbsp;
        <a type="button" class="btn btn-default btn-sm" 
            href="ViewPurchaseOrders.php?{$init}">All Orders</a>
    </div>
</p>
{$xferHeader}
<div class="row">
    <div class="col-sm-6">
        <table class="table table-bordered small">
            <tr>
                <td><b>PO#</b>: {$orderObj->vendorOrderID}</td>
                <td><b>Invoice#</b>: {$orderObj->vendorInvoiceID}</td>
                <th colspan="2">Coding(s)</th>
            </tr>
            <tr> 
                <td rowspan="10" colspan="2">
                    <label>Notes</label>
                    <textarea class="form-control" 
                        onkeypress="autoSaveNotes({$this->id}, this);">{$notes}</textarea>
                </td>
            {{CODING}}
            <tr>
                <td colspan="2"><b>Created by</b>: {$uname}</td>
            </tr>
            <tr>
                <td colspan="2">
                    <label>Not Inventory
                        <input type="checkbox" {$notInv} onchange="toggleInventory({$this->id}, this.checked);" />
                    </label>
                </td>
            </tr>
        </table>
    </div>
    <div class="col-sm-6">
    <p>
        <a class="btn btn-default btn-sm {$pendingOnlyClass}"
            href="EditOnePurchaseOrder.php?id={$this->id}">Add Items</a>
        <span class="{$pendingOnlyClass}">&nbsp;&nbsp;&nbsp;&nbsp;</span>
        <button class="btn btn-default btn-sm {$pendingOnlyClass}" 
            onclick="deleteOrder({$this->id}); return false;">Delete Order</button>
        <a class="btn btn-default btn-sm {$placedOnlyClass}"
            href="ManualPurchaseOrderPage.php?id={$orderObj->vendorID}&adjust={$this->id}">Edit Order</a>
        <span class="{$placedOnlyClass}">&nbsp;&nbsp;&nbsp;&nbsp;</span>
        <a class="btn btn-default btn-sm {$placedOnlyClass}" id="receiveBtn"
            href="ViewPurchaseOrders.php?id={$this->id}&receive=1">Receive Order</a>
        <span class="{$placedOnlyClass}">&nbsp;&nbsp;&nbsp;&nbsp;</span>
        <a class="btn btn-default btn-sm {$placedOnlyClass}" id="receiveBtn"
            href="TransferPurchaseOrder.php?id={$this->id}">Transfer Order</a>
        <span class="{$placedOnlyClass}">&nbsp;&nbsp;&nbsp;&nbsp;</span>
        <a class="btn btn-default btn-sm {$placedOnlyClass}"
            href="ViewPurchaseOrders.php?id={$this->id}&recode=1">Alter Codings</a>
    </p>
<div class="panel panel-default"><div class="panel-body">
Ph: {$vendor['phone']}<br />
Fax: {$vendor['fax']}<br />
Email: {$vendor['email']}<br />
{$vendor['address']}, {$vendor['city']}, {$vendor['state']} {$vendor['zip']}<br />
{$vendor['notes']}
</div></div>
HTML;
        $ret .= '</div></div>';

        $model = new PurchaseOrderItemsModel($dbc);
        $model->orderID($this->id);
        $codings = array();
        $accounting = $this->config->get('ACCOUNTING_MODULE');
        if (!class_exists($accounting)) {
            $accounting = '\COREPOS\Fannie\API\item\Accounting';
        }

        $ret .= '<table class="table tablesorter table-bordered small table-float"><thead style="background:#fff;">';
        $ret .= '<tr>
            <th class="thead hidden-xs">Coding</th>
            <th class="thead">SKU</th>
            <th class="thead hidden-xs">UPC</th>
            <th class="thead hidden-xs">Brand</th>
            <th class="thead">Description</th>
            <th class="thead hidden-xs">Unit Size</th>
            <th class="thead">Units/Case</th>
            <th class="thead">Cases</th>
            <th class="thead hidden-xs">Est. Cost</th>
            ' . (!$order->placed() ? '<th class="thead hidden-xs">On Hand</th>' : '') . '
            <th class="thead hidden-xs">Received</th>
            <th class="thead">Rec. Qty</th>
            <th class="thead hidden-xs">Rec. Cost</th>
            <th class="thead hidden-xs">SO</th></tr></thead><tbody>';
        $count = 0;
        foreach ($model->find() as $obj) {
            $css = $this->qtyToCss($order->placed(), $obj->quantity(),$obj->receivedQty());
            $onHand = '';
            if (!$order->placed()) {
                $batchR = $dbc->execute($batchP, array($obj->internalUPC(), $orderObj->storeID, $batchStart));
                $title = '';
                while ($batchW = $dbc->fetchRow($batchR)) {
                    $title .= $batchW['batchName'] . ' (';
                    $title .= date('M j', strtotime($batchW['startDate'])) . ' - ';
                    $title .= date('M j', strtotime($batchW['endDate'])) . ') ';
                }
                if ($title) {
                    $css = 'class="info" title="' . $title . '"';
                }
                $onHand = '<td class="hidden-xs">' . $dbc->getValue($invP, array($obj->internalUPC(), $order->storeID())) . '</td>';
            }
            $link = '../item/ItemEditorPage.php?searchupc=' . $obj->internalUPC();
            if ($obj->isSpecialOrder()) {
                $link = '../ordering/OrderViewPage.php?orderID=' . ltrim(substr($obj->internalUPC(), 0, 9), '0');
                $css = 'class="success" title="Special order"';
            }
            if ($obj->salesCode() == '') {
                $code = $obj->guessCode();
                $obj->salesCode($code);
                $obj->save();
            }
            $coding = (int)$obj->salesCode();
            $coding = $accounting::toPurchaseCode($coding);
            if (!isset($codings[$coding])) {
                $codings[$coding] = 0.0;
            }
            $codings[$coding] += $obj->receivedTotalCost();
            $ret .= sprintf('<tr %s><td class="hidden-xs">%d</td><td>%s</td>
                    <td class="hidden-xs"><a href="%s">%s</a></td><td class="hidden-xs">%s</td><td>%s</td>
                    <td class="hidden-xs">%s</td><td>%s</td>
                    <td><span id="qty%d">%s</span> <span class="%s pull-right">
                        <a href="" onclick="itemInc(%d, \'%s\', %d); return false;"><span class="fas fa-chevron-up small" /></a>
                        <br />
                        <a href="" onclick="itemDec(%d, \'%s\', %d); return false;"><span class="fas fa-chevron-down small" /></a>
                        </span>
                    </td>
                    <td class="hidden-xs">%.2f</td>
                    %s
                    <td class="hidden-xs">%s</td><td>%s</td><td class="hidden-xs">%.2f</td>
                    <td class="hidden-xs">
                        <select class="form-control input-sm" onchange="isSO(%d, \'%s\', this.value);">
                        %s
                        </select>
                    </tr>',
                    $css,
                    $accounting::toPurchaseCode($obj->salesCode()),
                    $obj->sku(),
                    $link, $obj->internalUPC(),
                    $obj->brand(),
                    $obj->description(),
                    $obj->unitSize(), $obj->caseSize(),
                    $count, $obj->quantity(), $pendingOnlyClass, $this->id, $obj->sku(), $count, $this->id, $obj->sku(), $count,
                    ($obj->quantity() * $obj->caseSize() * $obj->unitCost()),
                    $onHand,
                    strtotime($obj->receivedDate()) ? date('Y-m-d', strtotime($obj->receivedDate())) : 'n/a',
                    $obj->receivedQty(),
                    $obj->receivedTotalCost(),
                    $this->id, $obj->sku(), $this->specialOrderSelect($obj->isSpecialOrder())
            );
            $count++;
        }
        $ret .= '</tbody></table>';

        $coding_rows = '';
        foreach ($codings as $coding => $ttl) {
            $coding_rows .= sprintf('<tr><td>%d</td><td>%.2f</td></tr>',
                $coding, $ttl);
        }
        $ret = str_replace('{{CODING}}', $coding_rows, $ret);

        if (file_exists(__DIR__ . '/noauto/invoices/' . $this->id . '.csv')) {
            $ret .= '<p><a href="noauto/invoices/' . $this->id . '.csv">Download Original</a></p>';
        } elseif (file_exists(__DIR__ . '/noauto/invoices/' . $this->id . '.xls')) {
            $ret .= '<p><a href="noauto/invoices/' . $this->id . '.xls">Download Original</a></p>';
        }

        $this->addScript('js/view.js?date=20230306');
        $this->addScript('../src/javascript/tablesorter/jquery.tablesorter.min.js');
        $this->addScript($this->config->get('URL') . 'src/javascript/jquery.floatThead.min.js');
        $this->addOnloadCommand("\$('.tablesorter').tablesorter({ sortList: [[0, 0], [3, 0]] });\n");
        $this->addOnloadCommand("\$('.table-float').floatThead();\n");

        return $ret;
    }

    private function qtyToCss($placed, $ordered, $received)
    {
        if (!$placed) {
            return '';
        } elseif ($received == 0 && $ordered != 0) {
            return 'class="danger"';
        } elseif ($received < $ordered) {
            return 'class="warning"';
        } else {
            return '';
        }
    }

    private function specialOrderSelect($isSO)
    {
        if ($isSO) {
            return '<option value="1" selected>Yes</option><option value="0">No</option>';
        } else {
            return '<option value="1">Yes</option><option value="0" selected>No</option>';
        }
    }

    protected function get_id_receive_handler()
    {
        $this->enable_linea = true;

        return true;
    }

    /**
      Receiving interface for processing enter recieved costs and quantities
      on an order
    */
    protected function get_id_receive_view()
    {
        $this->addScript('js/view.js');
        $ret = '
            <p>Receiving order #<a href="ViewPurchaseOrders.php?id=' . $this->id . '">' . $this->id . '</a></p>
            <p><div class="form-inline">
                <form onsubmit="receiveSKU(); return false;" id="receive-form">
                <label>SKU</label>
                <input type="text" name="sku" id="sku-in" class="form-control" />
                <input type="hidden" name="id" value="' . $this->id . '" />
                <button type="submit" class="btn btn-default">Continue</button>
                <a href="?id=' . $this->id . '&receiveAll=1" class="btn btn-default btn-reset">All</a>
                <a href="?id=' . $this->id . '" class="btn btn-default btn-reset">Order</a>
                <a href="?id=' . $this->id . '&receiveDone=1" class="btn btn-default btn-reset">Done Receiving</a>
                </form>
            </div></p>
            <div id="item-area">
            </div>';
        $this->addOnloadCommand("enableLinea('#sku-in', receiveSKU);");
        $this->addOnloadCommand("\$('#sku-in').focus();\n");

        return $ret;
    }

    protected function get_id_receiveAll_view()
    {
        $dbc = FannieDB::getReadOnly($this->config->get('OP_DB'));
        $poi = new PurchaseOrderItemsModel($dbc);
        $poi->orderID($this->id);
        $ret = '<form method="post">
            <input type="hidden" name="id" value="' . $this->id . '" />
            <input type="hidden" name="receiveAll" value="1" />
            <table class="table table-bordered table-striped">
            <tr>
                <th>SKU</th>
                <th>Brand</th>
                <th>Description</th>
                <th>Unit Size</th>
                <th>Qty Ordered</th>
                <th>Qty Receveived</th>
            </tr>';
        foreach ($poi->find() as $item) {
            $qty = $item->caseSize() * $item->quantity();
            $ret .= sprintf('<tr>
                <td><input type="hidden" name="sku[]" value="%s" />%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%s</td>
                <td>%.2f</td>
                <td><input type="text" class="form-control input-sm" name="qty[]" value="%.2f" /></td>
                </tr>',
                $item->sku(), $item->sku(),
                $item->brand(),
                $item->description(),
                $item->unitSize(),
                $qty,
                ($item->receivedQty() === null ? $qty : $item->receivedQty())
            );
        }
        $ret .= '</table>
            <p>
                <button type="submit" class="btn btn-default btn-core">Receive Order</button>
                <button type="reset" class="btn btn-default btn-reset">Reset</button>
                &nbsp;&nbsp;&nbsp;&nbsp;
                <label>Update Received Date <input type="checkbox" name="re-date" value="1" /></label>
            </p>
            </form>';

        return $ret;
    }

    /**
      Receiving AJAX callback. For items that were in
      the purchase order, just save the received quantity and cost
    */
    protected function post_id_sku_qty_cost_handler()
    {
        $dbc = $this->connection;
        $model = new PurchaseOrderItemsModel($dbc);
        $uid = FannieAuth::getUID($this->current_user);
        $upcs = array();
        for ($i=0; $i<count($this->sku); $i++) {
            $model->orderID($this->id);
            $model->sku($this->sku[$i]);
            $model->load();
            $model->receivedQty($this->qty[$i]);
            $model->receivedTotalCost($this->cost[$i]);
            $model->receivedBy($uid);
            if ($model->receivedDate() === null) {
                $model->receivedDate(date('Y-m-d H:i:s'));
            }
            if (!$model->isSpecialOrder() && $this->qty[$i] > 0) {
                $upcs[BarcodeLib::padUPC($model->internalUPC())] = $this->qty[$i];
            }
            $model->save();
        }

        $this->newToInventory($this->id, $uid, $upcs);
        $storeP = $dbc->prepare("SELECT storeID FROM PurchaseOrder WHERE orderID=?");
        $store = $dbc->getValue($storeP, array($this->id));
        $dbc->startTransaction();
        $cache = new InventoryCacheModel($dbc);
        foreach ($upcs as $upc) {
            $cache->recalculateOrdered($upc, $store);
        }
        $dbc->commitTransaction();

        return false;
    }

    /**
      Receiving AJAX callback. For items that were NOT in
      the purchase order, create a whole record for the
      item that showed up. 
    */
    protected function post_id_sku_upc_brand_description_orderQty_orderCost_receiveQty_receiveCost_handler()
    {
        $dbc = $this->connection;
        $model = new PurchaseOrderItemsModel($dbc);
        $model->orderID($this->id);
        // short circuit on choosing an SPO
        if (FormLib::get('spoSKU', '') !== '') {
            $model->sku(FormLib::get('spoSKU'));
            $model->load();
            $model->receivedDate(date('Y-m-d H:i:s'));
            $model->receivedTotalCost($model->unitCost() * $model->quantity() * $model->caseSize());
            $model->receivedQty($model->quantity() * $model->caseSize());
            $model->save();
            return false;
        }
        $model->sku($this->sku);
        $model->internalUPC(BarcodeLib::padUPC($this->upc));
        $model->brand($this->brand);
        $model->description($this->description);
        $model->quantity($this->orderQty);
        $model->unitCost($this->orderCost);
        $model->caseSize(1);
        $model->receivedQty($this->receiveQty);
        $model->receivedTotalCost($this->receiveCost);
        $model->receivedDate(date('Y-m-d H:i:s'));
        $model->receivedBy(FannieAuth::getUID($this->current_user));
        $model->save();

        return false;
    }

    /**
      Receiving AJAX callback.
      Lookup item in the order and display form fields
      to enter required info 
    */
    protected function get_id_sku_handler()
    {
        $dbc = $this->connection;
        $model = new PurchaseOrderItemsModel($dbc);
        $model->orderID($this->id);
        $model->sku($this->sku);
        // lookup by SKU but if nothing is found
        // try using the value as a UPC instead
        $found = false;
        if ($model->load()) {
            $found = true;
        } else {
            $barcodes = array(
                BarcodeLib::padUPC($this->sku),
                BarcodeLib::padUPC(substr($this->sku, 0, strlen($this->sku)-1)),
            );
            foreach ($barcodes as $barcode) {
                $model->reset();
                $model->orderID($this->id);
                $model->internalUPC($barcode);
                $matches = $model->find('quantity');
                if (count($matches) > 0) {
                    $found = true;
                }
                $spo = $this->findInSPO($this->id, $barcode);
                foreach ($spo as $s) {
                    $spoModel = new PurchaseOrderItemsModel($this->connection);
                    $spoModel->orderID($this->id);
                    $spoModel->sku($s);
                    $spoModel->load();
                    $matches[] = $spoModel;
                    $found = true;
                }
                if ($found) {
                    $model = $matches;
                    break;
                }
            }
        }
        
        // item not in order. need all fields to add it.
        echo '<form onsubmit="saveReceive(); return false;">';
        if (!$found) {
            $this->receiveUnOrderedItem($dbc);
        } else {
            // item in order. just need received qty and cost
            $this->receiveOrderedItem($dbc, $model);
        }
        echo '</form>';

        return false;
    }

    private function findInSPO($orderID, $upc)
    {
        $itemsP = $this->connection->prepare('SELECT internalUPC AS upc, sku FROM PurchaseOrderItems WHERE orderID=? AND isSpecialOrder=1');
        $itemR = $this->connection->execute($itemsP, array($orderID));
        $spoP = $this->connection->prepare('
            SELECT upc
            FROM ' . $this->config->get('TRANS_DB') . '.PendingSpecialOrder
            WHERE order_id=? AND trans_id=?');
        $ret = array();
        while ($itemW = $this->connection->fetchRow($itemR)) {
            $poUPC = $itemW['upc'];
            $spoID = ltrim(substr($poUPC, 0, 9), '0');
            $transID = ltrim(substr($poUPC, -4), '0');
            $spoUPC = $this->connection->getValue($spoP, array($spoID, $transID));
            if ($spoUPC == $upc) {
                $ret[] = $itemW['sku'];
            }
        }

        return $ret;
    }

    private function receiveUnOrderedItem($dbc)
    {
        echo '<div class="alert alert-danger">SKU not found in order</div>';
        echo '<table class="table table-bordered">';
        echo '<tr><th>SKU</th><th>UPC</th><th>Brand</th><th>Description</th>
            <th>Qty Ordered</th><th>Cost (est)</th><th>Qty Received</th><th>Cost Received</th></tr>';
        $vendP = $dbc->prepare('SELECT vendorID FROM PurchaseOrder WHERE orderID=?');
        $vendorID = $dbc->getValue($vendP, array($this->id));
        $itemP = $dbc->prepare('SELECT * FROM vendorItems WHERE vendorID=? AND sku LIKE ?');
        $item = $dbc->getRow($itemP, array($vendorID, '%' . $this->sku));
        if ($item === false) {
            $itemP = $dbc->prepare('SELECT * FROM vendorItems WHERE vendorID=? AND upc=?');
            $item = $dbc->getRow($itemP, array($vendorID, BarcodeLib::padUPC($this->sku)));
            if ($item === false) {
                $itemP = $dbc->prepare('SELECT *, 1 AS units FROM products WHERE default_vendor_id=? AND upc=?');
                $item = $dbc->getRow($itemP, array($vendorID, BarcodeLib::padUPC($this->sku)));
            }
        }
        if ($item === false) {
            $item = array(
                'sku' => $this->sku,
                'upc' => BarcodeLib::padUPC($this->sku),
                'brand' => '',
                'description' => '',
                'cost' => 0,
                'units' => 1,
            );
        }
        printf('<tr>
            <td>%s<input type="hidden" name="sku" value="%s" /></td>
            <td><input type="text" class="form-control" name="upc" value="%s" /></td>
            <td><input type="text" class="form-control" name="brand" value="%s" /></td>
            <td><input type="text" class="form-control" name="description" value="%s" /></td>
            <td><input type="hidden" class="form-control" name="orderQty" value="%s" />0</td>
            <td><input type="text" class="form-control" name="orderCost" value="%.2f" /></td>
            <td><input type="text" class="form-control receiveQty" name="receiveQty" value="%s" /></td>
            <td><input type="text" class="form-control" name="receiveCost" value="%.2f" /></td>
            <td><button type="submit" class="btn btn-default">Add New Item</button><input type="hidden" name="id" value="%d" /></td>
            </tr>',
            isset($item['sku']) ? $item['sku'] : $this->sku,
            isset($item['sku']) ? $item['sku'] : $this->sku,
            $item['upc'],
            $item['brand'],
            $item['description'],
            0,
            $item['cost'] * $item['units'],
            0,
            0,
            $this->id
        );
        echo '</table>';

        $opts = '';
        $prep = $dbc->prepare('SELECT sku, quantity, caseSize, brand, description 
            FROM PurchaseOrderItems WHERE isSpecialOrder=1 AND orderID=?');
        $res = $dbc->execute($prep, array($this->id));
        while ($row = $dbc->fetchRow($res)) {
            $opts .= sprintf('<option value="%s">%s %s (%sx%s)</option>',
                $row['sku'], $row['brand'], $row['description'],
                $row['quantity'], $row['caseSize']);
        }
        if ($opts !== '') {
            echo '<p>Special Orders<br /><select name="spoSKU" class="form-control input-sm">
                <option value="">Select...</option>'
                . $opts
                . '<optgroup label=""></optgroup><!-- keeps iOS from truncating option labels above -->
                </select><br />
                <button type="submit" class="btn btn-default">Receive Item(s)</button><p>';
        }
    }

    private function receiveOrderedItem($dbc, $model)
    {
        echo '<table class="table table-bordered small">';
        $uid = FannieAuth::getUID($this->current_user);
        if (!is_array($model)) {
            $model = array($model);
        }
        foreach ($model as $m) {
            echo '<tr><th class="">SKU</th><th class="hidden-xs">UPC</th>
                <th class="hidden-xs">Brand</th><th class="">Description</th></tr>';
            if ($m->receivedQty() === null) {
                $m->receivedQty($m->quantity() * $m->caseSize());
                $m->receivedBy($uid);
            }
            if ($m->receivedTotalCost() === null) {
                $m->receivedTotalCost($m->quantity()*$m->unitCost()*$m->caseSize());
                $m->receivedBy($uid);
            }
            printf('<tr %s>
                <td class="small">%s<input type="hidden" name="sku[]" value="%s" /></td>
                <td class="hidden-xs">%s</td>
                <td class="hidden-xs">%s</td>
                <td class="small">%s</td>
                </tr><tr>
                <th>Qty Ordered</th><th class="hidden-xs">Cost (est)</th>
                <th>Qty Received</th></tr>
                <tr><td>%s (%sx%s)</td>
                <td class="hidden-xs">%.2f</td>
                <td><input type="text" pattern="\\d*" class="form-control receiveQty" name="qty[]" value="%s" /></td>
                </tr><tr><th>Cost Received</th></tr>
                <td><input type="number" min="-999" max="999" step="0.01" pattern="\\d+(\\.\\d*)?" class="form-control" name="cost[]" value="%.2f" /></td>
                <td><button type="submit" class="btn btn-default">Save</button><input type="hidden" name="id[]" value="%d" /></td>
                </tr>',
                ($m->isSpecialOrder() ? 'class="success"' : ''),
                $m->sku(), $m->sku(),
                $m->internalUPC(),
                $m->brand(),
                $m->description(),
                $m->quantity() * $m->caseSize(),
                $m->quantity() , $m->caseSize(),
                $m->quantity() * $m->unitCost() * $m->caseSize(),
                $m->receivedQty(),
                $m->receivedTotalCost(),
                $this->id
            );
        }
        echo '</table>';
    }

    protected function get_view()
    {
        $init = FormLib::get('init', 'placed');

        $monthOpts = '<option>Last 30 days</option>';
        for($i=1; $i<= 12; $i++) {
            $label = date('F', mktime(0, 0, 0, $i)); 
            $monthOpts .= sprintf('<option value="%d">%s</option>',
                        $i, $label);
        }

        $stores = FormLib::storePicker();
        $storeSelect = str_replace('<select ', '<select id="storeID" onchange="fetchOrders();" ', $stores['html']);

        $yearOpts = '';
        for ($i = date('Y'); $i >= 2013; $i--) {
            $yearOpts .= '<option>' . $i . '</option>';
        }

        $allSelected = $this->show_all ? 'selected' : '';
        $mySelected = !$this->show_all ? 'selected' : '';
        $ordersTable = $this->get_orders($init == 'placed' ? 1 : 0, Store::getIdByIp(), 'Last 30 days');

        $this->addScript('../src/javascript/tablesorter/jquery.tablesorter.min.js');
        $this->addScript($this->config->get('URL') . 'src/javascript/jquery.floatThead.min.js');
        $this->addScript('js/view.js?date=20191121');
        $this->addOnloadCommand("\$('.tablesorter').tablesorter();\n");
        $this->addOnloadCommand("\$('.table-float').floatThead();\n");

        return <<<HTML
<div class="form-group form-inline">
    <input type="hidden" id="orderStatus" value="{$init}" />
    <label>Showing</label> 
    <select id="orderShow" onchange="fetchOrders();" class="form-control">
        <option {$mySelected} value="0">My Orders</option><option {$allSelected} value="1">All Orders</option>
    </select>
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    {$storeSelect}
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <label>During</label> 
    <select id="viewMonth" onchange="fetchOrders();" class="form-control">
        {$monthOpts}
    </select>
    &nbsp;
    <select id="viewYear" onchange="fetchOrders();" class="form-control">
        {$yearOpts}
    </select>
    &nbsp;
    <button class="btn btn-default" onclick="location='PurchasingIndexPage.php'; return false;">Home</button>
</div>
<hr />
<div id="ordersDiv">{$ordersTable}</div>
HTML;
    }

    public function css_content()
    {
        return '
            .tablesorter thead th {
                cursor: hand;
                cursor: pointer;
            }';
    }

    public function helpContent()
    {
        if (isset($this->receive)) {
            return '<p>Receive an order. First enter a SKU (or UPC) to see
            the quantities that were ordered. Then enter the actual quantities
            received as well as costs. If a received item was <b>not</b> on the
            original order, you will be prompted to provide additional information
            so the item can be added to the order.</p>';
        } elseif (isset($this->id)) {
            return '<p>Details of a Purchase Order. Coding(s) are driven by POS department
            <em>Sales Codes</em>. Export outputs the order data in various formats.
            Edit Order loads the order line-items into an editing interface where adjustments
            to all fields can be made. Receive Order is used to resolve a purchase order
            with actual quantities received.
            </p>';
        } else {
            return '<p>Click the date link to view a particular purchase order. Use
                the dropdowns to filter the list. The distinction between <em>All Orders</em>
                and <em>My Orders</em> only works if user authentication is enabled.</p>';
        }
    }

    public function unitTest($phpunit)
    {
        $phpunit->assertNotEquals(0, strlen($this->get_view()));
        $this->id = '4011';
        $phpunit->assertNotEquals(0, strlen($this->get_id_view()));
        $this->recode = 1;
        $phpunit->assertNotEquals(0, strlen($this->get_id_recode_view()));
        $this->receive = 1;
        $phpunit->assertNotEquals(0, strlen($this->get_id_receive_view()));
    }
}

FannieDispatch::conditionalExec();

