<?php
/*******************************************************************************

    Copyright 2014 Foods Co-op

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

use COREPOS\Fannie\API\lib\FannieUI;

include(dirname(__FILE__) . '/../../config.php');
if (!class_exists('FannieAPI')) {
    include(__DIR__ . '/../../classlib2.0/FannieAPI.php');
}
if (!class_exists('WFC_Hybrid_PDF')) {
    include(__DIR__ . '/../../admin/labels/pdf_layouts/WFC_Hybrid.php');
}
//if (!class_exists('WFC_EssOil')) {
//    include(__DIR__ . '/../../admin/labels/pdf_layouts/WFC_EssOil.php');
//}
if (!class_exists('WFC_EssOil_PDF')) {
    include(__DIR__ . '/../../admin/labels/pdf_layouts/WFC_EssOil.php');
}

/*
 *  @class PrintEssentialOilStrips 
 *
 *  Print a list of tags in list order.
 *
 */
class PrintEssentialOilStrips extends FannieRESTfulPage 
{
    protected $title = "Fannie : Essential Oil Strips";
    protected $header = "Essential Oil Strips";

    public $description = '[Print Ordered Tags] Print a list of shelf tags 
        in the order the tags were entered.';

    public function preprocess()
    {
        $this->__routes[] = 'get<upcs>';
        $this->__routes[] = 'get<upcs><print>';

        return parent::preprocess();
    }

    protected function get_upcs_print_handler()
    {
        $dbc  = $this->connection;
        $upcs = FormLib::get('upcs');
        $upcs = explode("\n", $upcs);
        $offset = (FormLib::get('offset') == 'on') ? 1 : 0;
        $itemrows = FormLib::get("itemrows", 13);
        $spacing = FormLib::get("spacing", 1);
        $upcStr = '';
        foreach($upcs as $k => $upc) {
            $upcs[$k] = BarcodeLib::padUPC($upc); 
            $upcStr .= "\n$upc";
        }
        list($data, $td) = $this->getProdData($dbc, $upcs);
        $data = array_reverse($data);

        //WFC_Hybrid($data, $offset);
        WFC_EssOil_PDF::WFC_EssOil($data, $offset, $itemrows, $spacing);

        return false;
    }

