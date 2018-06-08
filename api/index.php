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

// Load configuration and connect to DB
require_once('libs/db.inc.php');

// Get common queries functions
require_once('libs/common-queries.inc.php');

function getipport($addr) {
  $portpos = strrpos($addr,":");
  $ip = substr($addr,0,$portpos);
  $port = substr($addr,$portpos+1,strlen($addr)-$portpos-1);
  return array($ip,$port);
}

// Create and bind the DI to the application
$app = new \Phalcon\Mvc\Micro();
$router = $app->getRouter();
$router->setUriSource(\Phalcon\Mvc\Router::URI_SOURCE_SERVER_REQUEST_URI);

// *******************************************************
// Non-Auth required
// *******************************************************

// Get API version
$app->get('/api/version', function() {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  //Change the HTTP status
  $response->setStatusCode(200, "OK");
  $response->setJsonContent(array('status' => 'OK', 'data' => array("version" => array(
      "api" => DASHNINJA_BEV,
      "phalcon" => Phalcon\Version::get(),
      "php" => phpversion()
  ))));

  return $response;

});

// Get blocks detail + stats
// Parameters:
//   testnet=0|1
//   interval=interval (optional, default is P1D for 1 day)
//   pubkeys=filter to chose pubkeys
//   onlysuperblocks=0|1 (default to 0)
//   budgetids=filter to chose budget names
$app->get('/api/blocks', function() use ($app,&$mysqli) {

  $apiversion = 3;
  $apiversioncompat = 1;

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  // Retrieve the 'nodetail' parameter
  if ($request->hasQuery('nodetail')) {
    $nodetail = (intval($request->getQuery('nodetail')) == 1);
    $cachenodetail = 1;
  }
  else {
    $nodetail = false;
    $cachenodetail = 0;
  }

  // Retrieve the 'onlysuperblocks' parameter
  if ($request->hasQuery('onlysuperblocks')) {
    $onlysuperblocks = intval($request->getQuery('onlysuperblocks'));
    if (($onlysuperblocks != 0) && ($onlysuperblocks != 1)) {
      $onlysuperblocks = 0;
    }
  }
  else {
    $onlysuperblocks = 0;
  }

  // Retrieve the 'interval' parameter
  if ($request->hasQuery('interval')) {
    try {
      $interval = new DateInterval($request->getQuery('interval'));
      $cacheinterval = $request->getQuery('interval');
      $cachetime = 900;
    } catch (Exception $e) {
      $errmsg[] = 'Wrong interval parameter';
      $interval = new DateInterval('PT1S');
      $cacheinterval = "PT1S";
      $cachetime = 150;
    }
  }
  else {
    $interval = new DateInterval('PT1S');
    $cacheinterval = "PT1S";
    $cachetime = 150;
  }
  if ($onlysuperblocks == 1) {
    $cacheinterval = "NONE";
  }
  $interval->invert = 1;
  $datefrom = new DateTime();
  $datefrom->add( $interval );
  $datefrom = $datefrom->getTimestamp();

  // Retrieve the 'pubkeys' parameter
  if ($request->hasQuery('pubkeys')) {
    $mnpubkeys = json_decode($request->getQuery('pubkeys'));
    if (($mnpubkeys === false) || !is_array($mnpubkeys)) {
      $errmsg[] = "Parameter pubkeys: Not a JSON encoded list of pubkeys";
    }
    else {
      foreach ($mnpubkeys as $mnpubkey) {
        if ( ( ($testnet == 1) && ! ( (substr($mnpubkey,0,1) == 'x') || (substr($mnpubkey,0,1) == 'y') ) )
          || ( ($testnet == 0) && ! ( (substr($mnpubkey,0,1) == 'X') || (substr($mnpubkey,0,1) == '7') ) )
          || ( strlen($mnpubkey) != 34 ) ) {
          $errmsg[] = "Parameter pubkeys: Entry $mnpubkey: Incorrect pubkey format.";
        }
      }
    }
  }
  else {
    $mnpubkeys = array();
  }

  // Retrieve the 'budgetids' parameter
  if ($request->hasQuery('budgetids')) {
    $budgetids = json_decode($request->getQuery('budgetids'));
    if (($budgetids === false) || !is_array($budgetids)) {
      $errmsg[] = "Parameter budgetids: Not a JSON encoded list of budget names";
    }
    else {
      foreach ($budgetids as $x => $budgetid) {
        $budgetids[$x] = $mysqli->real_escape_string($budgetid);
      }
    }
  }
  else {
    $budgetids = array();
  }

  $finalcount = count($mnpubkeys)+count($budgetids);
  if ($finalcount == 0) {
      $errmsg[] = "To use this API you must select at least 1 pubkey or budgetname. If you need a full 24h blocks list, try: /data/blocks24h-".$testnet.".json";
  }
  elseif ($finalcount > 50) {
      $errmsg[] = "To use this API you must select at most 50 pubkeys and/or budgetnames. If you need a full 24h blocks list, try: /data/blocks24h-".$testnet.".json";
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cacheserial = sha1(serialize($mnpubkeys).serialize($budgetids));
    $cachefnam = CACHEFOLDER.sprintf("dashninja_blocks_%d_%d_%s_%d_%d_%d_%s",$testnet,$cachenodetail,$cacheinterval,count($mnpubkeys),$onlysuperblocks,count($budgetids),$cacheserial);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+$cachetime)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $data["cache"]["fromcache"] = true;
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);
      $sql = "SELECT Protocol, ProtocolDescription FROM cmd_info_protocol_description";
      $protocols = array();
      if ($result = $mysqli->query($sql)) {
        while($row = $result->fetch_assoc()){
          $protocols[$row['Protocol']] = $row['ProtocolDescription'];
        }
      }
      else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
      }

      // Retrieve all blocks for last 24h
      $sqlpk = "";
      // Add selection by pubkey
      if (count($mnpubkeys) > 0) {
        $sqlpk = " AND (";
        $sqls = '';
        foreach($mnpubkeys as $mnpubkey) {
          if (strlen($sqls)>0) {
            $sqls .= ' OR ';
          }
          $mnpubkeyesc = $mysqli->real_escape_string($mnpubkey);
          $sqls .= sprintf("cib.BlockMNPayee = '%s' OR cib.BlockMNPayeeExpected = '%s'",$mnpubkeyesc,$mnpubkeyesc);
        }
        $sqlpk .= $sqls.")";
      }

      $sqldb = "";
      // Add selection by budgetname
      if (count($budgetids) > 0) {
        $sqldb = " AND (";
        $sqls = '';
        foreach($budgetids as $budgetid) {
          if (strlen($sqls)>0) {
            $sqls .= ' OR ';
          }
          $sqls .= sprintf("cib.SuperBlockBudgetName = '%s'",$budgetid);
        }
        $sqldb .= $sqls.")";
      }

      if ($onlysuperblocks == 1) {
        $extrasql = " AND cib.IsSuperBlock > 0".$sqldb;
      }
      else {
        $extrasql = sprintf(" AND cib.BlockTime >= %d", $datefrom);
        $extrasql.=$sqlpk;
      }

      $sql = sprintf("SELECT BlockId, BlockHash, cib.BlockMNPayee BlockMNPayee, BlockMNPayeeDonation, BlockMNValue, BlockSupplyValue, BlockMNPayed, BlockPoolPubKey, PoolDescription, BlockMNProtocol, BlockTime, BlockDifficulty, BlockMNPayeeExpected, BlockMNValueRatioExpected, IsSuperblock, SuperBlockBudgetName, BlockDarkSendTXCount, MemPoolDarkSendTXCount, SuperBlockBudgetPayees, SuperBlockBudgetAmount, BlockVersion FROM cmd_info_blocks cib LEFT JOIN cmd_pools_pubkey cpp ON cib.BlockPoolPubKey = cpp.PoolPubKey AND cib.BlockTestNet = cpp.PoolTestNet WHERE cib.BlockTestNet = %d%s ORDER BY BlockId DESC",$testnet,$extrasql);
      $blocks = array();
      $maxprotocol = 0;
      $blockidlow = 9999999999;
      $blockidhigh = 0;
      $sqlwheretemplate = "BlockHeight = %d";
      $sqlblockids = array();
      if ($result = $mysqli->query($sql)) {
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
  
        $blocksnew = array();
        foreach($blocks as $block) {
          $blocksnew[] = $block;
        }
        $blocks = $blocksnew;
        unset($blocksnew);
  
        $totalmninfo = 0;
        $uniquemnips = 0;
        $mninfo = dmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
        if ($mninfo === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error,$totalmninfo)));
          return $response;
        }

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

        if ($nodetail) {
          $blocks = array();
          $perminer = array();
        }

        $data = array('blocks' => $blocks,
                                                                          'stats' => array('perversion' => $perversion,
                                                                                           'perminer' => $perminer,
                                                                                           'global' => $globalstats
                                                                                          ),
            'cache' => array(
                'time' => time(),
                'fromcache' => false
            ),
            'api' => array(
                'version' => $apiversion,
                'compat' => $apiversioncompat,
                'bev' => 'bk='.DASHNINJA_BEV.".".$apiversion
            )
                                                                         );
        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        file_put_contents($cachefnam,serialize($data),LOCK_EX);
        unlink($cachefnamupdate);
      }
      else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
      }
    }
  }
  return $response;

});

