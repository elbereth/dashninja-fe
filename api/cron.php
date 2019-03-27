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

define("DASHNINJA_CRONVERSION","3");

// Load configuration and connect to DB
require_once('libs/db.inc.php');

// Get common queries functions
require_once('libs/common-queries.inc.php');

// Display log line (with date)
function xecho($line) {
    echo date('Y-m-d H:i:s').' - '.$line;
}

// Die but delete semaphore file before
function die2($retcode,$semaphorefile) {
    unlink($semaphorefile);
    die($retcode);
}

// Check and set semaphore file
function semaphore($semaphore) {

    if (file_exists($semaphore) && (posix_getpgid(intval(file_get_contents($semaphore))) !== false) ) {
        xecho("Already running (PID ".sprintf('%d',file_get_contents($semaphore)).")\n");
        die(10);
    }
    file_put_contents($semaphore,sprintf('%s',getmypid()));

}

// Save and GZIP pre-compress data
function save_json($filenameprefix,$data,$semaphore,$testnet) {

    // Generate and save JSON file
    xecho("=> Saving JSON file: ");
    $result = json_encode($data);
    if ($result === false) {
        echo "Failed JSON Encode!\n";
        die2(302,$semaphore);
    }
    echo strlen($result)." chars converted";
    $filename = DATAFOLDER.sprintf($filenameprefix."-%d.json",$testnet);
    $filenameupdate = $filename.".update";
    $resultw = file_put_contents($filenameupdate,$result);
    if ($resultw === false) {
        echo "Failed file save!\n";
        die2(304,$semaphore);
    }
    echo " - ".$resultw." bytes written\n";

    // Precompressing data
    xecho("=> Compressing JSON.GZ file: ");
    $result = gzcompress($result,9,ZLIB_ENCODING_GZIP);
    echo strlen($result)." bytes compressed";
    $resultw = file_put_contents($filenameupdate.".gz",$result);
    if ($resultw === false) {
        echo "Failed file save!\n";
        die2(5,$semaphore);
    }
    echo " - ".$resultw." bytes written\n";

    // Making new data available
    xecho("=> Making files available: ");
    touch($filenameupdate);
    touch($filenameupdate.".gz");
    rename($filenameupdate,$filename);
    rename($filenameupdate.".gz",$filename.".gz");
    echo $filename." and ".$filename.".gz\n";
    die2(0,$semaphore);

}

function generate_masternodeslistfull_json_files($mysqli, $testnet = 0) {

    xecho("Generating full masternodes list:\n");
    semaphore(DMN_CRON_MNFL_SEMAPHORE);

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

    $cachevalid = false;
    $nodes = dmn_masternodes2_get($mysqli, $testnet, $protocol, array(), array(), array(), $cachevalid, false);
    if (!is_array($nodes)) {
        echo "Failed!\n";
        die2(1,DMN_CRON_MNFL_SEMAPHORE);
    }
    echo "OK";
    if ($cachevalid) {
        echo " [CACHED] ";
    }
    else {
        echo " [DATABASE] ";
    }
    echo "(".count($nodes).")\n";

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
    $cachevalid = false;
    $portcheck = dmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet,$cachevalid, false);
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
    echo "OK";
    if ($cachevalid) {
        echo " [CACHED] ";
    }
    else {
        echo " [DATABASE] ";
    }
    echo "(".count($portcheck)." IP:ports)\n";

    // Balance info
    xecho("--> Retrieving balance info: ");
    $cachevalid = false;
    $balances = dmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet, $cachevalid,false);
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
    echo "OK";
    if ($cachevalid) {
        echo " [CACHED] ";
    }
    else {
        echo " [DATABASE] ";
    }
    echo "(".count($balances)." entries)\n";

    $data = array('status' => 'OK',
        'data' => array('masternodes' => $nodes,
            'cache' => array('time' => time(),
                'fromcache' => true),
            'api' => array('version' => 4,
                'compat' => 3,
                'bev' => 'mnfl='.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION)
        ));

    save_json("masternodeslistfull",$data,DMN_CRON_MNFL_SEMAPHORE,$testnet);

}