    protected function form($upcs='')
    {
        $offset = (FormLib::get('offset') == 'on') ? 'checked': '';
        $itemrows = FormLib::get("itemrows", 13);
        $spacing = FormLib::get("spacing", 1);

        return <<<HTML
<form action="PrintEssentialOilStrips.php" method="get" name="myform">
<div class="row">
    <div class="col-lg-4">
        <div class="form-group">
            <div><label for="upcs">UPC List</label></div>
            <textarea id="upcs" name="upcs" class="form-control" rows=6>$upcs</textarea>
        </div>
        <div class="row">
            <div class="col-lg-6">
                <div class="form-group">
                    <label for="offset">Offset</label>:&nbsp;&nbsp;
                    <input type="checkbox" id="offset" name="offset" $offset />
                </div>
                <div class="form-group">
                    <label for="itemrows">Number of Items in each Row</label>
                    <input class="form-control" type="text" id="itemrows" name="itemrows" value="$itemrows" />
                </div>
                <div class="form-group">
                    <label for="spacing">Spacing Type (1=AuraCacia | 2=All Others (more space)</label>
                    <input class="form-control" type="number" id="spacing" name="spacing" value="$spacing" min=1 max=2 />
                </div>
            </div>
            <div class="col-lg-4">
                <div class="form-group">
                    <button class="btn btn-default" type="submit">Submit</button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">

            <div><a href="../../admin/labels/CreateTagsByDept.php">Create Tags By Department</a></div>
            <div><a href="../../admin/labels/CreateTagsByManu.php">Create Tags By Brand</a></div>
            <div><a href="../../admin/labels/QueueTagsByList.php">Queue Tags by A List</a></div>
            <div><a href="../../admin/labels/QueueTagsByLC.php">Queue Tags by Like Code</a></div>
            <div><a href="../../admin/labels/MovementTagTracker.php">Movement Tag Tracker</a>
                | <a href="../../admin/labels/MovementTagTracker.php?id=config">Settings</a>
                | <a href="../../admin/labels/MovementTagTracker.php?data=view">Data</a></div>
            <div><a href="../../item/handheld/ItemStatusPage.php">Scan a Single Item</a></div>
            <div><a href="../../item/vendors/ShelfTagsInOrder.php">Print Tags in Order</a></div>
    </div>
    <div class="col-lg-4">
    </div>
</div>
</form>
HTML;
    }

    protected function get_view()
    {
        return <<<HTML
{$this->form()}
HTML;
    }

    protected function getProdData($dbc, $upcs)
    {
        $td = '';
        $data = array();
        //$i = 0;
        $i = count($upcs);
        foreach ($upcs as $k => $upc) {
            $args = array($upc);
            $prep = $dbc->prepare("SELECT *, p.brand AS pbrand, p.description AS pdesc, vendors.vendorName AS vendor,
                CONCAT(ROUND(normal_price / substring_index(v.size, ' ', 1), 3), '/', substring_index(v.size, ' ', -1)) AS pricePerUnit
                FROM products AS p LEFT JOIN vendorItems AS v ON    
                p.default_vendor_id=v.vendorID AND p.upc=v.upc LEFT JOIN vendors ON v.vendorID=vendors.vendorID WHERE p.upc = ? ");
            $res = $dbc->execute($prep, $args);
            while ($row = $dbc->fetchRow($res)) {
                $price = $row['normal_price'];
                $desc = $row['pdesc'];
                $brand = $row['pbrand'];
                $units = $row['units'];
                $size = $row['size'];
                $sku = $row['sku'];
                $upc = $row['upc'];
                $scale = $row['scale'];
                $vendor = (isset($row['vendor'])) ? $row['vendor'] : '';
                $ppu = isset($row['pricePerUnit']) ? $row['pricePerUnit'] : '';
                $data[$i]['normal_price'] = $price;
                $data[$i]['description'] = $desc;
                $data[$i]['brand'] = $brand;
                $data[$i]['units'] = $units;
                $data[$i]['size'] = $size;
                $data[$i]['sku'] = $sku;
                $data[$i]['upc'] = $upc;
                $data[$i]['scale'] = $scale;
                $data[$i]['vendor'] = $vendor;
                $data[$i]['pricePerUnit'] = $ppu;
                $td .= "<tr><td>$upc</td><td>$brand</td><td>$desc</td><td>$units</td>
                    <td>$size</td><td>$sku</td><td>$scale</td></tr>";
                //$i++;
                $i--;
                break; // only need one result per UPC
            }
        }

        return array($data, $td);
    }

    protected function get_upcs_view()
    {
        $URI = $_SERVER['REQUEST_URI'] . "&print=1";
        $upcs = FormLib::get('upcs');
        $itemrows = FormLib::get("itemrows", 13);
        $upcs = explode("\n", $upcs);
        $upcStr = '';
        foreach($upcs as $k => $upc) {
            $upcs[$k] = BarcodeLib::padUPC($upc); 
            $upcStr .= "\n$upc";
        }
        $dbc = $this->connection;

        $data = array();
        list($data, $td) = $this->getProdData($dbc, $upcs);

        return <<<HTML
{$this->form($upcStr)} 
<div class="row">
    <div class="col-lg-2">
        <div class="form-group">
            <button class="btn btn-primary form-control" onclick="window.location.href = '$URI'">Print</button>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-lg-1">
        <div class="form-group">
            <button class="btn btn-default form-control" onclick="window.location.href= 'http://key/git/fannie/item/vendors/PrintEssentialOilStrips.php'">Clear</button>
        </div>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-bordered"><thead></thead><tbody>$td</tbody></table>
</div>
HTML;
    }

    public function javascript_content()
    {
        return <<<JAVASCRIPT
$('#offset').change(function(){
    document.forms['myform'].submit();
});
$('#spacing').on('change', function() {
    document.forms['myform'].submit();
});
JAVASCRIPT;
    }

    public function helpContent()
    {
        return '<p>Print a list of shelf tags in order.</p>';
    }

    public function unitTest($phpunit)
    {
        $phpunit->assertEquals(false, $this->get_upcs_print_handler());
    }
}

FannieDispatch::conditionalExec();