// Get blocks consensus
// Parameters:
//   testnet=0|1
$app->get('/api/blocks/consensus', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => array('Payload (or CONTENT_LENGTH) is missing')));
  }
  else {
    // Retrieve all known nodes for current hub
    $sql = sprintf("SELECT BlockHeight, BlockMNPayee, BlockMNRatio, Protocol, NodeName FROM `cmd_info_blocks_history2` cibh, cmd_nodes cn WHERE cibh.NodeID = cn.NodeID AND cibh.BlockTestNet = %d ORDER BY BlockHeight DESC LIMIT 160",$testnet);
    $numblocks = 0;
    $curblock = -1;
    $bhinfo = array();
    if ($result = $mysqli->query($sql)) {
      while(($row = $result->fetch_assoc()) && ($numblocks < 11)){
        if ($row['BlockHeight'] != $curblock) {
          $curblock = $row['BlockHeight'];
          $numblocks++;
        }
        if ($numblocks < 11) {
          if (!array_key_exists($row['BlockHeight'],$bhinfo)) {
            $bhinfo[$row['BlockHeight']] = array();
          }
          if (!array_key_exists($row['Protocol'],$bhinfo[$row['BlockHeight']])) {
            $bhinfo[$row['BlockHeight']][$row['Protocol']] = array();
          }
          if (!array_key_exists($row['BlockMNPayee'],$bhinfo[$row['BlockHeight']][$row['Protocol']])) {
            $bhinfo[$row['BlockHeight']][$row['Protocol']][$row['BlockMNPayee']] = array('count' => 0,
                                                                                         'names' => array());
          }
          $bhinfo[$row['BlockHeight']][$row['Protocol']][$row['BlockMNPayee']]['count']++;
          $bhinfo[$row['BlockHeight']][$row['Protocol']][$row['BlockMNPayee']]['names'][] = $row['NodeName'];
        }
      }

      foreach($bhinfo as $bhid => $bhdata) {
        $maxprotocol[$bhid] = 0;
        foreach($bhdata as $protocol => $bhpayee) {
          if ($protocol > $maxprotocol[$bhid]) {
            $maxprotocol[$bhid] = $protocol;
          }
        }
      }

      $bhinfofinal = array();
      foreach($maxprotocol as $bhid => $protocol) {
        $totalnodes = 0;
        foreach($bhinfo[$bhid][$protocol] as $pubkey => $cinfo) {
          $totalnodes += $cinfo['count'];
        }
        $maxconsensus = 0;
        foreach($bhinfo[$bhid][$protocol] as $pubkey => $cinfo) {
          $bhinfo[$bhid][$protocol][$pubkey]['consensus'] = $cinfo['count']/$totalnodes;
          if ($bhinfo[$bhid][$protocol][$pubkey]['consensus'] > $maxconsensus) {
            $maxconsensus = $bhinfo[$bhid][$protocol][$pubkey]['consensus'];
          }
        }
        $maxconsensusfound = false;
        $maxconsensuspubkey = '';
        $otherconsensus = array();
        foreach($bhinfo[$bhid][$protocol] as $pubkey => $cinfo) {
          if (($cinfo['consensus'] == $maxconsensus) && !$maxconsensusfound) {
            $maxconsensusfound = true;
            $maxconsensuspubkey = $pubkey;
          }
          else {
            sort($cinfo['names']);
            $otherconsensus[] = array('Payee' => $pubkey,
                                      'RatioVotes' => $cinfo['count']/$totalnodes,
                                      'NodeNames' => $cinfo['names']);
          }
        }
        $bhinfofinal[] = array('BlockID' => $bhid,
                               'Consensus' => $maxconsensus,
                               'ConsensusPubKey' => $maxconsensuspubkey,
                               'Others' => $otherconsensus);
      }

      //Change the HTTP status
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $bhinfofinal));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
    }
  }
  return $response;

});

// Get super blocks payments details
// Parameters:
//   testnet=0|1
//   proposalshash=filter to chose only some proposals
$app->get('/api/blocks/superblocks', function() use ($app,&$mysqli) {

    $apiversion = 1;
    $apiversioncompat = 1;

    //Create a response
    $response = new Phalcon\Http\Response();
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader("Content-Type", "application/json");

    $request = $app->request;

    $errmsg = array();

    $cachetime = 150;

    if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
        $errmsg[] = "No CONTENT expected";
    }

    // Retrieve the 'testnet' parameter
    if ($request->hasQuery('testnet')) {
        $testnet = intval($request->getQuery('testnet'));
        if (($testnet != 0) && ($testnet != 1)) {
            $testnet = 0;
        }
    }
    else {
        $testnet = 0;
    }

    // Retrieve the 'proposalshash' parameter
    if ($request->hasQuery('proposalshash')) {
        $proposalshash = json_decode($request->getQuery('proposalshash'));
        if (($proposalshash === false) || !is_array($proposalshash)) {
            $errmsg[] = "Parameter proposalshash: Not a JSON encoded list of proposal names";
        }
        else {
            foreach ($proposalshash as $x => $proposalhash) {
                $proposalshash[$x] = $mysqli->real_escape_string($proposalhash);
            }
        }
    }
    else {
        $proposalshash = array();
    }

    if (count($errmsg) > 0) {
        //Change the HTTP status
        $response->setStatusCode(400, "Bad Request");

        //Send errors to the client
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
    }
    else {
        $cacheserial = sha1(serialize($proposalshash));
        $cachefnam = CACHEFOLDER.sprintf("dashninja_blocks_superblockspayments_%d_%d_%s",$testnet,count($proposalshash),$cacheserial);
        $cachefnamupdate = $cachefnam.".update";
        $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+$cachetime)>=time()) || file_exists($cachefnamupdate)));
        if ($cachevalid) {
            $data = unserialize(file_get_contents($cachefnam));
            $data["cache"]["fromcache"] = true;
            $response->setStatusCode(200, "OK");
            $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        }
        else {
            touch($cachefnamupdate);

            $sqldb = "";
            // Add selection by budgetname
            if (count($proposalshash) > 0) {
                $sqldb = " AND (";
                $sqls = '';
                foreach($proposalshash as $hash) {
                    if (strlen($sqls)>0) {
                        $sqls .= ' OR ';
                    }
                    $sqls .= sprintf("cibs.GovernanceObjectPaymentProposalHash = '%s'",$hash);
                }
                $sqldb .= $sqls.")";
            }

            $sql = sprintf("SELECT cib.BlockId BlockId, cib.BlockHash BlockHash, cib.BlockPoolPubKey BlockPoolPubKey, cpp.PoolDescription PoolDescription, cib.BlockMNPayee SuperblockV1PaymentAddress, "
                ."cib.BlockTime BlockTime, cib.BlockDifficulty BlockDifficulty, cib.IsSuperblock SuperblockVersion, cib.BlockMNValue TotalAmount, cib.SuperBlockBudgetName SuperblockV1BudgetName, "
                ."cgop.GovernanceObjectName SuperblockV2ProposalName, cibs.GovernanceObjectPaymentProposalHash SuperblockV2ProposalHash, cibs.GovernanceObjectPaymentAmount SuperblockV2ProposalPaymentAmount, "
                ."cibs.GovernanceObjectPaymentAddress SuperblockV2PaymentAddress FROM cmd_info_blocks cib "
                ."LEFT JOIN cmd_pools_pubkey cpp ON cib.BlockPoolPubKey = cpp.PoolPubKey AND cib.BlockTestNet = cpp.PoolTestNet "
                ."LEFT JOIN cmd_info_blocks_superblockpayments cibs ON cib.BlockTestNet = cibs.BlockTestNet AND cib.BlockId = cibs.BlockId "
                ."LEFT JOIN cmd_gobject_proposals cgop ON cibs.GovernanceObjectPaymentProposalHash = cgop.GovernanceObjectId AND cibs.BlockTestNet = cgop.GovernanceObjectTestNet "
                ."WHERE cib.BlockTestNet = %d AND cib.IsSuperblock > 0%s ORDER BY BlockId DESC",$testnet,$sqldb);
            $superblocks = array();
            if ($result = $mysqli->query($sql)) {
                while($row = $result->fetch_assoc()){
                    if ($row["SuperblockVersion"] == 1) {
                      $amount = floatval($row["TotalAmount"]);
                      $name = $row["SuperblockV1BudgetName"];
                      $address = $row["SuperblockV1PaymentAddress"];
                    }
                    else {
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

                $data = array('superblocks' => $superblocks,
                    'cache' => array(
                        'time' => time(),
                        'fromcache' => false
                    ),
                    'api' => array(
                        'version' => $apiversion,
                        'compat' => $apiversioncompat,
                        'bev' => 'sb='.DASHNINJA_BEV.".".$apiversion
                    )
                );
                //Change the HTTP status
                $response->setStatusCode(200, "OK");
                $response->setJsonContent(array('status' => 'OK', 'data' => $data));
                file_put_contents($cachefnam,serialize($data),LOCK_EX);
                unlink($cachefnamupdate);
            }
            else {
                $response->setStatusCode(503, "Service Unavailable");
                $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
            }
        }
    }
    return $response;

});