function generate_protxfull_json_files($mysqli, $testnet = 0) {

    xecho("Generating full deterministric masternodes list:\n");
    semaphore(DMN_CRON_PROTX_SEMAPHORE);

    xecho("--> Retrieve deterministric masternodes list: ");

    $nodes = dmn_protx_get($mysqli, $testnet, array(), array(), array());
    if (!is_array($nodes)) {
        echo "Failed!\n";
        die2(1,DMN_CRON_PROTX_SEMAPHORE);
    }
    echo "OK (".count($nodes).")\n";

    if (is_array($nodes)) {

      // Generate the final list of IP:port (resulting from the query)
      $mnipstrue = array();
      $mnpubkeystrue = array();
      $mnvinstrue = array();
      foreach ($nodes as $node) {
        $tmpvin = $node["collateralHash"] . "-" . $node["collateralIndex"];
        if (!in_array($tmpvin, $mnvinstrue)) {
          $mnvinstrue[] = $tmpvin;
        }
        $tmpip = $node['state']['addrIP'] . "-" . $node['state']['addrPort'];
        if (!in_array($tmpip, $mnipstrue)) {
          $mnipstrue[] = $tmpip;
        }
        if (!in_array($node['state']['payoutAddress'], $mnpubkeystrue)) {
          $mnpubkeystrue[] = $node['state']['payoutAddress'];
        }
      }

      $portcheck = dmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet);
      if ($portcheck === false) {
        echo "Failed (portcheck step)!\n";
        die2(1, DMN_CRON_PROTX_SEMAPHORE);
      } else {
        foreach ($nodes as $key => $node) {
          if (array_key_exists($node['state']['addrIP'] . "-" . $node['state']['addrPort'], $portcheck)) {
            $nodes[$key]['Portcheck'] = $portcheck[$node['state']['addrIP'] . "-" . $node['state']['addrPort']];
          } else {
            $nodes[$key]['Portcheck'] = false;
          }
        }
      }

      $balances = dmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet);
      if ($balances === false) {
        echo "Failed (balance step)!\n";
        die2(1, DMN_CRON_PROTX_SEMAPHORE);
      } else {
        foreach ($nodes as $key => $node) {
          if (array_key_exists($node['state']['payoutAddress'], $balances)) {
            $nodes[$key]['Balance'] = $balances[$node['state']['payoutAddress']];
          } else {
            $nodes[$key]['Balance'] = false;
          }
        }
      }
    }

    $data = array('status' => 'OK',
        'data' => array('protx' => $nodes,
            'cache' => array('time' => time(),
                'fromcache' => true),
            'api' => array('version' => 1,
                'compat' => 1,
                'bev' => 'protx='.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION)
        ));

    save_json("protxfull",$data,DMN_CRON_PROTX_SEMAPHORE,$testnet);

}

function generate_blocks24h_json_files($mysqli, $testnet = 0) {

    xecho("Generating 24 blocks list:\n");
    semaphore(DMN_CRON_BK24_SEMAPHORE);

    $interval = new DateInterval('P1D');
    $interval->invert = 1;
    $datefrom = new DateTime();
    $datefrom->add( $interval );
    $datefrom = $datefrom->getTimestamp();

    $mnpubkeys = array();
    $budgetids = array();

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
    $maxversion = 0;
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
            // Hack... for antpool still signaling DIP3
            if ($row['BlockVersion'] == 0x20000008) {
              $row['BlockVersion'] = 0x20000000;
            }
            // End of hack...
            if ($row['BlockVersion'] > $maxversion) {
                $maxversion = $row['BlockVersion'];
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
                    "BlockMNProtocol" => intval($row["BlockMNProtocol"]),
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
        $protocoldesc = array();
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
            if (!array_key_exists($block['BlockMNProtocol'],$protocoldesc)) {
                if (array_key_exists($block['BlockMNProtocol'], $protocols)) {
                    $protocoldesc[$block['BlockMNProtocol']] = $protocols[$block['BlockMNProtocol']];
                } else {
                    $protocoldesc[$block['BlockMNProtocol']] = $protocols[0] ;
                }
            }
            if (!array_key_exists($block['BlockVersion'],$perversion)) {
                if (array_key_exists($block['BlockMNProtocol'],$mninfo)) {
                    $mncount = $mninfo[$block['BlockMNProtocol']]['ActiveMasternodesCount'];
                    $mnuniqueips = $mninfo[$block['BlockMNProtocol']]['ActiveMasternodesUniqueIPs'];
                }
                else {
                    $mncount = 0;
                    $mnuniqueips = 0;
                }
                $perversion[$block['BlockVersion']] = array('BlockVersionDesc' => '0x'.dechex($block['BlockVersion']),
                        'Blocks' => 0,
                        'BlocksPayed' => 0,
                        'Amount' => 0.0,
                        'BlocksPayedCorrectRatio' => 0.0,
                        'BlocksPayedIncorrectRatio' => 0.0,
                        'MasternodesPopulation' => $mncount,
                        'MasternodesUniqueIPs' => $mnuniqueips,
                        'EstimatedMNDailyEarnings' => 0.0);
            }
            $perversion[$block['BlockVersion']]['Blocks']++;
            $perversion[$block['BlockVersion']]['Amount'] += $block['BlockMNValue'];
            $perversion[$block['BlockVersion']]['BlocksPayed'] += $block['BlockMNPayed'];
            if (round($block['BlockMNValueRatio'],3) == round($block['BlockMNValueRatioExpected'],3)) {
                $perversion[$block['BlockVersion']]['BlocksPayedCorrectRatio']++;
                $correctpayment = true;
            }
            elseif ($block['BlockMNValueRatio'] > 0) {
                $perversion[$block['BlockVersion']]['BlocksPayedIncorrectRatio']++;
                $correctpayment = false;
            }
            if ($block['BlockVersion'] == $maxversion) {
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
                'MNPaymentsAmount' => 0.0,
                'MaxProtocol' => intval($maxprotocol),
                'MaxVersion' => intval($maxversion));
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

        $data = array('status' => 'OK',
                        'data' => array('blocks' => $blocks,
            'stats' => array('perversion' => $perversion,
                'perminer' => $perminer,
                'global' => $globalstats,
                'protocoldesc' => $protocoldesc
            ),
            'cache' => array(
                'time' => time(),
                'fromcache' => true
            ),
            'api' => array(
                'version' => 4,
                'compat' => 4,
                'bev' => 'bk24h='.DASHNINJA_BEV.".".DASHNINJA_CRONVERSION
            )
        ));
        save_json("blocks24h",$data,DMN_CRON_BK24_SEMAPHORE,$testnet);

    }
    else {
        echo "Failed!";
        xecho($mysqli->errno.': '.$mysqli->error."n");
        die2(2,DMN_CRON_BK24_SEMAPHORE);
    }
}

