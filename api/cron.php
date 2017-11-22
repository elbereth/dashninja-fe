<?php

/*
    This file is part of Dash Ninja.
    https://github.com/elbereth/dashninja-fe

    Dash Ninja is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Dash Ninja is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Dash Ninja.  If not, see <http://www.gnu.org/licenses/>.

 */

define("DASHNINJA_CRONVERSION","0");

// Load configuration and connect to DB
require_once('libs/db.inc.php');

// Get common queries functions
require_once('libs/common-queries.inc.php');

// Display log line (with date)
function xecho($line) {
    echo date('Y-m-d H:i:s').' - '.$line;
}

function die2($retcode,$semaphorefile) {
    unlink($semaphorefile);
    die($retcode);
}

function generate_masternodeslistfull_json_files($mysqli, $testnet = 0) {

    xecho("Generating full masternodes list:\n");
    if (file_exists(DMN_CRON_MNFL_SEMAPHORE) && (posix_getpgid(intval(file_get_contents(DMN_CRON_MNFL_SEMAPHORE))) !== false) ) {
        xecho("Already running (PID ".sprintf('%d',file_get_contents(DMN_CRON_MNFL_SEMAPHORE)).")\n");
        die(10);
    }
    file_put_contents(DMN_CRON_MNFL_SEMAPHORE,sprintf('%s',getmypid()));

    xecho("Retrieving current protocol version: ");

    $cachefnam = CACHEFOLDER.sprintf("dashninja_maxprotocol_%d",$testnet);
    $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
    if ($cachevalid) {
        $protocol = unserialize(file_get_contents($cachefnam));
    }
    else {
        $sqlmaxprotocol = sprintf("SELECT MAX(NodeProtocol) Protocol FROM cmd_nodes cn, cmd_nodes_status cns WHERE cn.NodeId = cns.NodeId AND NodeTestnet = %d GROUP BY NodeTestnet",$testnet);
        // Run the query
        if ($result = $mysqli->query($sqlmaxprotocol)) {
            $row = $result->fetch_assoc();
            if ($row !== false) {
                $protocol = $row['Protocol'];
            }
            else {
                $protocol = 0;
            }
            file_put_contents($cachefnam,serialize($protocol),LOCK_EX);
        }
        else {
            echo "Failed!";
            xecho($mysqli->errno.': '.$mysqli->error."n");
            die2(2,DMN_CRON_MNFL_SEMAPHORE);
        }
    }

    echo "OK ($protocol)\n";

    xecho("--> Retrieve masternodes list: ");

    $nodes = dmn_masternodes2_get($mysqli, $testnet, $protocol, array(), array(), array());
    if (!is_array($nodes)) {
        echo "Failed!\n";
        die2(1,DMN_CRON_MNFL_SEMAPHORE);
    }
    echo "OK (".count($nodes).")\n";

    // Generate the final list of IP:port (resulting from the query)
    xecho("--> Generating IP and pubkey list: ");
    $mnipstrue = array();
    $mnpubkeystrue = array();
    $mnvinstrue = array();
    foreach($nodes as $key => $node) {
        $tmpvin = $node["MasternodeOutputHash"]."-".$node["MasternodeOutputIndex"];
        if (!in_array($tmpvin,$mnvinstrue)) {
            $mnvinstrue[] = $tmpvin;
        }
        if ($node["MasternodeTor"] != "") {
            $mnip = $node['MasternodeTor'].".onion";
        }
        else {
            $mnip = $node['MasternodeIP'];
        }
        $tmpip = $mnip."-".$node['MasternodePort'];
        if (!in_array($tmpip,$mnipstrue)) {
            $mnipstrue[] = $tmpip;
        }
        $mnpubkeystrue[] = $node['MasternodePubkey'];
        // Clean up data types
        $nodes[$key]["MasternodeOutputIndex"] = intval($nodes[$key]["MasternodeOutputIndex"]);
        $nodes[$key]["MasternodePort"] = intval($nodes[$key]["MasternodePort"]);
        $nodes[$key]["MasternodeProtocol"] = intval($nodes[$key]["MasternodeProtocol"]);
        $nodes[$key]["MasternodeLastSeen"] = intval($nodes[$key]["MasternodeLastSeen"]);
        $nodes[$key]["MasternodeActiveSeconds"] = intval($nodes[$key]["MasternodeActiveSeconds"]);
        $nodes[$key]["MasternodeLastPaid"] = intval($nodes[$key]["MasternodeLastPaid"]);
        if (is_array($nodes[$key]["LastPaidFromBlocks"])) {
            $nodes[$key]["LastPaidFromBlocks"]['MNLastPaidBlock'] = intval($nodes[$key]["LastPaidFromBlocks"]['MNLastPaidBlock']);
            $nodes[$key]["LastPaidFromBlocks"]['MNLastPaidTime'] = intval($nodes[$key]["LastPaidFromBlocks"]['MNLastPaidTime']);
            $nodes[$key]["LastPaidFromBlocks"]['MNLastPaidAmount'] = floatval($nodes[$key]["LastPaidFromBlocks"]['MNLastPaidAmount']);
        }
    }
    echo "OK (".count($mnipstrue)." IPs/".count($mnvinstrue)." Vins/".count($mnpubkeystrue)." pubkeys)\n";

    // Portcheck info
    xecho("--> Retrieving portcheck info: ");
    $portcheck = dmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet);
    if ($portcheck === false) {
        echo "Failed!\n";
        die2(1,DMN_CRON_MNFL_SEMAPHORE);
    }
    foreach($nodes as $key => $node) {
        if ($node["MasternodeTor"] != "") {
            $mnip = $node['MasternodeTor'].".onion";
        }
        else {
            $mnip = $node['MasternodeIP'];
        }
        if (array_key_exists($mnip."-".$node['MasternodePort'],$portcheck)) {
            $nodes[$key]['Portcheck'] = $portcheck[$mnip."-".$node['MasternodePort']];
            $nodes[$key]['Portcheck']['NextCheck'] = intval($nodes[$key]['Portcheck']['NextCheck']);
        }
        else {
            $nodes[$key]['Portcheck'] = false;
        }
    }
    echo "OK (".count($portcheck)." IP:ports)\n";

    // Balance info
    xecho("--> Retrieving balance info: ");
    $balances = dmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet);
    if ($balances === false) {
        echo "Failed!\n";
        die2(1,DMN_CRON_MNFL_SEMAPHORE);
    } else {
        foreach ($nodes as $key => $node) {
            if (array_key_exists($node['MasternodePubkey'], $balances)) {
                $nodes[$key]['Balance'] = array(
                    'Value' => $nodes[$key]['Balance']['Value'] = floatval($balances[$node['MasternodePubkey']]['Value']),
                    'LastUpdate' => $nodes[$key]['Balance']['Value'] = intval($balances[$node['MasternodePubkey']]['LastUpdate'])
                );
            } else {
                $nodes[$key]['Balance'] = false;
            }
        }
    }
    echo "OK (".count($balances)." entries)\n";

    // Generate and save JSON file
    xecho("=> Saving JSON file: ");
    $result = array('status' => 'OK',
                    'data' => array('masternodes' => $nodes,
                                    'cache' => array('time' => time(),
                                                     'fromcache' => true),
                                    'api' => array('version' => 3,
                                                   'compat' => 3,
                                                   'bev' => 'mnfl='.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION)
                        ));
    $result = json_encode($result);
    if ($result === false) {
        echo "Failed JSON Encode!\n";
        die2(3,DMN_CRON_MNFL_SEMAPHORE);
    }
    echo strlen($result)." chars converted";
    $filename = DATAFOLDER.sprintf("masternodeslistfull-%d.json",$testnet);
    $filenameupdate = $filename.".update";
    $resultw = file_put_contents($filenameupdate,$result);
    if ($resultw === false) {
        echo "Failed file save!\n";
        die2(4,DMN_CRON_MNFL_SEMAPHORE);
    }
    echo " - ".$resultw." bytes written\n";

    // Precompressing data
    xecho("=> Compressing JSON.GZ file: ");
    $result = gzcompress($result,9,ZLIB_ENCODING_GZIP);
    echo strlen($result)." bytes compressed";
    $resultw = file_put_contents($filenameupdate.".gz",$result);
    if ($resultw === false) {
        echo "Failed file save!\n";
        die2(5,DMN_CRON_MNFL_SEMAPHORE);
    }
    echo " - ".$resultw." bytes written\n";

    // Making new data available
    xecho("=> Making files available: ");
    touch($filenameupdate);
    touch($filenameupdate.".gz");
    rename($filenameupdate,$filename);
    rename($filenameupdate.".gz",$filename.".gz");
    echo $filename." and ".$filename.".gz\n";
    die2(0,DMN_CRON_MNFL_SEMAPHORE);

}