// Get budgets
// Parameters:
//   testnet=0|1
//   onlyvalid=0|1
//   budgethashes=[json array of hashes]
//   budgetids=[json array of hashes]
$app->get('/api/budgets', function() use ($app,&$mysqli) {

  $apiversion = 1;
  $apiversioncompat = 1;

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  // Retrieve the 'onlyvalid' parameter
  if ($request->hasQuery('onlyvalid')) {
    $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
  }
  else {
    $onlyvalid = false;
  }

  // Retrieve the 'budgetids' parameter
  if ($request->hasQuery('budgetids')) {
    $budgetids = json_decode($request->getQuery('budgetids'));
    if (($budgetids === false) || !is_array($budgetids)) {
      $errmsg[] = "Parameter budgetids: Not a JSON encoded list of budgets ids";
    }
    else {
      foreach ($budgetids as $x => $budgetid) {
        $budgetids[$x] = $mysqli->real_escape_string($budgetid);
      }
    }
  }
  else {
    $budgetids = array();
  }

  // Retrieve the 'budgethashes' parameter
  if ($request->hasQuery('budgethashes')) {
    $budgethashes = json_decode($request->getQuery('budgethashes'));
    if (($budgethashes === false) || !is_array($budgethashes)) {
      $errmsg[] = "Parameter budgethashes: Not a JSON encoded list of budget hashes";
    }
    else {
      foreach ($budgethashes as $budgethash) {
        if ( strlen($budgethash) != 64 ) {
          $errmsg[] = "Parameter budgethashes: Entry $budgethash: Incorrect hash format.";
        }
      }
    }
  }
  else {
    $budgethashes = array();
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cacheserial = sha1(serialize($budgetids).serialize($budgethashes));
    $cachefnam = CACHEFOLDER.sprintf("dashninja_budgets_%d_%d_%d_%d_%s",$testnet,$onlyvalid,count($budgetids),count($budgethashes),$cacheserial);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $data["cache"]["fromcache"] = true;
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);

      // Add selection by budget hashes
      $sqlbh = "";
      if (count($budgethashes) > 0) {
        $sqls = '';
        foreach($budgethashes as $budgethash) {
          if (strlen($sqls)>0) {
            $sqls .= ' OR ';
          }
          $sqls .= sprintf("BudgetHash = '%s'",$budgethash);
        }
        $sqlbh = " AND (".$sqls.")";
      }

      // Add selection by budget ids
      $sqlbi = "";
      if (count($budgetids) > 0) {
        $sqls = '';
        foreach($budgetids as $budgetid) {
          if (strlen($sqls)>0) {
            $sqls .= ' OR ';
          }
          $sqls .= sprintf("BudgetId = '%s'",$budgetid);
        }
        $sqlbi = " AND (".$sqls.")";
      }

      // Get budgets
      $sql = sprintf("SELECT * FROM cmd_budget WHERE BudgetTestnet = %d%s%s",$testnet,$sqlbh,$sqlbi);
      if ($onlyvalid) {
        $sql .= " AND IsValid = 1";
      }

      if ($result = $mysqli->query($sql)) {
        $budgetvalid = 0;
        $budgetestablished = 0;
        $budgets = array();
        while($row = $result->fetch_assoc()){
          $budgets[] = array(
              "ID" => stripslashes($row["BudgetId"]),
              "Hash" => stripslashes($row["BudgetHash"]),
              "FeeHash" => stripslashes($row["FeeHash"]),
              "URL" => stripslashes($row["BudgetURL"]),
              "BlockStart" => intval($row["BlockStart"]),
              "BlockEnd" => intval($row["BlockEnd"]),
              "TotalPaymentCount" => intval($row["TotalPaymentCount"]),
              "RemainingPaymentCount" => intval($row["RemainingPaymentCount"]),
              "PaymentAddress" => stripslashes($row["PaymentAddress"]),
              "Ratio" => floatval($row["Ratio"]),
              "Yeas" => intval($row["Yeas"]),
              "Nays" => intval($row["Nays"]),
              "Abstains" => intval($row["Abstains"]),
              "TotalPayment" => floatval($row["TotalPayment"]),
              "MonthlyPayment" => floatval($row["MonthlyPayment"]),
              "IsEstablished" => ($row["IsEstablished"] == 1),
              "IsValid" => ($row["IsValid"] == 1),
              "IsValidReason" => stripslashes($row["IsValidReason"]),
              "FirstReported" => strtotime($row["FirstReported"]),
              "LastReported" => strtotime($row["LastReported"])
                           );
          $budgetvalid+=intval($row["IsValid"]);
          $budgetestablished+=intval($row["IsEstablished"]);
        }

        $totalmninfo = 0;
        $uniquemnips = 0;
        $mninfo = dmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
        if ($mninfo === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error,$totalmninfo)));
          return $response;
        }

        $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1",$testnet);
        if ($result = $mysqli->query($sql)) {
          $currentblock = $result->fetch_assoc();
          $currentblock["BlockId"] = intval($currentblock["BlockId"]);
          $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
          $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
        }
        else {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
          return $response;
        }

        $nSubsidy = 5;
        if ($testnet == 0){
          $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
          for($i = 210240; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
          $estimatedbudgetamount = (($nSubsidy/100)*10)*576*30;
        } else {
          $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 50) + 50 ;
          for($i = 46200; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
          $estimatedbudgetamount = (($nSubsidy/100)*10)*50;
        }

        $data = array('budgets' => $budgets,
                      'stats' => array(
                                   'budgetvalid' => $budgetvalid,
                                   'budgetestablished' => $budgetestablished,
                                   'totalmns' => intval($totalmninfo),
                                   'nextsuperblock' => array(
                                                             "blockheight" => $nextsuperblock,
                                                             "estimatedbudgetamount" => $estimatedbudgetamount
                                                            ),
                                   'latestblock' => $currentblock
                                      ),
            'cache' => array(
                'time' => time(),
                'fromcache' => false
            ),
            'api' => array(
                'version' => $apiversion,
                'compat' => $apiversioncompat,
                'bev' => 'bu='.DASHNINJA_BEV.".".$apiversion
            )
                     );

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        file_put_contents($cachefnam,serialize($data),LOCK_EX);
        unlink($cachefnamupdate);
      }
      else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
      }
    }
  }
  return $response;

});

$app->get('/api/budgetsexpected', function() use ($app,&$mysqli) {

  $apiversion = 1;
  $apiversioncompat = 1;

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cachefnam = CACHEFOLDER . sprintf("dashninja_budgets_final_%d", $testnet);
    $cachefnamupdate = $cachefnam . ".update";
    $cachetime = filemtime($cachefnam);
    $cachevalid = (is_readable($cachefnam) && ((($cachetime + 120) >= time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $data["cache"]["fromcache"] = true;
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    } else {
      touch($cachefnamupdate);

      // Retrieve all known final budgets
      $sql = sprintf('SELECT BlockStart, BlockEnd, Proposals FROM cmd_budget_final WHERE VoteCount > 0 AND IsValid = 1 AND Status = "OK" AND BudgetTestnet = %d AND BlockStart > (SELECT max(BlockId) FROM cmd_info_blocks WHERE BlockTestnet = %d)', $testnet, $testnet);
      $mnbudgets = array();
      $proposalsfinal = array();
      if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
          $pos = 0;
          $proposals = explode(",", $row['Proposals']);
          for ($x = intval($row['BlockStart']); $x <= intval($row['BlockEnd']); $x++) {
            $mnbudgets[$x] = array(
                "BlockId" => $x,
                "BlockProposal" => $proposals[$pos]
            );
            $proposalsfinal[] = $proposals[$pos];
            $pos++;
          }
        }

        $proposalsvalues = array();
        $sql = sprintf("SELECT BudgetId, MonthlyPayment, PaymentAddress FROM cmd_budget_projection WHERE BudgetTestnet = %d", $testnet);
        if ($result = $mysqli->query($sql)) {
          $test = array();
          while ($row = $result->fetch_assoc()) {
            $test[] = $row;
            if (in_array($row['BudgetId'], $proposalsfinal)) {
              $proposalsvalues[$row['BudgetId']] = $row;
            }
          }

          $finaldata = array();
          foreach ($mnbudgets as $mnbudgetdataid => $mnbudgetdatadata) {
            if (array_key_exists($mnbudgetdatadata["BlockProposal"], $proposalsvalues)) {
              $mnbudgets[$mnbudgetdataid]["MonthlyPayment"] = floatval($proposalsvalues[$mnbudgetdatadata["BlockProposal"]]["MonthlyPayment"]);
              $mnbudgets[$mnbudgetdataid]["PaymentAddress"] = $proposalsvalues[$mnbudgetdatadata["BlockProposal"]]["PaymentAddress"];
            } else {
              $mnbudgets[$mnbudgetdataid]["MonthlyPayment"] = 0.0;
              $mnbudgets[$mnbudgetdataid]["PaymentAddress"] = "";
            };
            $finaldata[] = $mnbudgets[$mnbudgetdataid];
          }

          $data = array(
              'budgetsexpected' => $finaldata,
              'cache' => array(
                  'time' => time(),
                  'fromcache' => false
              ),
              'api' => array(
                  'version' => $apiversion,
                  'compat' => $apiversioncompat,
                  'bev' => 'be=' . DASHNINJA_BEV . "." . $apiversion
              )
          );

          //Change the HTTP status
          $response->setStatusCode(200, "OK");
          $response->setJsonContent(array('status' => 'OK', 'data' => $data));
          file_put_contents($cachefnam, serialize($data), LOCK_EX);
        } else {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
        }
      } else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
      }
      unlink($cachefnamupdate);
    }
  }

  return $response;

});