function generate_nodesstatus_json_files($mysqli, $testnet = 0) {

    xecho("Generating monitoring nodes status:\n");
    semaphore(DMN_CRON_MNST_SEMAPHORE);

    xecho("--> Retrieve nodes status: ");


    $sql = "SELECT NodeName, NodeTestNet, NodeEnabled, NodeProcessStatus, NodeVersion, NodeProtocol, NodeBlocks, NodeLastBlockHash, NodeConnections, UNIX_TIMESTAMP(LastUpdate) LastUpdate FROM cmd_nodes n, cmd_nodes_status s WHERE n.NodeId = s.NodeId AND n.NodeTestNet = %d ORDER BY NodeName";
    $sqlx = sprintf($sql,$testnet);
    if ($result = $mysqli->query($sqlx)) {
        echo "SQL OK - Retrieving rows: ";
        $nodes = array();
        while ($row = $result->fetch_assoc()) {
            $nodes[] = $row;
        }
    }
    else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_MNST_SEMAPHORE);
    }

    echo "OK (".count($nodes).")\n";

    $data = array('status' => 'OK',
        'data' => array('nodes' => $nodes,
            'cache' => array('time' => time(),
                'fromcache' => true),
            'api' => array('version' => 3,
                'compat' => 3,
                'bev' => 'mnst='.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION)
        ));
    save_json("nodesstatus",$data,DMN_CRON_MNST_SEMAPHORE,$testnet);

}