function generate_blocks24h_json_files($mysqli, $testnet = 0) {

    xecho("Generating 24 blocks list:\n");

    $interval = new DateInterval('P1D');
    $interval->invert = 1;
    $datefrom = new DateTime();
    $datefrom->add( $interval );
    $datefrom = $datefrom->getTimestamp();

    $mnpubkeys = array();
    $budgetids = array();

    if (file_exists(DMN_CRON_BK24_SEMAPHORE) && (posix_getpgid(intval(file_get_contents(DMN_CRON_BK24_SEMAPHORE))) !== false) ) {
        xecho("Already running (PID ".sprintf('%d',file_get_contents(DMN_CRON_BK24_SEMAPHORE)).")\n");
        die(10);
    }
    file_put_contents(DMN_CRON_BK24_SEMAPHORE,sprintf('%s',getmypid()));

    xecho("Retrieving protocol descriptions: ");

    $cachefnam = CACHEFOLDER.sprintf("dashninja_protocolesc_%d",$testnet);
    $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+900)>=time()));
    if ($cachevalid) {
        echo "From cache... ";
        $protocols = unserialize(file_get_contents($cachefnam));
        echo count($protocols)."\n";
    }
    else {
        echo "From database... ";
        $sql = "SELECT Protocol, ProtocolDescription FROM cmd_info_protocol_description";
        $protocols = array();
        if ($result = $mysqli->query($sql)) {
            while($row = $result->fetch_assoc()){
                $protocols[$row['Protocol']] = $row['ProtocolDescription'];
            }
            echo count($protocols)." entries - Saving cache: ";
            $result = file_put_contents($cachefnam,serialize($protocols),LOCK_EX);
            if ($result === false) {
                echo "Failed!\n";
            }
            else {
                echo $result." bytes written!\n";
            }
        }
        elseif (is_readable($cachefnam)) {
            echo "Failed! Fallback to cache... ";
            $protocols = unserialize(file_get_contents($cachefnam));
            echo count($protocols)."\n";
        }
        else {

            die2(200,DMN_CRON_BK24_SEMAPHORE);
        }
    }

    xecho("--> Retrieve all blocks from last 24h: ");

    // Retrieve all blocks for last 24h
    $sql = sprintf("SELECT BlockId, BlockHash, cib.BlockMNPayee BlockMNPayee, BlockMNPayeeDonation, BlockMNValue, BlockSupplyValue, BlockMNPayed, BlockPoolPubKey, PoolDescription, BlockMNProtocol, BlockTime, BlockDifficulty, BlockMNPayeeExpected, BlockMNValueRatioExpected, IsSuperblock, SuperBlockBudgetName, BlockDarkSendTXCount, MemPoolDarkSendTXCount, SuperBlockBudgetPayees, SuperBlockBudgetAmount, BlockVersion FROM cmd_info_blocks cib LEFT JOIN cmd_pools_pubkey cpp ON cib.BlockPoolPubKey = cpp.PoolPubKey AND cib.BlockTestNet = cpp.PoolTestNet WHERE cib.BlockTestNet = %d AND cib.BlockTime >= %d ORDER BY BlockId DESC",$testnet,$datefrom);
    $blocks = array();
    $maxprotocol = 0;
    $blockidlow = 9999999999;
    $blockidhigh = 0;
    $sqlwheretemplate = "BlockHeight = %d";
    $sqlblockids = array();
    if ($result = $mysqli->query($sql)) {
        echo "SQL OK - Retrieving rows: ";
        while($row = $result->fetch_assoc()){
            if ($row['BlockMNProtocol'] > $maxprotocol) {
                $maxprotocol = $row['BlockMNProtocol'];
            }
            if ($row['BlockId'] > $blockidhigh) {
                $blockidhigh = $row['BlockId'];
            }
            if ($row['BlockId'] < $blockidlow) {
                $blockidlow = $row['BlockId'];
            }
            $blocks[intval($row["BlockId"])] = array(
                    "BlockId" => intval($row["BlockId"]),
                    "BlockHash" => $row["BlockHash"],
                    "BlockMNPayee" => $row["BlockMNPayee"],
                    "BlockMNPayeeDonation" => intval($row["BlockMNPayeeDonation"]),
                    "BlockMNValue" => floatval($row["BlockMNValue"]),
                    "BlockSupplyValue" => floatval($row["BlockSupplyValue"]),
                    "BlockMNPayed" => intval($row["BlockMNPayed"]),
                    "BlockPoolPubKey" => $row["BlockPoolPubKey"],
                    "PoolDescription" => $row["PoolDescription"],
                    "BlockMNProtocol" => $row["BlockMNProtocol"],
                    "BlockTime" => intval($row["BlockTime"]),
                    "BlockDifficulty" => floatval($row["BlockDifficulty"]),
                    "BlockMNPayeeExpected" => $row["BlockMNPayeeExpected"],
                    "BlockMNValueRatioExpected" => floatval($row["BlockMNValueRatioExpected"]),
                    "IsSuperBlock" => $row["IsSuperblock"] != 0,
                    "SuperBlockBudgetName" => $row["SuperBlockBudgetName"],
                    "SuperBlockBudgetPayees" => intval($row["SuperBlockBudgetPayees"]),
                    "SuperBlockBudgetAmount" => floatval($row["SuperBlockBudgetAmount"]),
                    "BlockDarkSendTXCount" => intval($row["BlockDarkSendTXCount"]),
                    "MemPoolDarkSendTXCount" => intval($row["MemPoolDarkSendTXCount"]),
                    "BlockVersion" => intval($row["BlockVersion"]),
            );
            $sqlblockids[] = sprintf($sqlwheretemplate,$row['BlockId']);
        }
        echo "OK (".count($blocks).")\n";

        xecho("--> Calculating MN payment ratio: ");
        $curmnpaymentratio = 0.5;
        foreach($blocks as $blockid => $block) {
            $blocks[$blockid]['BlockMNValueRatio'] = round($block['BlockMNValue'] / $block['BlockSupplyValue'], 3);
            if ((count($mnpubkeys) > 0) &&
                !in_array($blocks[$blockid]['BlockMNPayee'], $mnpubkeys) &&
                !in_array($blocks[$blockid]['BlockMNPayeeExpected'], $mnpubkeys)
            ) {
                unset($blocks[$blockid]);
            }
            if ((!$blocks[$blockid]['IsSuperBlock']) && ($blocks[$blockid]['BlockMNValueRatioExpected'] > $curmnpaymentratio)) {
                $curmnpaymentratio = $blocks[$blockid]['BlockMNValueRatioExpected'];
            }
        }
        echo "OK\n";

        xecho("--> Removing blocks array keys: ");
        $blocksnew = array();
        foreach($blocks as $block) {
            $blocksnew[] = $block;
        }
        $blocks = $blocksnew;
        unset($blocksnew);
        echo "OK\n";

        xecho("--> Retrieving masternodes count: ");
        $totalmninfo = 0;
        $uniquemnips = 0;
        $mninfo = dmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
        if ($mninfo === false) {
            echo "Failed!\n";
            die2(201,DMN_CRON_BK24_SEMAPHORE);
        }
        echo count($mninfo)." protocols\n";

        xecho("--> Calculating per version and per miner stats: ");
        $perversion = array();
        $perminer = array();
        foreach($blocks as $block) {
            if (!is_null($block['PoolDescription'])) {
                $minerkey = $block['PoolDescription'];
            }
            else {
                $minerkey = $block['BlockPoolPubKey'];
            }
            if (!array_key_exists($minerkey,$perminer)) {
                $perminer[$minerkey] = array('PoolPubKeys' => array($block['BlockPoolPubKey']),
                        'PoolName' => $block['PoolDescription'],
                        'Blocks' => 0,
                        'BlocksPayed' => 0,
                        'BlocksBudgetPayed' => 0,
                        'TotalAmount' => 0.0,
                        'MasternodeAmount' => 0.0,
                        'SuperBlockPoolAmount' => 0.0,
                        'BudgetAmount' => 0.0,
                        'BlocksPayedToCurrentProtocol' => 0,
                        'BlocksPayedToOldProtocol' => 0,
                        'BlocksPayedCorrectly' => 0,
                        'RatioMNPaymentsExpected' => round($curmnpaymentratio,3));
                if (is_null($perminer[$minerkey]['PoolName'])) {
                    $perminer[$minerkey]['PoolName'] = '';
                }
            }
            if (!in_array($block['BlockPoolPubKey'],$perminer[$minerkey]['PoolPubKeys'])) {
                $perminer[$minerkey]['PoolPubKeys'][] = $block['BlockPoolPubKey'];
            }
            $perminer[$minerkey]['Blocks']++;
            $perminer[$minerkey]['TotalAmount'] += $block['BlockSupplyValue'];
            if ($block['IsSuperBlock']) {
                $perminer[$minerkey]['SuperBlockPoolAmount'] += $block['BlockSupplyValue']-$block['BlockMNValue'];
                $perminer[$minerkey]['BudgetAmount'] += $block['BlockMNValue'];
                $perminer[$minerkey]['BlocksBudgetPayed'] += $block['BlockMNPayed'];
            }
            else {
                $perminer[$minerkey]['MasternodeAmount'] += $block['BlockMNValue'];
                $perminer[$minerkey]['BlocksPayed'] += $block['BlockMNPayed'];
            }
            if (!array_key_exists($block['BlockMNProtocol'],$perversion)) {
                if (array_key_exists($block['BlockMNProtocol'],$mninfo)) {
                    $mncount = $mninfo[$block['BlockMNProtocol']]['ActiveMasternodesCount'];
                    $mnuniqueips = $mninfo[$block['BlockMNProtocol']]['UniqueActiveMasternodesIPs'];
                }
                else {
                    $mncount = 0;
                    $mnuniqueips = 0;
                }
                if (array_key_exists($block['BlockMNProtocol'],$protocols)) {
                    $protocoldesc = $protocols[$block['BlockMNProtocol']];
                }
                else {
                    $protocoldesc = $protocols[0];
                }
                $perversion[$block['BlockMNProtocol']] = array('ProtocolDesc' => $protocoldesc,
                        'Blocks' => 0,
                        'BlocksPayed' => 0,
                        'Amount' => 0.0,
                        'BlocksPayedCorrectRatio' => 0.0,
                        'BlocksPayedIncorrectRatio' => 0.0,
                        'MasternodesPopulation' => $mncount,
                        'MasternodesUniqueIPs' => $mnuniqueips,
                        'EstimatedMNDailyEarnings' => 0.0);
            }
            $perversion[$block['BlockMNProtocol']]['Blocks']++;
            $perversion[$block['BlockMNProtocol']]['Amount'] += $block['BlockMNValue'];
            $perversion[$block['BlockMNProtocol']]['BlocksPayed'] += $block['BlockMNPayed'];
            if (round($block['BlockMNValueRatio'],3) == round($block['BlockMNValueRatioExpected'],3)) {
                $perversion[$block['BlockMNProtocol']]['BlocksPayedCorrectRatio']++;
                $correctpayment = true;
            }
            elseif ($block['BlockMNValueRatio'] > 0) {
                $perversion[$block['BlockMNProtocol']]['BlocksPayedIncorrectRatio']++;
                $correctpayment = false;
            }
            if ($block['BlockMNProtocol'] == $maxprotocol) {
                $perminer[$minerkey]['BlocksPayedToCurrentProtocol'] += $block['BlockMNPayed'];
                if ($correctpayment) {
                    $perminer[$minerkey]['BlocksPayedCorrectly']++;
                }
            }
            else {
                $perminer[$minerkey]['BlocksPayedToOldProtocol'] += $block['BlockMNPayed'];
            }
        }
        echo "Stage 1 done... ";
        foreach($perversion as $protocol => $info) {
            if ($protocol == 0) {
                $perversion[$protocol]['EstimatedMNDailyEarnings'] = 0;
            } else {
                if ($info['MasternodesPopulation'] != 0) {
                    $perversion[$protocol]['EstimatedMNDailyEarnings'] = $info['Amount'] / $info['MasternodesPopulation'];
                }
                else {
                    $perversion[$protocol]['EstimatedMNDailyEarnings'] = 0;
                }
            }
            $perversion[$protocol]['RatioBlocksAll'] = $info['Blocks'] / count($blocks);
            $perversion[$protocol]['RatioBlocksPayed'] = $info['BlocksPayed'] / count($blocks);
            $perversion[$protocol]['RatioBlocksPayedIncorrectRatio'] = $info['BlocksPayedIncorrectRatio'] / count($blocks);
            $perversion[$protocol]['RatioBlocksPayedCorrectRatio'] = $info['BlocksPayedCorrectRatio'] / count($blocks);
        }
        ksort($perversion,SORT_NUMERIC);
        echo count($perversion)." versions... ";
        $globalstats = array('Blocks' => count($blocks),
                'BlocksPayed' => 0,
                'BlocksPayedToCurrentProtocol' => 0,
                'BlocksPayedCorrectly' => 0,
                'SupplyAmount' => 0.0,
                'MNPaymentsAmount' => 0.0);
        foreach($perminer as $miner => $info) {
            $divamount = ($perminer[$miner]['TotalAmount']-$perminer[$miner]['BudgetAmount']-$perminer[$miner]['SuperBlockPoolAmount']);
            if ($divamount == 0) {
                $perminer[$miner]['RatioMNPayments'] = 1;
            }
            else {
                $perminer[$miner]['RatioMNPayments'] = round($perminer[$miner]['MasternodeAmount'] / $divamount,3);
            }
            if (count($blocks) == 0) {
                $perminer[$miner]['RatioBlocksFound'] = 0;
            }
            else {
                $perminer[$miner]['RatioBlocksFound'] = $perminer[$miner]['Blocks'] / count($blocks);
            }
            $perminer[$miner]['RatioBlocksPayed'] = ($perminer[$miner]['BlocksPayed']+$perminer[$miner]['BlocksBudgetPayed']) / $perminer[$miner]['Blocks'];
            $perminer[$miner]['RatioBlocksPayedToCurrentProtocol'] = $perminer[$miner]['BlocksPayedToCurrentProtocol'] / $perminer[$miner]['Blocks'];
            $perminer[$miner]['RatioBlocksPayedToOldProtocol'] = $perminer[$miner]['BlocksPayedToOldProtocol'] / $perminer[$miner]['Blocks'];
            $perminer[$miner]['RatioBlocksPayedCorrectly'] = $perminer[$miner]['BlocksPayedCorrectly'] / $perminer[$miner]['Blocks'];
            $globalstats['BlocksPayed'] += $perminer[$miner]['BlocksPayed'];
            $globalstats['BlocksPayedToCurrentProtocol'] += $perminer[$miner]['BlocksPayedToCurrentProtocol'];
            $globalstats['BlocksPayedCorrectly'] += $perminer[$miner]['BlocksPayedCorrectly'];
            $globalstats['SupplyAmount'] += $perminer[$miner]['TotalAmount'];
            $globalstats['MNPaymentsAmount'] += $perminer[$miner]['MasternodeAmount'];
        }
        echo count($perminer)." miners... ";

        if ($globalstats['Blocks'] != 0) {
            $globalstats['RatioBlocksPayed'] = $globalstats['BlocksPayed'] / $globalstats['Blocks'];
            $globalstats['RatioBlocksPayedToCurrentProtocol'] = $globalstats['BlocksPayedToCurrentProtocol'] / $globalstats['Blocks'];
            $globalstats['RatioBlocksPayedCorrectly'] = $globalstats['BlocksPayedCorrectly'] / $globalstats['Blocks'];
        }
        else {
            $globalstats['RatioBlocksPayed'] = 0;
            $globalstats['RatioBlocksPayedToCurrentProtocol'] = 0;
            $globalstats['RatioBlocksPayedCorrectly'] = 0;
        }

        echo "OK\n";

        // Generate and save JSON file
        xecho("=> Saving JSON file: ");

        $result = array('status' => 'OK',
                        'data' => array('blocks' => $blocks,
            'stats' => array('perversion' => $perversion,
                'perminer' => $perminer,
                'global' => $globalstats
            ),
            'cache' => array(
                'time' => time(),
                'fromcache' => true
            ),
            'api' => array(
                'version' => 3,
                'compat' => 1,
                'bev' => 'bk24h='.DASHNINJA_BEV.".".DASHNINJA_CRONVERSION
            )
        ));
        $result = json_encode($result);
        if ($result === false) {
            echo "Failed JSON Encode!\n";
            die2(3,DMN_CRON_BK24_SEMAPHORE);
        }
        echo strlen($result)." chars converted";
        $filename = DATAFOLDER.sprintf("blocks24h-%d.json",$testnet);
        $filenameupdate = $filename.".update";
        $resultw = file_put_contents($filenameupdate,$result);
        if ($resultw === false) {
            echo "Failed file save!\n";
            die2(4,DMN_CRON_BK24_SEMAPHORE);
        }
        echo " - ".$resultw." bytes written\n";

        // Precompressing data
        xecho("=> Compressing JSON.GZ file: ");
        $result = gzcompress($result,9,ZLIB_ENCODING_GZIP);
        echo strlen($result)." bytes compressed";
        $resultw = file_put_contents($filenameupdate.".gz",$result);
        if ($resultw === false) {
            echo "Failed file save!\n";
            die2(5,DMN_CRON_BK24_SEMAPHORE);
        }
        echo " - ".$resultw." bytes written\n";

        // Making new data available
        xecho("=> Making files available: ");
        touch($filenameupdate);
        touch($filenameupdate.".gz");
        rename($filenameupdate,$filename);
        rename($filenameupdate.".gz",$filename.".gz");
        echo $filename." and ".$filename.".gz\n";
        die2(0,DMN_CRON_BK24_SEMAPHORE);

    }
    else {
        echo "Failed!";
        xecho($mysqli->errno.': '.$mysqli->error."n");
        die2(2,DMN_CRON_BK24_SEMAPHORE);
    }
}


xecho('DASH Ninja Front-End JSON Generator cron v'.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION."\n");

if ($argc != 3) {
    xecho("Usage: ".$argv[0]." main|testnet <command>\n");
    xecho("Command can be: masternodeslistfull = Generate the full masternodes list in data folder\n");
    xecho("                blocks24h = Generate the full last 24h blocks list in data folder\n");
    die(10);
}

$testnet = 0;
if ($argv[1] == "test") {
    $testnet = 1;
    xecho("===== Test net =====\n");
}
else {
    xecho("===== Main net =====\n");
}

if ($argv[2] == "masternodeslistfull") {
    generate_masternodeslistfull_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "blocks24h") {
    generate_blocks24h_json_files($mysqli, $testnet);
}
else {
    xecho("Unknown command ".$argv[2]."\n");
    die(11);
}

?>