// Get budget votes
// Parameters:
//   testnet=0|1
//   onlyvalid=0|1
//   budgetid=name of the budget
$app->get('/api/budgets/votes', function() use ($app,&$mysqli) {

  $apiversion = 1;
  $apiversioncompat = 1;

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  // Retrieve the 'onlyvalid' parameter
  if ($request->hasQuery('onlyvalid')) {
    $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
  }
  else {
    $onlyvalid = false;
  }

  // Retrieve the 'debug' parameter
  if ($request->hasQuery('debug')) {
    $debug = (intval($request->getQuery('debug')) == 1);
  }
  else {
    $debug = false;
  }

  // Retrieve the 'budgetid' parameter
  if ($request->hasQuery('budgetid')) {
    $budgetid = $request->getQuery('budgetid');
    $budgetid = $mysqli->real_escape_string($budgetid);
  }
  else {
    $errmsg[] = "Parameter budgetid is mandatory";
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cacheserial = sha1(serialize($budgetid));
    $cachefnam = CACHEFOLDER.sprintf("dashninja_budgets_votes_%d_%d_%s",$testnet,$onlyvalid,$cacheserial);
    $cachefnamupdate = $cachefnam.".update";
    $cachetime = filemtime($cachefnam);
    $cachevalid = (is_readable($cachefnam) && ((($cachetime+120)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $data["cache"]["fromcache"] = true;
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);

      // Get budget votes
      $sql = sprintf("SELECT * FROM cmd_budget_votes WHERE BudgetTestnet = %d AND BudgetId = '%s'",$testnet,$budgetid);
      if ($onlyvalid) {
        $sql .= " AND VoteIsValid = 1";
      }

      if ($result = $mysqli->query($sql)) {
        $votesvalid = 0;
        $votesyes = 0;
        $votesno = 0;
        $votesabstain = 0;
        $budgetsvotes = array();
        while($row = $result->fetch_assoc()){
          $budgetsvotes[] = array(
              "ID" => $row["BudgetId"],
              "MasternodeOutputHash" => $row["MasternodeOutputHash"],
              "MasternodeOutputIndex" => intval($row["MasternodeOutputIndex"]),
              "VoteHash" => $row["VoteHash"],
              "VoteValue" => $row["VoteValue"],
              "VoteTime" => intval($row["VoteTime"]),
              "VoteIsValid" => ($row["VoteIsValid"] == 1)
          );
          if ($row["VoteValue"] == "YES") {
            $votesyes++;
          }
          elseif ($row["VoteValue"] == "NO") {
            $votesno++;
          }
          elseif ($row["VoteValue"] == "ABSTAIN") {
            $votesabstain++;
          }
          $votesvalid+=intval($row["VoteIsValid"]);
        }

        $data = array('budgetsvotes' => $budgetsvotes,
            'stats' => array(
                'votesvalid' => $votesvalid,
                'votesyes' => $votesyes,
                'votesno' => $votesno,
                'votesabstain' => $votesabstain,
            ),
            'cache' => array(
                'time' => time(),
                'fromcache' => false
            ),
            'api' => array(
                'version' => $apiversion,
                'compat' => $apiversioncompat,
                'bev' => 'bv='.DASHNINJA_BEV.".".$apiversion
            )
        );

        if ($debug) {
          $data["debug"] = array("sql" => $sql);
        }

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        file_put_contents($cachefnam,serialize($data),LOCK_EX);
        unlink($cachefnamupdate);
      }
      else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
      }
    }
  }
  return $response;

});

// Get budgets projection (next super-block)
// Parameters:
//   testnet=0|1
//   onlyvalid=0|1
//   budgethashes=[json array of hashes]
//   budgetids=[json array of hashes]
$app->get('/api/budgetsprojection', function() use ($app,&$mysqli) {

  $apiversion = 1;
  $apiversioncompat = 1;

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  // Retrieve the 'onlyvalid' parameter
  if ($request->hasQuery('onlyvalid')) {
    $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
  }
  else {
    $onlyvalid = false;
  }

  // Retrieve the 'budgetids' parameter
  if ($request->hasQuery('budgetids')) {
    $budgetids = json_decode($request->getQuery('budgetids'));
    if (($budgetids === false) || !is_array($budgetids)) {
      $errmsg[] = "Parameter budgetids: Not a JSON encoded list of budgets ids";
    }
    else {
      foreach ($budgetids as $x => $budgetid) {
        $budgetids[$x] = $mysqli->real_escape_string($budgetid);
      }
    }
  }
  else {
    $budgetids = array();
  }

  // Retrieve the 'budgethashes' parameter
  if ($request->hasQuery('budgethashes')) {
    $budgethashes = json_decode($request->getQuery('budgethashes'));
    if (($budgethashes === false) || !is_array($budgethashes)) {
      $errmsg[] = "Parameter budgethashes: Not a JSON encoded list of budget hashes";
    }
    else {
      foreach ($budgethashes as $budgethash) {
        if ( strlen($budgethash) != 64 ) {
          $errmsg[] = "Parameter budgethashes: Entry $budgethash: Incorrect hash format.";
        }
      }
    }
  }
  else {
    $budgethashes = array();
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cacheserial = sha1(serialize($budgetids).serialize($budgethashes));
    $cachefnam = CACHEFOLDER.sprintf("dashninja_budgetsprojection_%d_%d_%d_%d_%s",$testnet,$onlyvalid,count($budgetids),count($budgethashes),$cacheserial);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $data["cache"]["fromcache"] = true;
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);

      // Add selection by budget hashes
      $sqlbh = "";
      if (count($budgethashes) > 0) {
        $sqls = '';
        foreach($budgethashes as $budgethash) {
          if (strlen($sqls)>0) {
            $sqls .= ' OR ';
          }
          $sqls .= sprintf("BudgetHash = '%s'",$budgethash);
        }
        $sqlbh = " AND (".$sqls.")";
      }

      // Add selection by budget ids
      $sqlbi = "";
      if (count($budgetids) > 0) {
        $sqls = '';
        foreach($budgetids as $budgetid) {
          if (strlen($sqls)>0) {
            $sqls .= ' OR ';
          }
          $sqls .= sprintf("BudgetId = '%s'",$budgetid);
        }
        $sqlbi = " AND (".$sqls.")";
      }

      // Get budgets projection
      $sql = sprintf("SELECT * FROM cmd_budget_projection WHERE BudgetTestnet = %d%s%s",$testnet,$sqlbh,$sqlbi);
      if ($onlyvalid) {
        $sql .= " AND IsValid = 1";
      }

      if ($result = $mysqli->query($sql)) {
        $budgetvalid = 0;
        $budgets = array();
        while($row = $result->fetch_assoc()){
          $budgets[] = array(
              "ID" => stripslashes($row["BudgetId"]),
              "Hash" => stripslashes($row["BudgetHash"]),
              "FeeHash" => stripslashes($row["FeeHash"]),
              "URL" => stripslashes($row["BudgetURL"]),
              "BlockStart" => intval($row["BlockStart"]),
              "BlockEnd" => intval($row["BlockEnd"]),
              "TotalPaymentCount" => intval($row["TotalPaymentCount"]),
              "RemainingPaymentCount" => intval($row["RemainingPaymentCount"]),
              "PaymentAddress" => stripslashes($row["PaymentAddress"]),
              "Ratio" => floatval($row["Ratio"]),
              "Yeas" => intval($row["Yeas"]),
              "Nays" => intval($row["Nays"]),
              "Abstains" => intval($row["Abstains"]),
              "TotalPayment" => floatval($row["TotalPayment"]),
              "MonthlyPayment" => floatval($row["MonthlyPayment"]),
              "Alloted" => floatval($row["Alloted"]),
              "TotalBudgetAlloted" => floatval($row["TotalBudgetAlloted"]),
              "IsValid" => ($row["IsValid"] == 1),
              "IsValidReason" => stripslashes($row["IsValidReason"]),
              "FirstReported" => strtotime($row["FirstReported"]),
              "LastReported" => strtotime($row["LastReported"])
          );
          if ((time()-strtotime($row["LastReported"])) <= 3600) {
            $budgetvalid += intval($row["IsValid"]);
          }
        }

        $totalmninfo = 0;
        $uniquemnips = 0;
        $mninfo = dmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
        if ($mninfo === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error,$totalmninfo)));
          return $response;
        }

        $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1",$testnet);
        if ($result = $mysqli->query($sql)) {
          $currentblock = $result->fetch_assoc();
          $currentblock["BlockId"] = intval($currentblock["BlockId"]);
          $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
          $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
        }
        else {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
          return $response;
        }

        $nSubsidy = 5;
        if ($testnet == 0){
          $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
          for($i = 210240; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
          $estimatedbudgetamount = (($nSubsidy/100)*10)*576*30;
        } else {
          $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 50) + 50 ;
          for($i = 46200; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
          $estimatedbudgetamount = (($nSubsidy/100)*10)*50;
        }

        $data = array('budgetsprojection' => $budgets,
            'stats' => array(
                'budgetalloted' => $budgetvalid,
                'totalmns' => intval($totalmninfo),
                'nextsuperblock' => array(
                    "blockheight" => $nextsuperblock,
                    "estimatedbudgetamount" => $estimatedbudgetamount
                ),
                'latestblock' => $currentblock
            ),
            'cache' => array(
                'time' => time(),
                'fromcache' => false
            ),
            'api' => array(
                'version' => $apiversion,
                'compat' => $apiversioncompat,
                'bev' => 'bp='.DASHNINJA_BEV.".".$apiversion
            )
        );

        $data["debug"] = array("nSubsidy" => $nSubsidy);

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        file_put_contents($cachefnam,serialize($data),LOCK_EX);
        unlink($cachefnamupdate);
      }
      else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
      }
    }
  }
  return $response;

});