function generate_blocksconsensus_json_files($mysqli, $testnet = 0) {

    xecho("Generating blocks consensus status:\n");
    semaphore(DMN_CRON_BKCS_SEMAPHORE);

    xecho("--> Retrieve blocks consensus status: ");

    $sql = sprintf("SELECT BlockHeight, BlockMNPayee, BlockMNRatio, Protocol, NodeName FROM `cmd_info_blocks_history2` cibh, cmd_nodes cn WHERE cibh.NodeID = cn.NodeID AND cibh.BlockTestNet = %d ORDER BY BlockHeight DESC LIMIT 160",$testnet);
    $numblocks = 0;
    $curblock = -1;
    $bhinfo = array();
    if ($result = $mysqli->query($sql)) {
        echo "SQL OK - Retrieving rows: ";
        while (($row = $result->fetch_assoc()) && ($numblocks < 11)) {
            if ($row['BlockHeight'] != $curblock) {
                $curblock = $row['BlockHeight'];
                $numblocks++;
            }
            if ($numblocks < 11) {
                if (!array_key_exists($row['BlockHeight'], $bhinfo)) {
                    $bhinfo[$row['BlockHeight']] = array();
                }
                if (!array_key_exists($row['Protocol'], $bhinfo[$row['BlockHeight']])) {
                    $bhinfo[$row['BlockHeight']][$row['Protocol']] = array();
                }
                if (!array_key_exists($row['BlockMNPayee'], $bhinfo[$row['BlockHeight']][$row['Protocol']])) {
                    $bhinfo[$row['BlockHeight']][$row['Protocol']][$row['BlockMNPayee']] = array('count' => 0,
                        'names' => array());
                }
                $bhinfo[$row['BlockHeight']][$row['Protocol']][$row['BlockMNPayee']]['count']++;
                $bhinfo[$row['BlockHeight']][$row['Protocol']][$row['BlockMNPayee']]['names'][] = $row['NodeName'];
            }
        }
        echo "OK (".count($bhinfo).")\n";

        xecho("--> Computing max protocol per block height: ");
        foreach ($bhinfo as $bhid => $bhdata) {
            $maxprotocol[$bhid] = 0;
            foreach ($bhdata as $protocol => $bhpayee) {
                if ($protocol > $maxprotocol[$bhid]) {
                    $maxprotocol[$bhid] = $protocol;
                }
            }
        }
        echo "OK (".count($maxprotocol).")\n";

        xecho("--> Computing consensus: ");
        $bhinfofinal = array();
        foreach ($maxprotocol as $bhid => $protocol) {
            $totalnodes = 0;
            foreach ($bhinfo[$bhid][$protocol] as $pubkey => $cinfo) {
                $totalnodes += $cinfo['count'];
            }
            $maxconsensus = 0;
            foreach ($bhinfo[$bhid][$protocol] as $pubkey => $cinfo) {
                $bhinfo[$bhid][$protocol][$pubkey]['consensus'] = $cinfo['count'] / $totalnodes;
                if ($bhinfo[$bhid][$protocol][$pubkey]['consensus'] > $maxconsensus) {
                    $maxconsensus = $bhinfo[$bhid][$protocol][$pubkey]['consensus'];
                }
            }
            $maxconsensusfound = false;
            $maxconsensuspubkey = '';
            $otherconsensus = array();
            foreach ($bhinfo[$bhid][$protocol] as $pubkey => $cinfo) {
                if (($cinfo['consensus'] == $maxconsensus) && !$maxconsensusfound) {
                    $maxconsensusfound = true;
                    $maxconsensuspubkey = $pubkey;
                } else {
                    sort($cinfo['names']);
                    $otherconsensus[] = array('Payee' => $pubkey,
                        'RatioVotes' => $cinfo['count'] / $totalnodes,
                        'NodeNames' => $cinfo['names']);
                }
            }
            $bhinfofinal[] = array('BlockID' => $bhid,
                'Consensus' => $maxconsensus,
                'ConsensusPubKey' => $maxconsensuspubkey,
                'Others' => $otherconsensus);
        }
        echo "OK (".count($bhinfofinal).")\n";
    }
    else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_BKCS_SEMAPHORE);
    }

    $data = array('status' => 'OK',
        'data' => array('blocksconsensus' => $bhinfofinal,
            'cache' => array('time' => time(),
                'fromcache' => true),
            'api' => array('version' => 3,
                'compat' => 3,
                'bev' => 'bkcs='.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION)
        ));
    save_json("blocksconsensus",$data,DMN_CRON_BKCS_SEMAPHORE,$testnet);

}

function generate_governancevotelimit_json_files($mysqli, $testnet = 0) {

    xecho("Generating governance vote limit:\n");
    semaphore(DMN_CRON_GOVL_SEMAPHORE);

    xecho("--> Retrieve current block: ");
    $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1", $testnet);
    if ($result = $mysqli->query($sql)) {
        $currentblock = $result->fetch_assoc();
        $currentblock["BlockId"] = intval($currentblock["BlockId"]);
        $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
        $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
    } else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_GOVL_SEMAPHORE);
    }
    echo "OK (".$currentblock["BlockId"].")\n";

    if ($testnet == 0) {
        $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
    } else {
        $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 50) + 50;
    }

    xecho("--> Retrieve next superblock height: ");
    $keyst = "governancesb";
    if ($testnet == 1) {
        $keyst .= "test";
    }
    $sql = sprintf('SELECT `StatKey`, `StatValue` FROM `cmd_stats_values` WHERE StatKey = "%s" LIMIT 2', $keyst);
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            if (!is_null($row["StatValue"]) && ($row["StatValue"] > 0)) {
                if ($row["StatKey"] == $keyst) {
                    $nextsuperblock = floatval($row["StatValue"]);
                }
            }
        }
    } else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_GOVL_SEMAPHORE);
    }
    echo "OK (".$nextsuperblock.")\n";

    xecho("--> Computing vote limit: ");
    // Vote limit is 1662 blocks before next superblock
    $nextsuperblockid = $nextsuperblock - 1662;
    $nextsuperblocktime = round($currentblock["BlockTime"]+((($nextsuperblock - 1662 - $currentblock["BlockId"])/553.85)*86400));
    if ($nextsuperblockid <= $currentblock["BlockId"]) {
        $nextsuperblockid = 0;
        $nextsuperblocktime = 0;
    }
    $nextvotelimitblock = array(
        "BlockId" => $nextsuperblockid,
        "BlockTime" => $nextsuperblocktime
    );

    $nextsuperblock = array(
        "BlockId" => $nextsuperblock,
        "BlockTime" => round($currentblock["BlockTime"]+((($nextsuperblock - $currentblock["BlockId"])/553.85)*86400))
    );
    echo "OK\n";

    $data = array('status' => 'OK',
        'data' => array('votelimit' => array(
        'nextvote' => $nextvotelimitblock,
        'nextsuperblock' => $nextsuperblock,
        'latestblock' => $currentblock
    ),
        'cache' => array(
            'time' => time(),
            'fromcache' => true
        ),
        'api' => array(
            'version' => 1,
            'compat' => 1,
            'bev' => 'gpvl=' . DASHNINJA_BEV . "." . DASHNINJA_CRONVERSION
        )
    ));

    save_json("votelimit",$data,DMN_CRON_GOVL_SEMAPHORE,$testnet);

}

