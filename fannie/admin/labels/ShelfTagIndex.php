<?php
/*******************************************************************************

    Copyright 2009,2013 Whole Foods Co-op

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
require(dirname(__FILE__) . '/../../config.php');
if (!class_exists('FannieAPI')) {
    include_once(__DIR__ . '/../../classlib2.0/FannieAPI.php');
}

class ShelfTagIndex extends FanniePage {

    protected $title = 'Fannie - Shelf Tags';
    protected $header = 'Shelf Tags';
    public $description = '[Shelf Tag Menu] lists shelf tag related pages.';
    public $themed = true;

    private $layouts = array();

    function preprocess(){
        if (php_sapi_name() !== 'cli') {
            /* this page requires a session to pass some extra
               state information through multiple requests */
            if (session_id() == '') {
                session_start();
            }
        }

        $sel = FormLib::get('layout');
        $queue = FormLib::get('queue');
        if (is_numeric($queue)) {
            $obj = new COREPOS\Fannie\API\item\signage\MovementTags(array(), 'shelftags', $queue);
            $obj->drawPDF();
        }

        if (!function_exists('scan_layouts')) {
            require('scan_layouts.php');
        }
        $this->layouts = scan_layouts();
        return True;
    }

    function javascript_content(){
        ob_start();
        ?>
$(function(){
    $('#layoutselector').on('change', function(){
        var layout = $('#layoutselector option:selected').text();
        window.location.href = "ShelfTagIndex.php?layout=" + layout;
    });
});
function getMovement(queue){
    var layout = $('#layoutselector option:selected').text();
    window.location.href = 'ShelfTagIndex.php?queue='+queue;
}


function goToPage(the_id){
    var offsetCheckbox = $('#offset');
    if (offsetCheckbox.prop('checked')) {
        var offset = document.getElementById('offset').value;
    } else {
        var offset = 0;
    }
    var str = "0";
    if (!isNaN(parseInt(offset)))
        str = parseInt(offset);

    var url = 'genLabels.php?id='+the_id;
    url += '&offset='+offset;

    var sel = document.getElementById('layoutselector');
    var pdf = sel.options[sel.selectedIndex].text;
    url += '&layout='+pdf;

    url += '&sort='+$('#tag-sort').val();

    /*window.top.location = url;*/
    /* 5May13 Eric Lee As popup instead of replacing the select window. */
    tagwindow=window.open (url, "Shelftags", "location=0,status=1,scrollbars=1,width=800,height=1100");
    tagwindow.moveTo(750,10);
}
function printMany(){
    var url = 'genLabels.php?';
    url += $('.print-many').serialize();
    url += '&offset='+$('#offset').val();
    url += '&layout='+$('#layoutselector').val();
    url += '&sort='+$('#tag-sort').val();
    tagwindow=window.open (url, "Shelftags", "location=0,status=1,scrollbars=1,width=800,height=1100");
    tagwindow.moveTo(750,10);
}
        <?php
        return ob_get_clean();
    }

    function body_content()
    {
        global $FANNIE_OP_DB;
        $dbc = FannieDB::getReadOnly($FANNIE_OP_DB);

        $sel = FormLib::get('layout', $this->config->get('DEFAULT_PDF'));

        ob_start();
        ?>
        <div class="col-sm-8">

        <ul class="nav nav-tabs" role="tablist">
            <li class="active"><a href="ShelfTagIndex.php">Regular shelf tags</a></li>
            <li><a href="BatchShelfTags.php">Batch shelf tags</a></li>
        </ul>
        <p>
        <div class="form-group form-inline">
            <label>Offset</label>:
            <input type="checkbox" class="price-field form-control" id=offset name="offset" value=1 />
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <label>Layout</label>:
        <select id=layoutselector class="form-control">
        <?php
        $tagEnabled = $this->config->get('ENABLED_SIGNAGE');

        $tagEnabled = $this->config->get('ENABLED_TAGS');
        sort($this->layouts);
        $this->layouts = array_reverse($this->layouts);
        foreach($this->layouts as $k => $l){
            if (!in_array($l, $tagEnabled) && count($tagEnabled) > 0) continue;
            if ($l == $sel) {
                echo "<option selected>".$l."</option>";
            } elseif ($sel == '' && $l == 'WFC Hybrid') {
                echo "<option selected>".$l."</option>";
            } else {
                echo "<option>".$l."</option>";
            }
        }
        ?>
        </select>
        &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
            <label>Sort</label>:
            <select id="tag-sort" class="form-control">
                <option>Department</option>
                <option>Alphabetically</option>
                <option>Order Entered</option>
            </select>
        </div>
        </p>
        <table class="table table-striped">
        <?php

        $query = $dbc->prepare("
            SELECT s.shelfTagQueueID,
                s.description,
                count(distinct t.upc) AS ct
            FROM ShelfTagQueues AS s
                LEFT JOIN shelftags AS t ON s.shelfTagQueueID = t.id
            GROUP BY shelfTagQueueID,
                s.description
            ORDER BY shelfTagQueueID");
        $result = $dbc->execute($query);
        if ($dbc->numRows($result) == 0) {
            $queues = new ShelfTagQueuesModel($dbc);
            $queues->initQueues();
            $result = $dbc->execute($query);
        }
        $rows = array();
        while($row = $dbc->fetch_row($result))
            $rows[] = $row;
        $zeroID = $dbc->query('SELECT upc FROM shelftags WHERE id=0');
        array_unshift($rows, array(0,'Default',$dbc->numRows($zeroID)));

        foreach ($rows as $row) {
            $this->printRow($row);
        }
        ?>
        </table>
        <p>
            <a href="" onclick="printMany(); return false;" class="btn btn-default">Print Selected</a>
        </p>
        </div>

        <div class="col-sm-3">
            <div><a href="CreateTagsByDept.php">Create Tags By Department</a></div>
            <div><a href="CreateTagsByManu.php">Create Tags By Brand</a></div>
            <div><a href="QueueTagsByList.php">Queue Tags by A List</a></div>
            <div><a href="QueueTagsByLC.php">Queue Tags by Like Code</a></div>
            <div><a href="MovementTagTracker.php">Movement Tag Tracker</a>
                | <a href="MovementTagTracker.php?id=config">Settings</a>
                | <a href="MovementTagTracker.php?data=view">Data</a></div>
            <div><a href="../../item/handheld/ItemStatusPage.php">Scan a Single Item</a></div>
            <div><a href="../../item/vendors/ShelfTagsInOrder.php">Print Tags in Order</a></div>
            <div><a href="../../item/vendors/PrintEssentialOilStrips.php">Print Essential Oil Strips</a></div>
        </div>
        <?php

        return ob_get_clean();
    }


    private function drawPdf()
    {
        if (FormLib::get('pdf') == 'Print') {
            foreach (FormLib::get('exclude', array()) as $e) {
                $this->signage_obj->addExclude($e);
            }
            $this->signage_obj->setInUseFilter(FormLib::get('store', 0));
            $this->signage_obj->drawPDF();
            return false;
        } else {
            return true;
        }
    }

    private function printRow($row)
    {
        $layoutselected = FormLib::get('layout');
        $lastTagQueue = false;
        if (isset($this->session->LastTagQueue) && is_numeric($this->session->LastTagQueue)) {
            $lastTagQueue = $this->session->LastTagQueue;
        }
        $trc = ($lastTagQueue == $row[0]) ? "alert-warning" : "";
        if ($layoutselected == "MovementTags") {
            /*
                what needs to happen
                . include SignFromSearch.php
                . instantiate an object of SignFromSearch()
                . set $this->u with upc(s) in "Print"-ed queue.
                . call $obj->post_u_hander();
                . that's it???
            */
            if (!class_exists("SignFromSearch")) {
                require("SignFromSearch.php");
            }
            $sfs = new SignFromSearch();

            printf("<tr class=\"%s\">
            <td>%s barcodes/shelftags</td>
            <td style='text-align:right;'>%d</td>
            <td><a href=\"\" onclick=\"getMovement('%d'); return false;\">Print</a></td>
            <td><a href=\"DeleteShelfTags.php?id=%d\">Clear</a></td>
            <td><a href=\"EditShelfTags.php?id=%d\">" . \COREPOS\Fannie\API\lib\FannieUI::editIcon() . "</td>
            <td><a href=\"SignFromSearch.php?queueID=%d\">Signs</a></td>
            <td><input type=\"checkbox\" name=\"id[]\" value=\"%d\" class=\"print-many\" /></td>
            </tr>",
            $trc,$row[1],$row[2],$row[0],$row[0],$row[0],$row[0], $row[0]);
        } else {
            printf("<tr class=\"%s\">
            <td>%s barcodes/shelftags</td>
            <td style='text-align:right;'>%d</td>
            <td><a href=\"\" onclick=\"goToPage('%d');return false;\">Print</a></td>
            <td><a href=\"DeleteShelfTags.php?id=%d\">Clear</a></td>
            <td><a href=\"EditShelfTags.php?id=%d\">" . \COREPOS\Fannie\API\lib\FannieUI::editIcon() . "</td>
            <td><a href=\"SignFromSearch.php?queueID=%d\">Signs</a></td>
            <td><input type=\"checkbox\" name=\"id[]\" value=\"%d\" class=\"print-many\" /></td>
            </tr>",
            $trc, $row[1],$row[2],$row[0],$row[0],$row[0],$row[0], $row[0]);
        }
    }

    public function helpContent()
    {
        return '<p>This page lists shelf tags that have been queued up via
            the item editor. Shelf tags can also be associated with a batch
            or generated based on POS department or brand name.</p>
            <p>The dropdown box lists all available shelf tag layouts. The
            offset value will leave a number of tags at the beginning of
            the sheet blank. This is intended for re-using partial sheets.</p>
            <p>The numeric value is the number of shelf tags currently queued
            up for that super department. <em>Print</em> will generate the
            actual shelf tag PDF. <em>Clear</em> will clear the queued up
            tags for that super department. The pencil icon is for editing
            the currently queued tags.</p>';
    }

    public function unitTest($phpunit)
    {
        $phpunit->assertNotEquals(0, strlen($this->body_content()));
        $phpunit->assertNotEquals(0, strlen($this->javascript_content()));
    }
}

FannieDispatch::conditionalExec();