// Get governance proposals
// Parameters:
//   testnet=0|1
//   onlyvalid=0|1
//   proposalshashes=[json array of hashes]
//   proposalsnames=[json array of hashes]
$app->get('/api/governanceproposals', function() use ($app,&$mysqli) {

    $apiversion = 2;
    $apiversioncompat = 1;

    //Create a response
    $response = new Phalcon\Http\Response();
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader("Content-Type", "application/json");

    $request = $app->request;

    $errmsg = array();

    if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
        $errmsg[] = "No CONTENT expected";
    }

    // Retrieve the 'testnet' parameter
    if ($request->hasQuery('testnet')) {
        $testnet = intval($request->getQuery('testnet'));
        if (($testnet != 0) && ($testnet != 1)) {
            $testnet = 0;
        }
    }
    else {
        $testnet = 0;
    }

    // Retrieve the 'onlyvalid' parameter
    if ($request->hasQuery('onlyvalid')) {
        $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
    }
    else {
        $onlyvalid = false;
    }

    // Retrieve the 'proposalsnames' parameter
    if ($request->hasQuery('proposalsnames')) {
        $proposalsnames = json_decode($request->getQuery('proposalsnames'));
        if (($proposalsnames === false) || !is_array($proposalsnames)) {
            $errmsg[] = "Parameter proposalsnames: Not a JSON encoded list of budgets ids";
        }
        else {
            foreach ($proposalsnames as $x => $proposalname) {
                $proposalsnames[$x] = $mysqli->real_escape_string($proposalname);
            }
        }
    }
    else {
        $proposalsnames = array();
    }

    // Retrieve the 'budgethashes' parameter
    if ($request->hasQuery('proposalshashes')) {
        $proposalshashes = json_decode($request->getQuery('proposalshashes'));
        if (($proposalshashes === false) || !is_array($proposalshashes)) {
            $errmsg[] = "Parameter proposalshashes: Not a JSON encoded list of budget hashes";
        }
        else {
            foreach ($proposalshashes as $proposalhash) {
                if ( strlen($proposalhash) != 64 ) {
                    $errmsg[] = "Parameter proposalshashes: Entry $proposalhash: Incorrect hash format.";
                }
            }
        }
    }
    else {
        $proposalshashes = array();
    }

    if (count($errmsg) > 0) {
        //Change the HTTP status
        $response->setStatusCode(400, "Bad Request");

        //Send errors to the client
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
    }
    else {
        $cacheserial = sha1(serialize($proposalsnames).serialize($proposalshashes));
        $cachefnam = CACHEFOLDER.sprintf("dashninja_governanceproposals_%d_%d_%d_%d_%s",$testnet,$onlyvalid,count($proposalsnames),count($proposalshashes),$cacheserial);
        $cachefnamupdate = $cachefnam.".update";
        $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
        if ($cachevalid) {
            $data = unserialize(file_get_contents($cachefnam));
            $data["cache"]["fromcache"] = true;
            $response->setStatusCode(200, "OK");
            $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        }
        else {
            touch($cachefnamupdate);

            // Add selection by proposals hashes
            $sqlhashes= "";
            if (count($proposalshashes) > 0) {
                $sqls = '';
                foreach($proposalshashes as $proposalhash) {
                    if (strlen($sqls)>0) {
                        $sqls .= ' OR ';
                    }
                    $sqls .= sprintf("GovernanceObjectId = '%s'",$proposalhash);
                }
                $sqlhashes = " AND (".$sqls.")";
            }

            // Add selection by proposals names
            $sqlnames = "";
            if (count($proposalsnames) > 0) {
                $sqls = '';
                foreach($proposalsnames as $proposalname) {
                    if (strlen($sqls)>0) {
                        $sqls .= ' OR ';
                    }
                    $sqls .= sprintf("GovernanceObjectName = '%s'",$proposalname);
                }
                $sqlnames = " AND (".$sqls.")";
            }

            // Retrieve current block
            $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1",$testnet);
            if ($result = $mysqli->query($sql)) {
                $currentblock = $result->fetch_assoc();
                $currentblock["BlockId"] = intval($currentblock["BlockId"]);
                $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
                $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
            }
            else {
                $response->setStatusCode(503, "Service Unavailable");
                $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
                return $response;
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

            // Get governance proposals
            $sql = sprintf("SELECT * FROM cmd_gobject_proposals WHERE GovernanceObjectTestnet = %d%s%s",$testnet,$sqlhashes,$sqlnames);
            if ($onlyvalid) {
                $sql .= " AND GovernanceObjectCachedValid = 1";
            }

            if ($result = $mysqli->query($sql)) {
                $proposalsvalid = 0;
                $proposalsfunded = 0;
                $proposals = array();
                while($row = $result->fetch_assoc()){
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
                    $proposalsvalid+=intval($row["GovernanceObjectBlockchainValidity"]);
                    if (($row['GovernanceObjectEpochStart'] <= $nextsuperblocktimestamp) && ($row['GovernanceObjectEpochEnd'] > time())) {
                        $proposalsfunded+=intval($row["GovernanceObjectCachedFunding"]);
                    }
                }

                $totalmninfo = 0;
                $uniquemnips = 0;
                $mninfo = dmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
                if ($mninfo === false) {
                    $response->setStatusCode(503, "Service Unavailable");
                    $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error,$totalmninfo)));
                    return $response;
                }

                $keyst1 = "governancebudget";
                $keyst2 = "governancesb";
                if ($testnet == 1) {
                    $keyst1 .= "test";
                    $keyst2 .= "test";
                }
                $sql = sprintf('SELECT `StatKey`, `StatValue` FROM `cmd_stats_values` WHERE StatKey = "%s" OR  StatKey = "%s" LIMIT 2',$keyst1,$keyst2);
                if ($result = $mysqli->query($sql)) {
                    while($row = $result->fetch_assoc()){
                      if (!is_null($row["StatValue"]) && ($row["StatValue"] > 0)) {
                          if ($row["StatKey"] == $keyst1) {
                              $estimatedbudgetamount = floatval($row["StatValue"]);
                          } elseif ($row["StatKey"] == $keyst2) {
                              $nextsuperblock = floatval($row["StatValue"]);
                          }
                      }
                    }
                }
                else {
                    $response->setStatusCode(503, "Service Unavailable");
                    $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
                    return $response;
                }

                $data = array('governanceproposals' => $proposals,
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
                        'fromcache' => false
                    ),
                    'api' => array(
                        'version' => $apiversion,
                        'compat' => $apiversioncompat,
                        'bev' => 'gp='.DASHNINJA_BEV.".".$apiversion
                    )
                );

                //Change the HTTP status
                $response->setStatusCode(200, "OK");
                $response->setJsonContent(array('status' => 'OK', 'data' => $data));
                file_put_contents($cachefnam,serialize($data),LOCK_EX);
                unlink($cachefnamupdate);
            }
            else {
                $response->setStatusCode(503, "Service Unavailable");
                $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
            }
        }
    }
    return $response;

});

// Get governance proposals vote limit
// Parameters:
//   testnet=0|1
$app->get('/api/governanceproposals/votelimit', function() use ($app,&$mysqli) {

    $apiversion = 1;
    $apiversioncompat = 1;

    //Create a response
    $response = new Phalcon\Http\Response();
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader("Content-Type", "application/json");

    $request = $app->request;

    $errmsg = array();

    if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
        $errmsg[] = "No CONTENT expected";
    }

    $testnet = 0;
    // Retrieve the 'testnet' parameter
    if ($request->hasQuery('testnet')) {
        $testnet = intval($request->getQuery('testnet'));
        if (($testnet != 0) && ($testnet != 1)) {
            $testnet = 0;
        }
    }

    $cachefnam = CACHEFOLDER.sprintf("dashninja_governanceproposals_votelimit_%d",$testnet);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
        $data = unserialize(file_get_contents($cachefnam));
        $data["cache"]["fromcache"] = true;

        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
        touch($cachefnamupdate);

        // Retrieve current block
        $sql = sprintf("SELECT `BlockId`, `BlockTime`, `BlockDifficulty` FROM `cmd_info_blocks` WHERE BlockTestNet = %d ORDER BY BlockId DESC LIMIT 1", $testnet);
        if ($result = $mysqli->query($sql)) {
            $currentblock = $result->fetch_assoc();
            $currentblock["BlockId"] = intval($currentblock["BlockId"]);
            $currentblock["BlockTime"] = intval($currentblock["BlockTime"]);
            $currentblock["BlockDifficulty"] = floatval($currentblock["BlockDifficulty"]);
        } else {
            $response->setStatusCode(503, "Service Unavailable");
            $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
            return $response;
        }

        if ($testnet == 0) {
            $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
        } else {
            $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 50) + 50;
        }

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
            $response->setStatusCode(503, "Service Unavailable");
            $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
            return $response;
        }

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

        $data = array('votelimit' => array(
                'nextvote' => $nextvotelimitblock,
                'nextsuperblock' => $nextsuperblock,
                'latestblock' => $currentblock
            ),
            'cache' => array(
                'time' => time(),
                'fromcache' => false
            ),
            'api' => array(
                'version' => $apiversion,
                'compat' => $apiversioncompat,
                'bev' => 'gpvl=' . DASHNINJA_BEV . "." . $apiversion
            )
        );

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        file_put_contents($cachefnam, serialize($data), LOCK_EX);
        unlink($cachefnamupdate);
    }

    return $response;

});