function cmpproposals($a, $b)
{
    if ($a["AbsoluteYes"] == $b["AbsoluteYes"]) {
        if ($a["Yes"] == $b["Yes"]) {
            return 0;
        }
        else {
            return ($a["Yes"] > $b["Yes"]) ? -1 : 1;
        }
    }
    return ($a["AbsoluteYes"] > $b["AbsoluteYes"]) ? -1 : 1;
}

function generate_governanceproposals_json_files($mysqli, $testnet = 0) {

    xecho("Generating governance proposals:\n");
    semaphore(DMN_CRON_GOPR_SEMAPHORE);

    xecho("--> Retrieve current block: ");
    // Retrieve current block
    $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1",$testnet);
    if ($result = $mysqli->query($sql)) {
        $currentblock = $result->fetch_assoc();
        $currentblock["BlockId"] = intval($currentblock["BlockId"]);
        $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
        $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
    }
    else {
        echo "SQL error - " . $mysqli->errno . ": " . $mysqli->error . "\n";
        die2(301, DMN_CRON_GOPR_SEMAPHORE);
    }

    // Calculate next superblock height
    $nSubsidy = 5;
    if ($testnet == 0) {
        $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
        for ($i = 210240; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy / 14;
        $estimatedbudgetamount = (($nSubsidy / 100) * 10) * (60 * 24 * 30) / 2.6;
    } else {
        $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 50) + 50;
        for ($i = 46200; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy / 14;
        $estimatedbudgetamount = (($nSubsidy / 100) * 10) * 50;
    }

    // Calculate next superblock timestamp
    $nextsuperblocktimestamp = round($currentblock['BlockTime']+(($nextsuperblock-$currentblock['BlockId'])/553.85)*86400);
    echo "OK (".$currentblock["BlockId"]." / Estimated budget amount: ".$estimatedbudgetamount." DASH)\n";

    // Get governance proposals
    $sql = sprintf("SELECT * FROM cmd_gobject_proposals WHERE GovernanceObjectTestnet = %d",$testnet);

    if ($result = $mysqli->query($sql)) {
        $proposalsvalid = 0;
        $proposalsfunded = 0;
        $proposals = array();
        while ($row = $result->fetch_assoc()) {
            $proposals[] = array(
                "Name" => stripslashes($row["GovernanceObjectName"]),
                "Hash" => stripslashes($row["GovernanceObjectId"]),
                "CollateralHash" => stripslashes($row["GovernanceObjectCollateral"]),
                "URL" => stripslashes($row["GovernanceObjectURL"]),
                "EpochStart" => intval($row["GovernanceObjectEpochStart"]),
                "EpochEnd" => intval($row["GovernanceObjectEpochEnd"]),
                "PaymentAddress" => stripslashes($row["GovernanceObjectPaymentAddress"]),
                "PaymentAmount" => floatval($row["GovernanceObjectPaymentAmount"]),
                "AbsoluteYes" => intval($row["GovernanceObjectVotesAbsoluteYes"]),
                "Yes" => intval($row["GovernanceObjectVotesYes"]),
                "No" => intval($row["GovernanceObjectVotesNo"]),
                "Abstain" => intval($row["GovernanceObjectVotesAbstain"]),
                "BlockchainValidity" => ($row["GovernanceObjectBlockchainValidity"] == 1),
                "IsValidReason" => stripslashes($row["GovernanceObjectIsValidReason"]),
                "CachedValid" => ($row["GovernanceObjectCachedValid"] == 1),
                "CachedFunding" => ($row["GovernanceObjectCachedFunding"] == 1),
                "CachedDelete" => ($row["GovernanceObjectCachedDelete"] == 1),
                "CachedEndorsed" => ($row["GovernanceObjectCachedEndorsed"] == 1),
                "FirstReported" => strtotime($row["FirstReported"]),
                "LastReported" => strtotime($row["LastReported"])
            );
            $proposalsvalid += intval($row["GovernanceObjectBlockchainValidity"]);
            if (($row['GovernanceObjectEpochStart'] <= $nextsuperblocktimestamp) && ($row['GovernanceObjectEpochEnd'] > time())) {
                $proposalsfunded += intval($row["GovernanceObjectCachedFunding"]);
            }
        }

        usort($proposals,"cmpproposals");

        $totalmninfo = 0;
        $uniquemnips = 0;
        $mninfo = dmn_masternodes_count($mysqli, $testnet, $totalmninfo, $uniquemnips);
        if ($mninfo === false) {
            echo "SQL error - " . $mysqli->errno . ": " . $mysqli->error . "\n";
            die2(301, DMN_CRON_GOPR_SEMAPHORE);
        }

        $keyst1 = "governancebudget";
        $keyst2 = "governancesb";
        if ($testnet == 1) {
            $keyst1 .= "test";
            $keyst2 .= "test";
        }
        $sql = sprintf('SELECT `StatKey`, `StatValue` FROM `cmd_stats_values` WHERE StatKey = "%s" OR  StatKey = "%s" LIMIT 2', $keyst1, $keyst2);
        if ($result = $mysqli->query($sql)) {
            while ($row = $result->fetch_assoc()) {
                if (!is_null($row["StatValue"]) && ($row["StatValue"] > 0)) {
                    if ($row["StatKey"] == $keyst1) {
                        $estimatedbudgetamount = floatval($row["StatValue"]);
                    } elseif ($row["StatKey"] == $keyst2) {
                        $nextsuperblock = floatval($row["StatValue"]);
                    }
                }
            }
        } else {
            echo "SQL error - " . $mysqli->errno . ": " . $mysqli->error . "\n";
            die2(301, DMN_CRON_GOPR_SEMAPHORE);
        }
    }
    else {
        echo "SQL error - " . $mysqli->errno . ": " . $mysqli->error . "\n";
        die2(301, DMN_CRON_GOPR_SEMAPHORE);
    }

    $budgetleft = $estimatedbudgetamount;
    foreach($proposals as $proposalkey => $proposal) {
        $proposals[$proposalkey]["FundedSB"] = (($proposal['EpochStart'] <= $nextsuperblocktimestamp) && ($proposal['EpochEnd'] > time()) && ($proposal["EpochEnd"] >= $nextsuperblocktimestamp)
            && ($proposal["PaymentAmount"] <= $budgetleft) && ($proposal["CachedFunding"]));
        if ($proposals[$proposalkey]["FundedSB"]) {
            $proposalsfunded++;
            $budgetleft -= $proposal["PaymentAmount"];
        }
    }

    $data = array('status' => 'OK',
        'data' => array('governanceproposals' => $proposals,
        'stats' => array(
            'valid' => $proposalsvalid,
            'funded' => $proposalsfunded,
            'totalmns' => intval($totalmninfo),
            'nextsuperblock' => array(
                "blockheight" => $nextsuperblock,
                "estimatedbudgetamount" => $estimatedbudgetamount,
                "estimatedblocktime" => $nextsuperblocktimestamp
            ),
            'latestblock' => $currentblock
        ),
        'cache' => array(
            'time' => time(),
            'fromcache' => true
        ),
        'api' => array(
            'version' => 2,
            'compat' => 1,
            'bev' => 'gp='.DASHNINJA_BEV.".".DASHNINJA_CRONVERSION
        )
        ));


    save_json("governanceproposals",$data,DMN_CRON_GOPR_SEMAPHORE,$testnet);

}

function generate_governancetriggers_json_files($mysqli, $testnet = 0) {

    xecho("Generating governance superblock tiggers:\n");
    semaphore(DMN_CRON_GOTR_SEMAPHORE);

    xecho("--> Retrieve current block: ");
    $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1", $testnet);
    if ($result = $mysqli->query($sql)) {
        $currentblock = $result->fetch_assoc();
        $currentblock["BlockId"] = intval($currentblock["BlockId"]);
        $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
        $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
    } else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_GOTR_SEMAPHORE);
    }
    echo "OK (".$currentblock["BlockId"].")\n";

    xecho("--> Retrieve governance triggers: ");
    // Get governance triggers
    $sql = sprintf("SELECT cgot.GovernanceObjectId Hash, cgot.GovernanceObjectEventBlockHeight BlockHeight, "
        ." cgot.GovernanceObjectVotesAbsoluteYes AbsoluteYes, cgot.GovernanceObjectVotesYes Yes, "
        ." cgot.GovernanceObjectVotesNo No, cgot.GovernanceObjectVotesAbstain Abstain, "
        ." cgot.GovernanceObjectBlockchainValidity BlockchainValidity, "
        ." cgot.GovernanceObjectIsValidReason IsValidReason, "
        ." cgot.GovernanceObjectCachedValid CachedValid, "
        ." cgot.GovernanceObjectCachedFunding CachedFunding, "
        ." cgot.GovernanceObjectCachedDelete CachedDelete, "
        ." cgot.GovernanceObjectCachedEndorsed CachedEndorsed, "
        ." cgot.FirstReported FirstReported, "
        ." cgot.LastReported LastReported, "
        ." cgotp.GovernanceObjectPaymentPosition PaymentPosition, "
        ." cgotp.GovernanceObjectPaymentAddress PaymentAddress, "
        ." cgotp.GovernanceObjectPaymentAmount PaymentAmount, "
        ." cgotp.GovernanceObjectPaymentProposalHash PaymentProposalHash, "
        ." cgop.GovernanceObjectName PaymentProposalName "
        ."FROM cmd_gobject_triggers cgot "
        ."LEFT JOIN cmd_gobject_triggers_payments cgotp ON (cgotp.GovernanceObjectTestnet = cgot.GovernanceObjectTestnet AND cgotp.GovernanceObjectId = cgot.GovernanceObjectId) "
        ."LEFT JOIN cmd_gobject_proposals cgop ON (cgop.GovernanceObjectTestnet = cgot.GovernanceObjectTestnet AND cgotp.GovernanceObjectPaymentProposalHash = cgop.GovernanceObjectId) "
        ."WHERE "
        ." cgot.GovernanceObjectTestnet = %d AND cgot.GovernanceObjectVotesAbsoluteYes > 0"
        ." AND cgot.GovernanceObjectCachedValid = 1"
        ." AND cgot.GovernanceObjectEventBlockHeight >= %d"
    ,$testnet,$currentblock["BlockId"]);

    if ($result = $mysqli->query($sql)) {
        $triggersvalid = 0;
        $triggers = array();
        while($row = $result->fetch_array()){
            $triggers[] = array(
                "Hash" => stripslashes($row["Hash"]),
                "BlockHeight" => stripslashes($row["BlockHeight"]),
                "AbsoluteYes" => intval($row["AbsoluteYes"]),
                "Yes" => intval($row["Yes"]),
                "No" => intval($row["No"]),
                "Abstain" => intval($row["Abstain"]),
                "BlockchainValidity" => ($row["BlockchainValidity"] == 1),
                "IsValidReason" => stripslashes($row["IsValidReason"]),
                "CachedValid" => ($row["CachedValid"] == 1),
                "CachedFunding" => ($row["CachedFunding"] == 1),
                "CachedDelete" => ($row["CachedDelete"] == 1),
                "CachedEndorsed" => ($row["CachedEndorsed"] == 1),
                "FirstReported" => strtotime($row["FirstReported"]),
                "LastReported" => strtotime($row["LastReported"]),
                "PaymentPosition" => intval($row["PaymentPosition"]),
                "PaymentAddress" => intval($row["PaymentAddress"]),
                "PaymentAmount" => intval($row["PaymentAmount"]),
                "PaymentProposalHash" => $row["PaymentProposalHash"],
                "PaymentProposalName" => $row["PaymentProposalName"],
            );
            $triggersvalid+=intval($row["BlockchainValidity"]);
        }

    } else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_GOTR_SEMAPHORE);
    }
    echo "OK (".count($triggers)." triggers)\n";

    $data = array('status' => 'OK',
        'data' => array('governancetriggers' => $triggers,
        'stats' => array(
            'valid' => $triggersvalid,
        ),
        'cache' => array(
            'time' => time(),
            'fromcache' => true
        ),
        'api' => array(
            'version' => 1,
            'compat' => 1,
            'bev' => 'gt='.DASHNINJA_BEV.".".DASHNINJA_CRONVERSION
        )
        ));

    save_json("governancetriggers",$data,DMN_CRON_GOTR_SEMAPHORE,$testnet);

}