// Get governance proposals votes
// Parameters:
//   testnet=0|1
//   proposalhash=hash of the proposal
$app->get('/api/governanceproposals/votes', function() use ($app,&$mysqli) {

    $apiversion = 1;
    $apiversioncompat = 1;

    $rehash = '/^[a-z0-9]{64}$/';

    //Create a response
    $response = new Phalcon\Http\Response();
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader("Content-Type", "application/json");

    $request = $app->request;

    $errmsg = array();

    if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
        $errmsg[] = "No CONTENT expected";
    }

    // Retrieve the 'testnet' parameter
    if ($request->hasQuery('testnet')) {
        $testnet = intval($request->getQuery('testnet'));
        if (($testnet != 0) && ($testnet != 1)) {
            $testnet = 0;
        }
    }
    else {
        $testnet = 0;
    }

    // Retrieve the 'debug' parameter
    if ($request->hasQuery('debug')) {
        $debug = (intval($request->getQuery('debug')) == 1);
    }
    else {
        $debug = false;
    }

    // Retrieve the 'onlyvalid' parameter
    if ($request->hasQuery('onlyvalid')) {
        $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
    }
    else {
        $onlyvalid = false;
    }

    // Retrieve the 'proposalhash' parameter
    if ($request->hasQuery('proposalhash')) {
        $budgetid = $request->getQuery('proposalhash');
        $budgetid = $mysqli->real_escape_string($budgetid);
    }
    else {
        $errmsg[] = "Parameter proposalhash is mandatory";
    }

    if (preg_match($rehash, $budgetid) !== 1) {
        $errmsg[] = "Parameter proposalhash is incorrect";
    }

    if (count($errmsg) > 0) {
        //Change the HTTP status
        $response->setStatusCode(400, "Bad Request");

        //Send errors to the client
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
    }
    else {
        $cacheserial = sha1(serialize($budgetid));
        $cachefnam = CACHEFOLDER.sprintf("dashninja_governanceproposals_votes_%d_%s",$testnet,$cacheserial);
        $cachefnamupdate = $cachefnam.".update";
        $cachetime = filemtime($cachefnam);
        $cachevalid = (is_readable($cachefnam) && ((($cachetime+120)>=time()) || file_exists($cachefnamupdate)));
        if ($cachevalid) {
            $data = unserialize(file_get_contents($cachefnam));
            $data["cache"]["fromcache"] = true;
            $response->setStatusCode(200, "OK");
            $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        }
        else {
            touch($cachefnamupdate);

            // Get budget votes
            $sql = sprintf("SELECT * FROM cmd_gobject_votes WHERE GovernanceObjectTestnet = %d AND GovernanceObjectId = '%s'",$testnet,$budgetid);
            if ($onlyvalid) {
                $sql .= " AND VoteIsValid = 1";
            }

            if ($result = $mysqli->query($sql)) {
                $votesyes = 0;
                $votesno = 0;
                $votesabstain = 0;
                $governanceproposalvotes = array();
                while($row = $result->fetch_assoc()){
                    $governanceproposalvotes[] = array(
                        "MasternodeOutputHash" => $row["MasternodeOutputHash"],
                        "MasternodeOutputIndex" => intval($row["MasternodeOutputIndex"]),
                        "VoteHash" => $row["VoteHash"],
                        "VoteValue" => $row["VoteValue"],
                        "VoteTime" => intval($row["VoteTime"])
                    );
                    if ($row["VoteValue"] == "YES") {
                        $votesyes++;
                    }
                    elseif ($row["VoteValue"] == "NO") {
                        $votesno++;
                    }
                    elseif ($row["VoteValue"] == "ABSTAIN") {
                        $votesabstain++;
                    }
                }

                $data = array('governanceproposalvotes' => $governanceproposalvotes,
                    'stats' => array(
                        'votesyes' => $votesyes,
                        'votesno' => $votesno,
                        'votesabstain' => $votesabstain,
                    ),
                    'cache' => array(
                        'time' => time(),
                        'fromcache' => false
                    ),
                    'api' => array(
                        'version' => $apiversion,
                        'compat' => $apiversioncompat,
                        'bev' => 'gpv='.DASHNINJA_BEV.".".$apiversion
                    )
                );

                if ($debug) {
                    $data["debug"] = array("sql" => $sql);
                }

                //Change the HTTP status
                $response->setStatusCode(200, "OK");
                $response->setJsonContent(array('status' => 'OK', 'data' => $data));
                file_put_contents($cachefnam,serialize($data),LOCK_EX);
                unlink($cachefnamupdate);
            }
            else {
                $response->setStatusCode(503, "Service Unavailable");
                $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
            }
        }
    }
    return $response;

});

// Get governance triggers
// Parameters:
//   testnet=0|1
//   onlyvalid=0|1
//   afterblockheight=x
$app->get('/api/governancetriggers', function() use ($app,&$mysqli) {

    $apiversion = 1;
    $apiversioncompat = 1;

    //Create a response
    $response = new Phalcon\Http\Response();
    $response->setHeader('Access-Control-Allow-Origin', '*');
    $response->setHeader("Content-Type", "application/json");

    $request = $app->request;

    $errmsg = array();

    if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
        $errmsg[] = "No CONTENT expected";
    }

    // Retrieve the 'testnet' parameter
    if ($request->hasQuery('testnet')) {
        $testnet = intval($request->getQuery('testnet'));
        if (($testnet != 0) && ($testnet != 1)) {
            $testnet = 0;
        }
    }
    else {
        $testnet = 0;
    }

    // Retrieve the 'onlyvalid' parameter
    if ($request->hasQuery('onlyvalid')) {
        $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
    }
    else {
        $onlyvalid = false;
    }

    // Retrieve the 'afterblockheight' parameter
    if ($request->hasQuery('afterblockheight')) {
        $onlyfuture = true;
        $afterblockheight = intval($request->getQuery('afterblockheight'));
    }
    else {
        $onlyfuture = false;
        $afterblockheight = 1;
    }

    if (count($errmsg) > 0) {
        //Change the HTTP status
        $response->setStatusCode(400, "Bad Request");

        //Send errors to the client
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
    }
    else {
        $cachefnam = CACHEFOLDER.sprintf("dashninja_governancetriggers_%d_%d_%d_%d",$testnet,$onlyvalid,$onlyfuture,$afterblockheight);
        $cachefnamupdate = $cachefnam.".update";
        $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
        if ($cachevalid) {
            $data = unserialize(file_get_contents($cachefnam));
            $data["cache"]["fromcache"] = true;
            $response->setStatusCode(200, "OK");
            $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        }
        else {
            touch($cachefnamupdate);

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
                ." cgot.GovernanceObjectTestnet = %d AND cgot.GovernanceObjectVotesAbsoluteYes > 0",$testnet);
            if ($onlyvalid) {
                $sql .= " AND cgot.GovernanceObjectCachedValid = 1";
            }
            if ($onlyfuture) {
                $sql .= sprintf(" AND cgot.GovernanceObjectEventBlockHeight >= %d",$afterblockheight);
            }

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

                $data = array('governancetriggers' => $triggers,
                    'stats' => array(
                        'valid' => $triggersvalid,
                    ),
                    'cache' => array(
                        'time' => time(),
                        'fromcache' => false
                    ),
                    'api' => array(
                        'version' => $apiversion,
                        'compat' => $apiversioncompat,
                        'bev' => 'gt='.DASHNINJA_BEV.".".$apiversion
                    )
                );

                //Change the HTTP status
                $response->setStatusCode(200, "OK");
                $response->setJsonContent(array('status' => 'OK', 'data' => $data));
                file_put_contents($cachefnam,serialize($data),LOCK_EX);
                unlink($cachefnamupdate);
            }
            else {
                $response->setStatusCode(503, "Service Unavailable");
                $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
            }
        }
    }
    return $response;

});

// Get currently running nodes
// Parameters:
//   testnet=0|1
$app->get('/api/nodes', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => array('Payload (or CONTENT_LENGTH) is missing')));
  }
  else {
    // Retrieve all known nodes for current hub
    $sql = "SELECT NodeName, NodeTestNet, NodeEnabled, NodeProcessStatus, NodeVersion, NodeProtocol, NodeBlocks, NodeLastBlockHash, NodeConnections, UNIX_TIMESTAMP(LastUpdate) LastUpdate FROM cmd_nodes n, cmd_nodes_status s WHERE n.NodeId = s.NodeId AND n.NodeTestNet = %d ORDER BY NodeName";
    $sqlx = sprintf($sql,$testnet);
    $numnodes = 0;
    $nodes = array();
    if ($result = $mysqli->query($sqlx)) {
      while($row = $result->fetch_assoc()){
        $numnodes++;
        $nodes[] = $row;
      }

      //Change the HTTP status
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $nodes));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
    }
  }
  return $response;

});

// Get masternodes status
// Parameters:
//   testnet=0|1
//   pubkeys=JSON encoded list of pubkeys
//   ips=JSON encoded list of ip:port
//   vins=JSON encoded list of output-index
//   protocol=latest|integer (optional, then value=latest)
//   prev12=0|1 (optional, respond as pre v0.12 Dash Ninja API, obsolete)
// Each following enabled parameter will slow down the query, only activate if you really need the data :
//   balance=0|1 (optional, add balance info)
//   donation=0|1 (optional, add donation info, obsolete)
//   exstatus=0|1 (optional, add extended masternode status)
//   portcheck=0|1 (optional, add portcheck info)
//   lastpaid=0|1 (optional, add lastpaid info)
//   votes=0|1 (optional, add votes info, obsolete)
$app->get('/api/masternodes', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  // Retrieve the 'protocol' parameter
  if ($request->hasQuery('protocol')) {
    $protocol = $request->getQuery('protocol');
    if (is_numeric($protocol)) {
      $protocol = intval($protocol);
      if ($protocol < 0) {
        $protocol = -1;
      }
    }
    else {
      $protocol = -1;
    }
  }
  else {
    $protocol = -1;
  }

  if ($protocol == -1) {
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
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        return $response;
      }
    }
  }

  // Retrieve the 'pubkeys' parameter
  if ($request->hasQuery('pubkeys')) {
    $mnpubkeys = json_decode($request->getQuery('pubkeys'));
    if (($mnpubkeys === false) || !is_array($mnpubkeys)) {
      $errmsg[] = "Parameter pubkeys: Not a JSON encoded list of pubkeys";
    }
    else {
      foreach ($mnpubkeys as $mnpubkey) {
        if ( ( ($testnet == 1) && ! ( (substr($mnpubkey,0,1) == 'y') || (substr($mnpubkey,0,1) == 'x') ) )
          || ( ($testnet == 0) && ! ( (substr($mnpubkey,0,1) == 'X') || (substr($mnpubkey,0,1) == '7') ) )
          || ( strlen($mnpubkey) != 34 ) ) {
          $errmsg[] = "Parameter pubkeys: Entry $mnpubkey: Incorrect pubkey format.";
        }
      }
    }
  }
  else {
    $mnpubkeys = array();
  }

  // Retrieve the 'vins' parameter
  if ($request->hasQuery('vins')) {
    $mnvins = json_decode($request->getQuery('vins'));
    if (($mnvins === false) || !is_array($mnvins)) {
      $errmsg[] = "Parameter vins: Not a JSON encoded list of pubkeys";
    }
    else {
      foreach ($mnvins as $mnvin) {
        $mnvinx = explode("-",$mnvin);
        if (count($mnvinx) != 2) {
          $errmsg[] = "Parameter vins: Entry $mnvin: Incorrect format (should be hash-index).";
        }
        else {
          if ( strlen($mnvinx[0]) != 64 ) {
            $errmsg[] = "Parameter vins: Entry $mnvin: Incorrect hash format.";
          }
        }
      }
    }
  }
  else {
    $mnvins = array();
  }

  // Retrieve the 'ips' parameter
  $mnips = array();
  if ($request->hasQuery('ips')) {
    $mnipsa = json_decode($request->getQuery('ips'));
    if (($mnipsa === false) || !is_array($mnipsa)) {
      $errmsg[] = "Parameter ips: Not a JSON encoded list of ip:port.";
    }
    else {
      foreach ($mnipsa as $mnipa) {
        $mnipx = getipport($mnipa);
        if (count($mnipx) != 2) {
          $errmsg[] = "Parameter ips: Entry $mnipa: Incorrect format (should be IP:Port).";
        }
        else {
          if (!filter_var($mnipx[0], FILTER_VALIDATE_IP)) {
            $errmsg[] = "Parameter ips: Entry ".$mnipx[0].": Incorrect IP format.";
          }
          $mnport = intval($mnipx[1]);
          if (($mnport < 0) || ($mnport > 65535)) {
            $errmsg[] = "Parameter ips: Entry $mnipa: Incorrect port value.";
          }
          $mnips[] = $mnipx;
        }
      }
    }
  }

  $finalcount = count($mnips)+count($mnvins)+count($mnpubkeys);
  if ($finalcount == 0) {
    $errmsg[] = "To use this API you must select at least 1 masternode by IP, vin or pubkey. If you need a full list, try: /data/masternodeslistfull-".$testnet.".json";
  }
  elseif ($finalcount > 50) {
    $errmsg[] = "To use this API you must select at most 50 masternodes by IP, vin or pubkey. If you need a full list, try: /data/masternodeslistfull-".$testnet.".json";
  }

    // Retrieve the optional info parameters (status, balance and portcheck)
  $withbalance = ($request->hasQuery('balance') && ($request->getQuery('balance') == 1));
  $withportcheck = ($request->hasQuery('portcheck') && ($request->getQuery('portcheck') == 1));
  $withlastpaid = ($request->hasQuery('lastpaid') && ($request->getQuery('lastpaid') == 1));
  $withdonation = ($request->hasQuery('donation') && ($request->getQuery('donation') == 1));
  $withvotes = ($request->hasQuery('votes') && ($request->getQuery('votes') == 1));
  $withexstatus = ($request->hasQuery('exstatus') && ($request->getQuery('exstatus') == 1));
  $prev12 = ($request->hasQuery('prev12') && ($request->getQuery('prev12') == 1));

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  elseif ($prev12) {
    // Retrieve masternodes list
    $nodes = dmn_masternodes_get($mysqli, $testnet, $protocol, $mnpubkeys, $mnips, $withlastpaid);
    if ($nodes !== false) {
      // Generate the final list of IP:port (resulting from the query)
      $mnipstrue = array();
      $mnpubkeystrue = array();
      foreach($nodes as $node) {
        $mnipstrue[] = ip2long($node['MasternodeIP']).":".$node['MasternodePort'];
        $mnpubkeystrue[] = $node['MNPubKey'];
      }

      // If we need the portcheck info, let's retrieve it
      if ($withportcheck) {
        $portcheck = dmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet);
        if ($portcheck === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        }
        else {
          foreach($nodes as $key => $node) {
            if (array_key_exists($node['MasternodeIP'].":".$node['MasternodePort'],$portcheck)) {
              $nodes[$key]['Portcheck'] = $portcheck[$node['MasternodeIP'].":".$node['MasternodePort']];
            }
            else {
              $nodes[$key]['Portcheck'] = false;
            }
          }
        }
      }

      // If we need the balance info, let's retrieve it
      if ($withbalance) {
        $balances = dmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet);
        if ($balances === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        }
        else {
          foreach($nodes as $key => $node) {
            if (array_key_exists($node['MNPubKey'],$balances)) {
              $nodes[$key]['Balance'] = $balances[$node['MNPubKey']];
            }
            else {
              $nodes[$key]['Balance'] = false;
            }
          }
        }
      }

      // If we need the donation info, let's retrieve it
      if ($withdonation) {
        $donation = dmn_masternodes_donation_get($mysqli, $mnipstrue, $testnet);
        if ($donation === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        }
        else {
          foreach($nodes as $key => $node) {
            if (array_key_exists($node['MasternodeIP'].":".$node['MasternodePort'],$donation)) {
              $nodes[$key]['Donation'] = $donation[$node['MasternodeIP'].":".$node['MasternodePort']];
            }
            else {
              $nodes[$key]['Donation'] = false;
            }
          }
        }
      }

      // If we need the votes info, let's retrieve it
      if ($withvotes) {
        $votes = dmn_masternodes_votes_get($mysqli, $mnipstrue, $testnet);
        if ($votes === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        }
        else {
          foreach($nodes as $key => $node) {
            if (array_key_exists($node['MasternodeIP'].":".$node['MasternodePort'],$votes)) {
              $nodes[$key]['Votes'] = $votes[$node['MasternodeIP'].":".$node['MasternodePort']];
            }
            else {
              $nodes[$key]['Votes'] = false;
            }
          }
        }
      }

      //Change the HTTP status
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $nodes));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
    }
  }
  else {
    // Retrieve masternodes list
    $nodes = dmn_masternodes2_get($mysqli, $testnet, $protocol, $mnpubkeys, $mnips, $mnvins);
    if (is_array($nodes)) {
      if  (count($nodes) > 0) {
          // Generate the final list of IP:port (resulting from the query)
          $mnipstrue = array();
          $mnpubkeystrue = array();
          $mnvinstrue = array();
          foreach ($nodes as $node) {
              $tmpvin = $node["MasternodeOutputHash"] . "-" . $node["MasternodeOutputIndex"];
              if (!in_array($tmpvin, $mnvinstrue)) {
                  $mnvinstrue[] = $tmpvin;
              }
              if ($node["MasternodeTor"] != "") {
                  $mnip = $node['MasternodeTor'] . ".onion";
              } else {
                  $mnip = $node['MasternodeIP'];
              }
              $tmpip = $mnip . "-" . $node['MasternodePort'];
              if (!in_array($tmpip, $mnipstrue)) {
                  $mnipstrue[] = $tmpip;
              }
              $mnpubkeystrue[] = $node['MasternodePubkey'];
          }

          // If we need the portcheck info, let's retrieve it
          if ($withportcheck) {
              $portcheck = dmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet);
              if ($portcheck === false) {
                  $response->setStatusCode(503, "Service Unavailable");
                  $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
              } else {
                  foreach ($nodes as $key => $node) {
                      if ($node["MasternodeTor"] != "") {
                          $mnip = $node['MasternodeTor'] . ".onion";
                      } else {
                          $mnip = $node['MasternodeIP'];
                      }
                      if (array_key_exists($mnip . "-" . $node['MasternodePort'], $portcheck)) {
                          $nodes[$key]['Portcheck'] = $portcheck[$mnip . "-" . $node['MasternodePort']];
                      } else {
                          $nodes[$key]['Portcheck'] = false;
                      }
                  }
              }
          }

          // If we need the balance info, let's retrieve it
          if ($withbalance) {
              $balances = dmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet);
              if ($balances === false) {
                  $response->setStatusCode(503, "Service Unavailable");
                  $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
              } else {
                  foreach ($nodes as $key => $node) {
                      if (array_key_exists($node['MasternodePubkey'], $balances)) {
                          $nodes[$key]['Balance'] = $balances[$node['MasternodePubkey']];
                      } else {
                          $nodes[$key]['Balance'] = false;
                      }
                  }
              }
          }

          // If we need the extended status info, let's retrieve it
          if ($withexstatus) {
              $exstatus = dmn_masternodes_exstatus_get($mysqli, $mnvinstrue, $testnet);
              if ($portcheck === false) {
                  $response->setStatusCode(503, "Service Unavailable");
                  $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno . ': ' . $mysqli->error)));
              } else {
                  foreach ($nodes as $key => $node) {
                      if (array_key_exists($node["MasternodeOutputHash"] . "-" . $node['MasternodeOutputIndex'], $exstatus)) {
                          $nodes[$key]['ExStatus'] = $exstatus[$node["MasternodeOutputHash"] . "-" . $node['MasternodeOutputIndex']];
                      } else {
                          $nodes[$key]['ExStatus'] = false;
                      }
                  }
              }
          }

          //Change the HTTP status
          $response->setStatusCode(200, "OK");
          $response->setJsonContent(array('status' => 'OK', 'data' => $nodes));
      }
      else {
          //Change the HTTP status
          $response->setStatusCode(200, "OK");
          $response->setJsonContent(array('status' => 'OK', 'data' => $nodes));
      }
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
    }
  }
  return $response;

});