function generate_blockssuperblocks_json_files($mysqli, $testnet = 0) {

    xecho("Generating superblocks list status:\n");
    semaphore(DMN_CRON_BKSB_SEMAPHORE);

    xecho("--> Retrieve superblocks: ");

    $sql = sprintf("SELECT cib.BlockId BlockId, cib.BlockHash BlockHash, cib.BlockPoolPubKey BlockPoolPubKey, cpp.PoolDescription PoolDescription, cib.BlockMNPayee SuperblockV1PaymentAddress, "
        ."cib.BlockTime BlockTime, cib.BlockDifficulty BlockDifficulty, cib.IsSuperblock SuperblockVersion, cib.BlockMNValue TotalAmount, cib.SuperBlockBudgetName SuperblockV1BudgetName, "
        ."cgop.GovernanceObjectName SuperblockV2ProposalName, cibs.GovernanceObjectPaymentProposalHash SuperblockV2ProposalHash, cibs.GovernanceObjectPaymentAmount SuperblockV2ProposalPaymentAmount, "
        ."cibs.GovernanceObjectPaymentAddress SuperblockV2PaymentAddress FROM cmd_info_blocks cib "
        ."LEFT JOIN cmd_pools_pubkey cpp ON cib.BlockPoolPubKey = cpp.PoolPubKey AND cib.BlockTestNet = cpp.PoolTestNet "
        ."LEFT JOIN cmd_info_blocks_superblockpayments cibs ON cib.BlockTestNet = cibs.BlockTestNet AND cib.BlockId = cibs.BlockId "
        ."LEFT JOIN cmd_gobject_proposals cgop ON cibs.GovernanceObjectPaymentProposalHash = cgop.GovernanceObjectId AND cibs.BlockTestNet = cgop.GovernanceObjectTestNet "
        ."WHERE cib.BlockTestNet = %d AND cib.IsSuperblock > 0 ORDER BY BlockId DESC",$testnet);
    $superblocks = array();
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            if ($row["SuperblockVersion"] == 1) {
                $amount = floatval($row["TotalAmount"]);
                $name = $row["SuperblockV1BudgetName"];
                $address = $row["SuperblockV1PaymentAddress"];
            } else {
                $amount = floatval($row["SuperblockV2ProposalPaymentAmount"]);
                $name = $row["SuperblockV2ProposalName"];
                $address = $row["SuperblockV2PaymentAddress"];
            }
            $superblocks[] = array(
                "BlockId" => intval($row["BlockId"]),
                "BlockHash" => $row["BlockHash"],
                "BlockPoolPubKey" => $row["BlockPoolPubKey"],
                "PoolDescription" => $row["PoolDescription"],
                "BlockTime" => intval($row["BlockTime"]),
                "SuperBlockVersion" => intval($row["SuperblockVersion"]),
                "SuperBlockProposalName" => $name,
                "SuperBlockProposalHash" => $row["SuperblockV2ProposalHash"],
                "SuperBlockPaymentAmount" => $amount,
                "SuperBlockPaymentAddress" => $address
            );
        }
    }
    else {
        echo "SQL error - ".$mysqli->errno.": ".$mysqli->error."\n";
        die2(301,DMN_CRON_BKSB_SEMAPHORE);
    }
    echo "OK (".count($superblocks).")\n";

    $data = array("status" => 'OK',
        'data' => array('superblocks' => $superblocks,
            'cache' => array(
                'time' => time(),
                'fromcache' => true
            ),
            'api' => array(
                'version' => 1,
                'compat' => 1,
                'bev' => 'sb='.DASHNINJA_BEV.".".DASHNINJA_CRONVERSION
            )
        ));

    save_json("blockssuperblocks",$data,DMN_CRON_BKSB_SEMAPHORE,$testnet);

}