// Get masternodes donations list
// Parameters:
//   testnet=0|1
$app->get('/api/masternodes/donations', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {

    $mndonationlist = dmn_masternodes_donation_get($mysqli, array(), $testnet);

    if ($mndonationlist !== false) {

      $wsresult = array();
      foreach($mndonationlist as $mnipstr => $data) {
        $mnip = explode(':',$mnipstr);
        $wsresult[] = array("MasternodeIP" => $mnip[0],
                            "MasternodePort" => $mnip[1],
                            "MasternodeDonationPubKey" => $data["DonationPubKey"],
                            "MasternodeDonationPercentage" => $data["DonationOccurence"]);
      }

      //Change the HTTP status
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $wsresult));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
    }
  }

  return $response;

});

// Get masternodes donations stats
// Parameters:
//   testnet=0|1
$app->get('/api/masternodes/donations/stats', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {

    $peraddress = dmn_masternodes_donations_get($mysqli, $testnet);
    if ($peraddress !== false) {

      //Change the HTTP status
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $peraddress));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
    }
  }

  return $response;

});

// Get masternodes donations stats
// Parameters:
//   testnet=0|1
$app->get('/api/masternodes/votes', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    // Retrieve masternodes list
    $votes = dmn_masternodes_votes_get($mysqli, array(), $testnet);
    if ($votes !== false) {
        $votescount = array(
          array("Vote" => "Yea", "VoteCount" => 0, "VotePercentage" => 0.0),
          array("Vote" => "Nay", "VoteCount" => 0, "VotePercentage" => 0.0),
          array("Vote" => "Abstain", "VoteCount" => 0, "VotePercentage" => 0.0)
        );
        $totalvotes = count($votes);
        foreach($votes as $vote) {
          $totcast = $vote["VoteYeaCount"]+$vote["VoteNayCount"];
          if (($vote["VoteAbstainCount"]>=$totcast) || ($vote["VoteYeaCount"] == $vote["VoteNayCount"])) {
            $votescount[2]["VoteCount"]++;
          }
          elseif ($vote["VoteYeaCount"]>$vote["VoteNayCount"]) {
            $votescount[0]["VoteCount"]++;
          }
          else {
            $votescount[1]["VoteCount"]++;
          }
        }
        $totalcount = $votescount[2]["VoteCount"]+$votescount[1]["VoteCount"]+$votescount[0]["VoteCount"];
        $votescount[0]["VotePercentage"] = $votescount[0]["VoteCount"]/$totalcount;
        $votescount[1]["VotePercentage"] = $votescount[1]["VoteCount"]/$totalcount;
        $votescount[2]["VotePercentage"] = $votescount[2]["VoteCount"]/$totalcount;
        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $votescount));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
    }
  }

  return $response;

});

// Get masternodes stats
// Parameters:
//   testnet=0|1
$app->get('/api/masternodes/stats', function() use ($app,&$mysqli) {

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  // Retrieve the 'testnet' parameter
  if ($request->hasQuery('testnet')) {
    $testnet = intval($request->getQuery('testnet'));
    if (($testnet != 0) && ($testnet != 1)) {
      $testnet = 0;
    }
  }
  else {
    $testnet = 0;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    // Retrieve the total supply for 24h
    $sqlsupply = sprintf("SELECT BlockMNProtocol Protocol, SUM(BlockMNValue) TotalMNValue, SUM(BlockSupplyValue) TotalSupply, SUM(BlockMNPayed) TotalMNPayed, COUNT(BlockMNPayed) TotalBlocks FROM `cmd_info_blocks` WHERE"
                        ." BlockTime >= UNIX_TIMESTAMP(NOW() - INTERVAL 1 DAY) AND BlockTestNet = %d GROUP BY BlockMNProtocol",$testnet);

    // Run the query
    if ($result = $mysqli->query($sqlsupply)) {
      $supplyinfo = array();
      $totalsupplyinfo = array("TotalMNValue" => 0.0,
                               "TotalSupply" => 0.0,
                               "TotalMNPayed" => 0,
                               "TotalBlocks" => 0);
      // Group the result by masternode ip:port (status is per protocolversion and nodename)
      while($row = $result->fetch_assoc()){
        $supplyinfo[$row['Protocol']] = array("TotalMNValue" => floatval($row['TotalMNValue']),
                                              "TotalSupply" => floatval($row['TotalSupply']),
                                              "TotalMNPayed" => intval($row['TotalMNPayed']),
                                              "TotalBlocks" => intval($row['TotalBlocks']),
                                              "RatioPayed" => 0);
        $totalsupplyinfo['TotalMNValue'] += $row['TotalMNValue'];
        $totalsupplyinfo['TotalSupply'] += $row['TotalSupply'];
        $totalsupplyinfo['TotalMNPayed'] += $row['TotalMNPayed'];
        $totalsupplyinfo['TotalBlocks'] += $row['TotalBlocks'];
      }
      $totalsupplyinfo['RatioPayed'] = floatval($totalsupplyinfo['TotalMNPayed'] / $totalsupplyinfo['TotalBlocks']);
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
      return $response;
    }

    $totalmninfo = 0;
    $totaluniqueips = 0;
    $mninfo = dmn_masternodes_count($mysqli,$testnet, $totalmninfo,$totaluniqueips);
    if ($mninfo === false) {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
      return $response;
    }

    // Compute the results
    $finalinfo = array();
    foreach($supplyinfo as $protocol => $info) {
      $info['RatioPayed'] = $info['TotalMNPayed'] / $totalsupplyinfo['TotalBlocks'];
      $finalinfo[$protocol] = $info;
      if (array_key_exists($protocol,$mninfo)) {
        $finalinfo[$protocol]['ActiveMasternodesCount'] = $mninfo[$protocol]['ActiveMasternodesCount'];
        $finalinfo[$protocol]['ActiveMasternodesUniqueIPs'] = $mninfo[$protocol]['ActiveMasternodesUniqueIPs'];
        $finalinfo[$protocol]['MasternodeExpectedPayment'] = $info['TotalMNValue'] / $finalinfo[$protocol]['ActiveMasternodesCount'];
      }
      else {
        $finalinfo[$protocol]['ActiveMasternodesCount'] = 0;
        $finalinfo[$protocol]['ActiveMasternodesUniqueIPs'] = 0;
        $finalinfo[$protocol]['MasternodeExpectedPayment'] = 0;
      }
    }
    $totalsupplyinfo['ActiveMasternodesCount'] = $totalmninfo;
    $totalsupplyinfo['ActiveMasternodesUniqueIPs'] = $totaluniqueips;
    $totalsupplyinfo['MasternodeExpectedPayment'] = $totalsupplyinfo['TotalMNValue'] / $totalmninfo;

    //Change the HTTP status
    $response->setStatusCode(200, "OK");
    $response->setJsonContent(array('status' => 'OK', 'data' => array('MasternodeStatsPerProtocolVersion' => $finalinfo,
                                                                      'MasternodeStatsTotal' => $totalsupplyinfo)));
  }
  return $response;

});

// Get table vars
// Parameters:
//   none
$app->get('/api/tablevars', function() use ($app,&$mysqli) {

  $apiversion = 1;
  $apiversioncompat = 1;

  //Create a response
  $response = new Phalcon\Http\Response();
  $response->setHeader('Access-Control-Allow-Origin', '*');
  $response->setHeader("Content-Type", "application/json");

  $request = $app->request;

  $errmsg = array();

  if (!array_key_exists('CONTENT_LENGTH',$_SERVER) || (intval($_SERVER['CONTENT_LENGTH']) != 0)) {
    $errmsg[] = "No CONTENT expected";
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => array('Payload (or CONTENT_LENGTH) is missing')));
  }
  else {
    $cachefnam = CACHEFOLDER."dashninja_tablevars";
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+60)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $data["cache"]["fromcache"] = true;
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);

      // Retrieve all known nodes for current hub
      $sql = "SELECT * FROM cmd_stats_values";

      $stats = array();
      if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) {
          $stats[$row["StatKey"]] = $row;
        }

        $data = array("tablevars" => $stats,
            'cache' => array(
                'time' => time(),
                'fromcache' => false
            ),
            'api' => array(
                'version' => $apiversion,
                'compat' => $apiversioncompat,
                'bev' => 'tv='.DASHNINJA_BEV.".".$apiversion
            )
        );

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
        file_put_contents($cachefnam,serialize($data),LOCK_EX);
        unlink($cachefnamupdate);
      } else {
        $response->setStatusCode(503, "Service Unavailable");
        $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno . ': ' . $mysqli->error));
      }
    }
  }
  return $response;

});

$app->notFound(function () use ($app) {
    $response = new Phalcon\Http\Response();
    $response->setStatusCode(404, "Not Found");
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => array('Unknown end-point')));
    $response->send();
});

$app->handle();

?>