xecho('DASH Ninja Front-End JSON Generator cron v'.DASHNINJA_BEV.'.'.DASHNINJA_CRONVERSION."\n");

if ($argc != 3) {
    xecho("Usage: ".$argv[0]." main|testnet <command>\n");
    xecho("Command can be: masternodeslistfull = Generate the full masternodes list in data folder\n");
    xecho("                protxfull = Generate the full deterministic masternodes list in data folder\n");
    xecho("                blocks24h = Generate the full last 24h blocks list in data folder\n");
    xecho("                nodesstatus = Generate monitoring nodes status in data folder\n");
    xecho("                blocksconsensus = Generate block consensus in data folder\n");
    xecho("                votelimit = Generate governance vote limit in data folder\n");
    xecho("                governanceproposals = Generate governance proposals in data folder\n");
    xecho("                governancetriggers = Generate governance superblock triggers in data folder\n");
    xecho("                blockssuperblocks = Generate superblocks list in data folder\n");
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
elseif ($argv[2] == "protxfull") {
    generate_protxfull_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "blocks24h") {
    generate_blocks24h_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "nodesstatus") {
    generate_nodesstatus_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "blocksconsensus") {
    generate_blocksconsensus_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "votelimit") {
    generate_governancevotelimit_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "governanceproposals") {
    generate_governanceproposals_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "governancetriggers") {
    generate_governancetriggers_json_files($mysqli, $testnet);
}
elseif ($argv[2] == "blockssuperblocks") {
    generate_blockssuperblocks_json_files($mysqli, $testnet);
}
else {
    xecho("Unknown command ".$argv[2]."\n");
    die(11);
}

?>