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

// Get blocks detail + stats
// Parameters:
//   testnet=0|1
//   interval=interval (optional, default is P1D for 1 day)
//   pubkeys=filter to those pubkeys
//   onlysuperblocks=0|1 (default to 0)
$app->get('/api/blocks', function() use ($app,&$mysqli) {

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
      $interval = new DateInterval('P1D');
      $cacheinterval = "P1D";
      $cachetime = 150;
    }
  }
  else {
    $interval = new DateInterval('P1D');
    $cacheinterval = "P1D";
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

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cacheserial = sha1(serialize($mnpubkeys));
    $cachefnam = CACHEFOLDER.sprintf("dashninja_blocks_%d_%d_%s_%d_%d_%s",$testnet,$cachenodetail,$cacheinterval,count($mnpubkeys),$onlysuperblocks,$cacheserial);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+$cachetime)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
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

      if ($onlysuperblocks == 1) {
        $extrasql = " AND cib.IsSuperBlock = 1";
      }
      else {
        $extrasql = sprintf(" AND cib.BlockTime >= %d", $datefrom);
        $extrasql.=$sqlpk;
      }

      $sql = sprintf("SELECT BlockId, BlockHash, cib.BlockMNPayee BlockMNPayee, BlockMNPayeeDonation, BlockMNValue, BlockSupplyValue, BlockMNPayed, BlockPoolPubKey, PoolDescription, BlockMNProtocol, BlockTime, BlockDifficulty, BlockMNPayeeExpected, BlockMNValueRatioExpected, IsSuperblock, SuperBlockBudgetName FROM cmd_info_blocks cib LEFT JOIN cmd_pools_pubkey cpp ON cib.BlockPoolPubKey = cpp.PoolPubKey AND cib.BlockTestNet = cpp.PoolTestNet WHERE cib.BlockTestNet = %d%s ORDER BY BlockId DESC",$testnet,$extrasql);
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
             "IsSuperBlock" => $row["IsSuperblock"] == 1,
             "SuperBlockBudgetName" => $row["SuperBlockBudgetName"],
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
        $mninfo = drkmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
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
          $perminer[$miner]['RatioMNPayments'] = round($perminer[$miner]['MasternodeAmount'] / ($perminer[$miner]['TotalAmount']-$perminer[$miner]['BudgetAmount']-$perminer[$miner]['SuperBlockPoolAmount']),3);
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

// Get budgets
// Parameters:
//   testnet=0|1
//   onlyvalid=0|1
$app->get('/api/budgets', function() use ($app,&$mysqli) {

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
  if ($request->hasQuery('onlyvalid')) {
    $onlyvalid = (intval($request->getQuery('onlyvalid')) == 1);
  }
  else {
    $onlyvalid = false;
  }

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  else {
    $cachefnam = CACHEFOLDER.sprintf("dashninja_budgets_%d_%d",$testnet,$onlyvalid);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);

      // Get budgets
      $sql = sprintf("SELECT * FROM cmd_budget WHERE BudgetTestnet = %d",$testnet);
      if ($onlyvalid) {
        $sql .= " AND IsValid = 1";
      }
      $sqlblockids = array();
      if ($result = $mysqli->query($sql)) {
        $budgetvalid = 0;
        $budgetestablished = 0;
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
        $mninfo = drkmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
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

        $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
        $nSubsidy = 5;
        if ($testnet == 0){
          for($i = 46200; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
        } else {
          for($i = 210240; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
        }

        $data = array('budgets' => $budgets,
                      'stats' => array(
                                   'budgetvalid' => $budgetvalid,
                                   'budgetestablished' => $budgetestablished,
                                   'totalmns' => intval($totalmninfo),
                                   'nextsuperblock' => array(
                                                             "blockheight" => $nextsuperblock,
                                                             "estimatedbudgetamount" => (($nSubsidy/100)*10)*576*30
                                                            ),
                                   'latestblock' => $currentblock
                                      )
                     );

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
//        file_put_contents($cachefnam,serialize($data),LOCK_EX);
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
$app->get('/api/budgetsprojection', function() use ($app,&$mysqli) {

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
    $cachefnam = CACHEFOLDER.sprintf("dashninja_budgetsprojection_%d",$testnet);
    $cachefnamupdate = $cachefnam.".update";
    $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+120)>=time()) || file_exists($cachefnamupdate)));
    if ($cachevalid) {
      $data = unserialize(file_get_contents($cachefnam));
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => $data));
    }
    else {
      touch($cachefnamupdate);

      // Get budgets
      $sql = sprintf("SELECT * FROM cmd_budget_projection WHERE BudgetTestnet = %d",$testnet);
      if ($onlyvalid) {
        $sql .= " AND IsValid = 1";
      }
      $sqlblockids = array();
      if ($result = $mysqli->query($sql)) {
        $budgetvalid = 0;
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
          $budgetvalid+=intval($row["IsValid"]);
        }

        $totalmninfo = 0;
        $uniquemnips = 0;
        $mninfo = drkmn_masternodes_count($mysqli,$testnet, $totalmninfo, $uniquemnips);
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

        $nextsuperblock = $currentblock["BlockId"] - ($currentblock["BlockId"] % 16616) + 16616;
        $nSubsidy = 5;
        if ($testnet == 0){
          for($i = 46200; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
        } else {
          for($i = 210240; $i <= $nextsuperblock; $i += 210240) $nSubsidy -= $nSubsidy/14;
        }

        $data = array('budgetsprojection' => $budgets,
            'stats' => array(
                'budgetalloted' => $budgetvalid,
                'totalmns' => intval($totalmninfo),
                'nextsuperblock' => array(
                    "blockheight" => $nextsuperblock,
                    "estimatedbudgetamount" => (($nSubsidy/100)*10)*576*30
                ),
                'latestblock' => $currentblock
            )
        );

        //Change the HTTP status
        $response->setStatusCode(200, "OK");
        $response->setJsonContent(array('status' => 'OK', 'data' => $data));
//        file_put_contents($cachefnam,serialize($data),LOCK_EX);
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

// Function to retrieve the masternode list
function drkmn_masternodes_get($mysqli, $testnet = 0, $protocol = 0, $mnpubkeys = array(), $mnips = array(), $withlastpaid = false) {

  $sqlprotocol = sprintf("%d",$protocol);
  $sqltestnet = sprintf("%d",$testnet);
  if ($withlastpaid) {
    $lastpaidnum = 1;
  }
  else {
    $lastpaidnum = 0;
  }
  $cacheserial = sha1(serialize($mnpubkeys).serialize($mnips));
  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_get_%d_%d_%d_%d_%d_%s",$testnet,$protocol,count($mnpubkeys),count($mnips),$lastpaidnum,$cacheserial);
  $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
  if ($cachevalid) {
    $nodes = unserialize(file_get_contents($cachefnam));
  }
  else {
    // Add selection by pubkey
    $sqlpks = "";
    if (count($mnpubkeys) > 0) {
      $sqls = '';
      foreach($mnpubkeys as $mnpubkey) {
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("cimpk.MNPubKey = '%s'",$mysqli->real_escape_string($mnpubkey));
      }
      $sqlpks = " AND (".$sqls.")";
    }
  
    // Add selection by IP:port
    $sqlips = "";
    if (count($mnips) > 0) {
      $sqls = '';
      foreach($mnips as $mnipstr) {
        $mnip = explode(':',$mnipstr);
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(cim.MasternodeIP = %d AND cim.MasternodePort = %d)",$mnip[0],$mnip[1]);
      }
      $sqlips = " AND (".$sqls.")";
    }

    $sql = <<<EOT
DROP TABLE IF EXISTS _node_status;
CREATE TEMPORARY TABLE IF NOT EXISTS
    _node_status ENGINE=MEMORY AS (
    SELECT
        ciml.MasternodeIP,
        ciml.MasternodePort,
        ciml.MasternodeStatus,
        ciml.MNTestNet,
        MAX(ciml.MasternodeStatusPoS) AS MasternodeStatusPoS,
        SUM(CASE
            WHEN MasternodeStatus = 'active' THEN 1
            WHEN MasternodeStatus = 'current' THEN 1
            ELSE NULL END) AS ActiveCount,
        SUM(CASE
            WHEN MasternodeStatus = 'inactive' THEN 1
            ELSE NULL END) AS InactiveCount,
        SUM(CASE
            WHEN MasternodeStatus = 'unlisted' THEN 1
            ELSE NULL END) AS UnlistedCount
    FROM
        cmd_info_masternode_list ciml, cmd_nodes_status cns
    WHERE
        ciml.NodeID = cns.NodeID AND
        ciml.MNTestNet = $sqltestnet AND
        cns.NodeProtocol = $sqlprotocol
    GROUP BY
        ciml.MasternodeIP, ciml.MasternodePort, ciml.MNTestNet
    );
SELECT
    inet_ntoa(cim.MasternodeIP) AS MasternodeIP,
    cim.MasternodePort MasternodePort,
    cimpk.MNPubKey MNPubKey,
    MNActiveSeconds,
    MNLastSeen,
    MNCountry,
    MNCountryCode,
    UNIX_TIMESTAMP(cim.MNLastReported) MNLastReported,
    UNIX_TIMESTAMP(cimpk.MNLastReported) MNLastReportedPubkey,
    ActiveCount,
    InactiveCount,
    UnlistedCount,
    MasternodeStatusPoS,
    cimlp.MNLastPaidBlock MNLastPaidBlock,
    cib.BlockTime MNLastPaidTime,
    cib.BlockMNValue MNLastPaidAmount
FROM
    (cmd_info_masternode cim,
    cmd_info_masternode_pubkeys cimpk,
    _node_status)
    LEFT JOIN
        cmd_info_masternode_lastpaid cimlp
            ON (cimlp.MNTestNet = cimpk.MNTestNet AND cimlp.MNPubKey = cimpk.MNPubKey)
    LEFT JOIN
        cmd_info_blocks cib
            ON (cib.BlockTestNet = cimlp.MNTestNet AND cib.BlockId = cimlp.MNLastPaidBlock)
WHERE
    cim.MasternodeIP = cimpk.MasternodeIP AND cim.MasternodeIP = _node_status.MasternodeIP AND
    cim.MasternodePort = cimpk.MasternodePort AND cim.MasternodePort = _node_status.MasternodePort AND
    cim.MNTestNet = cimpk.MNTestNet AND cim.MNTestNet = _node_status.MNTestNet AND
    cim.MNTestNet = $sqltestnet AND
    ((ActiveCount > 0) OR (InactiveCount > 0)) AND
    cimpk.MNLastReported = 1$sqlpks$sqlips
 ORDER BY MasternodeIP, MasternodePort;
EOT;

    // Execute the query
    $numnodes = 0;
    if ($mysqli->multi_query($sql)) {
      if ($mysqli->more_results() && $mysqli->next_result()) {
        if ($mysqli->more_results() && $mysqli->next_result()) {
          if ($result = $mysqli->store_result()) {
            $nodes = array();
            while($row = $result->fetch_assoc()){
              $numnodes++;
              if (is_null($row['ActiveCount'])) {
                $row['ActiveCount'] = 0;
              }
              if (is_null($row['InactiveCount'])) {
                $row['InactiveCount'] = 0;
              }
              if (is_null($row['UnlistedCount'])) {
                $row['UnlistedCount'] = 0;
              }
              if (strlen($row['MNLastSeen']) == 16) {
                $row['MNLastSeen'] = substr($row['MNLastSeen'],0,-6);
              }
              if ($withlastpaid) {
                if (!is_null($row['MNLastPaidBlock'])) {
                  $row['LastPaid'] = array("MNLastPaidBlock" => $row['MNLastPaidBlock'],
                                           "MNLastPaidTime" => $row['MNLastPaidTime'],
                                           "MNLastPaidAmount" => $row['MNLastPaidAmount']);
                }
                else {
                  $row['LastPaid'] = false;
                }
                unset($row['MNLastPaidBlock'],$row['MNLastPaidTime'],$row['MNLastPaidAmount']);
              }
              $nodes[] = $row;
            }
          }
          else {
            $nodes = false;
          }
        }
        else {
          $nodes = false;
        }
      }
      else {
        $nodes = false;
      }
    }
    else {
      $nodes = false;
    }
    if ($nodes !== false) {
      file_put_contents($cachefnam, serialize($nodes), LOCK_EX);
    }
  }

  return $nodes;
}

// Function to retrieve the masternode list
function drkmn_masternodes2_get($mysqli, $testnet = 0, $protocol = 0, $mnpubkeys = array(), $mnips = array(), $mnvins = array()) {

  $sqlprotocol = sprintf("%d",$protocol);
  $sqltestnet = sprintf("%d",$testnet);

  $cacheserial = sha1(serialize($mnpubkeys).serialize($mnips).serialize($mnvins));
  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes2_get_%d_%d_%d_%d_%d_%s",$testnet,$protocol,count($mnpubkeys),count($mnips),count($mnvins),$cacheserial);
  $cachefnamupdate = $cachefnam.".update";
  $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+300)>=time()) || file_exists($cachefnamupdate)));
  if ($cachevalid) {
    $nodes = unserialize(file_get_contents($cachefnam));
  }
  else {
    // Semaphore that we are currently updating
    touch($cachefnamupdate);

    // Add selection by pubkey
    $sqlpks = "";
    if (count($mnpubkeys) > 0) {
      $sqls = '';
      foreach($mnpubkeys as $mnpubkey) {
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("cim.MasternodePubkey = '%s'",$mysqli->real_escape_string($mnpubkey));
      }
      $sqlpks = " AND (".$sqls.")";
    }

    // Add selection by IP:port
    $sqlips = "";
    if (count($mnips) > 0) {
      $sqls = '';
      foreach($mnips as $mnip) {
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(cim.MasternodeIPv6 = INET6_ATON('%s') AND cim.MasternodePort = %d)",$mysqli->real_escape_string($mnip[0]),$mnip[1]);
      }
      $sqlips = " AND (".$sqls.")";
    }

    // Add selection by Output-Index
    $sqlvins = "";
    if (count($mnvins) > 0) {
      $sqls = '';
      foreach($mnvins as $mnvin) {
        $mnoutput = explode('-',$mnvin);
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(cim.MasternodeOutputHash = '%s' AND cim.MasternodeOutputIndex = %d)",$mysqli->real_escape_string($mnoutput[0]),$mnoutput[1]);
      }
      $sqlvins = " AND (".$sqls.")";
    }

    $sql = <<<EOT
DROP TABLE IF EXISTS _node_status;
CREATE TEMPORARY TABLE IF NOT EXISTS
    _node_status ENGINE=MEMORY AS (
    SELECT
        ciml.MasternodeOutputHash,
        ciml.MasternodeOutputIndex,
        ciml.MasternodeStatus,
        ciml.MasternodeTestNet,
        SUM(CASE
            WHEN MasternodeStatus = 'active' THEN 1
            WHEN MasternodeStatus = 'current' THEN 1
            ELSE NULL END) AS ActiveCount,
        SUM(CASE
            WHEN MasternodeStatus = 'inactive' THEN 1
            ELSE NULL END) AS InactiveCount,
        SUM(CASE
            WHEN MasternodeStatus = 'unlisted' THEN 1
            ELSE NULL END) AS UnlistedCount
    FROM
        cmd_info_masternode2_list ciml, cmd_nodes_status cns
    WHERE
        ciml.NodeID = cns.NodeID AND
        ciml.MasternodeTestNet = $sqltestnet AND
        cns.NodeProtocol = $sqlprotocol
    GROUP BY
        ciml.MasternodeOutputHash, ciml.MasternodeOutputIndex, ciml.MasternodeTestNet
    );
SELECT
    cim.MasternodeOutputHash MasternodeOutputHash,
    cim.MasternodeOutputIndex MasternodeOutputIndex,
    inet6_ntoa(cim.MasternodeIPv6) AS MasternodeIP,
    cim.MasternodePort MasternodePort,
    cim.MasternodePubkey MasternodePubkey,
    MasternodeProtocol,
    MasternodeLastSeen,
    MasternodeActiveSeconds,
    MasternodeLastPaid,
    ActiveCount,
    InactiveCount,
    UnlistedCount
FROM
    (cmd_info_masternode2 cim,
    _node_status)
WHERE
    cim.MasternodeOutputHash = _node_status.MasternodeOutputHash AND
    cim.MasternodeOutputIndex = _node_status.MasternodeOutputIndex AND
    cim.MasternodeTestNet = _node_status.MasternodeTestNet AND
    cim.MasternodeTestNet = $sqltestnet AND
    ((ActiveCount > 0) OR (InactiveCount > 0))$sqlpks$sqlips$sqlvins
ORDER BY MasternodeOutputHash, MasternodeOutputIndex;
EOT;

    // Execute the query
    $numnodes = 0;
    if ($mysqli->multi_query($sql)) {
      if ($mysqli->more_results() && $mysqli->next_result()) {
        if ($mysqli->more_results() && $mysqli->next_result()) {
          if ($result = $mysqli->store_result()) {
            $nodes = array();
            while($row = $result->fetch_assoc()){
              $numnodes++;
              if (is_null($row['ActiveCount'])) {
                $row['ActiveCount'] = 0;
              }
              if (is_null($row['InactiveCount'])) {
                $row['InactiveCount'] = 0;
              }
              if (is_null($row['UnlistedCount'])) {
                $row['UnlistedCount'] = 0;
              }
              if (strlen($row['MasternodeLastSeen']) == 16) {
                $row['MasternodeLastSeen'] = substr($row['MasternodeLastSeen'],0,-6);
              }
              $nodes[] = $row;
            }
          }
          else {
            $nodes = false;
          }
        }
        else {
          $nodes = false;
        }
      }
      else {
        $nodes = false;
      }
    }
    else {
      $nodes = false;
    }
    if ($nodes !== false) {
      file_put_contents($cachefnam . ".new", serialize($nodes), LOCK_EX);
      rename($cachefnam . ".new", $cachefnam);
    }
    if (file_exists($cachefnamupdate)) {
      unlink($cachefnamupdate);
    }
  }

  return $nodes;
}

// Function to retrieve the masternode votes
function drkmn_masternodes_votes_get($mysqli, $mnips = array(), $testnet) {

  $cacheserial = sha1(serialize($mnips));
  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_votes_get_%d_%d_%s",$testnet,count($mnips),$cacheserial);
  $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
  if ($cachevalid) {
    $nodes = unserialize(file_get_contents($cachefnam));
  }
  else {
    $sqltestnet = sprintf("MNTestNet = %d",$testnet);

    // Retrieve the number of time each masternode is seen voting YEA
    $sqlvoteyea = "(SELECT MasternodeIP, MasternodePort, MNTestNet, COUNT(1) VoteYeaCount FROM cmd_info_masternode_votes"
                 ." WHERE $sqltestnet"
                 ." AND MasternodeVote = 'YEA'"
                 ." GROUP BY MasternodeIP, MasternodePort, MNTestNet) mnvoteyea";

    // Retrieve the number of time each masternode is seen voting NAY
    $sqlvotenay = "(SELECT MasternodeIP, MasternodePort, MNTestNet, COUNT(1) VoteNayCount FROM cmd_info_masternode_votes"
                 ." WHERE $sqltestnet"
                 ." AND MasternodeVote = 'NAY'"
                 ." GROUP BY MasternodeIP, MasternodePort, MNTestNet) mnvotenay";

    // Retrieve the number of time each masternode is seen voting ABSTAIN
    $sqlvoteabstain = "(SELECT MasternodeIP, MasternodePort, MNTestNet, COUNT(1) VoteAbstainCount FROM cmd_info_masternode_votes"
                     ." WHERE $sqltestnet"
                     ." AND MasternodeVote = 'ABSTAIN'"
                     ." GROUP BY MasternodeIP, MasternodePort, MNTestNet) mnvoteabstain";

    // Retrieve the result
    $sql = "SELECT inet_ntoa(cim.MasternodeIP) MasternodeIP, cim.MasternodePort MasternodePort, VoteYeaCount, VoteNayCount, VoteAbstainCount FROM cmd_info_masternode cim"
          ." LEFT JOIN $sqlvoteyea USING (MasternodeIP, MasternodePort, MNTestNet)"
          ." LEFT JOIN $sqlvotenay USING (MasternodeIP, MasternodePort, MNTestNet)"
          ." LEFT JOIN $sqlvoteabstain USING (MasternodeIP, MasternodePort, MNTestNet)"
          ." WHERE cim.$sqltestnet AND (VoteYeaCount>0 OR VoteNayCount>0 OR VoteAbstainCount>0)";

    // Add selection by IP:port
    if (count($mnips) > 0) {
      $sql .= " AND (";
      $sqls = '';
      foreach($mnips as $mnipstr) {
        $mnip = explode(':',$mnipstr);
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(cim.MasternodeIP = %d AND cim.MasternodePort = %d)",$mnip[0],$mnip[1]);
      }
      $sql .= $sqls.")";
    }
    $sql .= " ORDER BY MasternodeIP, MasternodePort";

    // Execute the query
    $numnodes = 0;
    if ($result = $mysqli->query($sql)) {
      $nodes = array();
      while($row = $result->fetch_assoc()){
        $numnodes++;
        if (is_null($row['VoteYeaCount'])) {
          $row['VoteYeaCount'] = 0;
        }
        else {
          $row['VoteYeaCount'] = intval($row['VoteYeaCount']);
        }
        if (is_null($row['VoteNayCount'])) {
          $row['VoteNayCount'] = 0;
        }
        else {
          $row['VoteNayCount'] = intval($row['VoteNayCount']);
        }
        if (is_null($row['VoteAbstainCount'])) {
          $row['VoteAbstainCount'] = 0;
        }
        else {
          $row['VoteAbstainCount'] = intval($row['VoteAbstainCount']);
        }
        $nodes[$row["MasternodeIP"].":".$row["MasternodePort"]] = array("VoteYeaCount" => $row['VoteYeaCount'],
                                                                        "VoteNayCount" => $row['VoteNayCount'],
                                                                        "VoteAbstainCount" => $row['VoteAbstainCount']);
      }
    }
    else {
      $nodes = false;
    }
    file_put_contents($cachefnam,serialize($nodes),LOCK_EX);
  }

  return $nodes;
}

// Function to retrieve the portcheck info
function drkmn_masternodes_portcheck_get($mysqli, $mnkeys, $testnet = 0) {

  $cacheserial = sha1(serialize($mnkeys));
  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_portcheck_get_%d_%d_%s",$testnet,count($mnkeys),$cacheserial);
  $cachefnamupdate = $cachefnam.".update";
  $cachevalid = (is_readable($cachefnam) && (((filemtime($cachefnam)+300)>=time()) || file_exists($cachefnamupdate)));
  if ($cachevalid) {
    $portcheck = unserialize(file_get_contents($cachefnam));
  }
  else {
    touch($cachefnamupdate);
    // Retrieve the portcheck info for the specific ip:ports
    $sql = sprintf("SELECT inet6_ntoa(NodeIP) NodeIP, NodePort, NodePortCheck, NodeSubVer, UNIX_TIMESTAMP(NextCheck) NextCheck, ErrorMessage, NodeCountry, NodeCountryCode FROM cmd_portcheck WHERE NodeTestNet = %d",$testnet);
    // Add the filtering to ip:ports (in $mnkeys parameter)
/*    if (count($mnkeys) > 0) {
      $sql .= " AND (";
      $sqls = '';
      foreach($mnkeys as $mnipstr) {
        $mnip = explode('-',$mnipstr);
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(NodeIP = INET6_ATON('%s') AND NodePort = %d)",$mnip[0],$mnip[1]);
      }
      $sql .= $sqls.")";
    }*/
    $sql .= " ORDER BY NodeIP, NodePort";

    // Run the query
    $numnodes = 0;
    if ($result = $mysqli->query($sql)) {
      $portcheck = array();
      // Group the result by masternode ip:port (status is per protocolversion and nodename)
      while($row = $result->fetch_assoc()){
        $portcheck[$row['NodeIP'].'-'.$row['NodePort']] = array("Result" => $row['NodePortCheck'],
                                                                "SubVer" => $row['NodeSubVer'],
                                                                "NextCheck" => $row['NextCheck'],
                                                                "ErrorMessage" => $row['ErrorMessage'],
                                                                "Country" => $row['NodeCountry'],
                                                                "CountryCode" => $row['NodeCountryCode']);
      }
    }
    else {
      $portcheck = false;
    }
    file_put_contents($cachefnam.".new",serialize($portcheck),LOCK_EX);
    rename($cachefnam.".new",$cachefnam);
    if (file_exists($cachefnamupdate)) {
      unlink($cachefnamupdate);
    }
  }

  return $portcheck;
}

// Function to retrieve the donation info
function drkmn_masternodes_donation_get($mysqli, $mnkeys, $testnet = 0) {

  $cacheserial = sha1(serialize($mnkeys));
  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_donation_get_%d_%d_%s",$testnet,count($mnkeys),$cacheserial);
  $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
  if ($cachevalid) {
    $donation = unserialize(file_get_contents($cachefnam));
  }
  else {
    // Retrieve the portcheck info for the specific ip:ports
    $sql = sprintf("SELECT inet_ntoa(MasternodeIP) MasternodeIP, MasternodePort, MNPubKey, MNDonationPercentage FROM cmd_info_masternode_donation WHERE MNTestNet = %d AND MNLastReported = 1",$testnet);
    // Add the filtering to ip:ports (in $mnkeys parameter)
    if (count($mnkeys) > 0) {
      $sql .= " AND (";
      $sqls = '';
      foreach($mnkeys as $mnipstr) {
        $mnip = explode(':',$mnipstr);
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(MasternodeIP = %d AND MasternodePort = %d)",$mnip[0],$mnip[1]);
      }
      $sql .= $sqls.")";
    }
    else {
      $sql .= " AND MNPubKey <> ''";
    }
    $sql .= " ORDER BY MasternodeIP, MasternodePort";

    // Run the query
    $numnodes = 0;
    if ($result = $mysqli->query($sql)) {
      $donation = array();
      while($row = $result->fetch_assoc()){
        $donation[$row['MasternodeIP'].':'.$row['MasternodePort']] = array("DonationPubKey" => $row['MNPubKey'],
                                                                           "DonationOccurence" => intval($row['MNDonationPercentage']));
      }
    }
    else {
      $donation = false;
    }
    file_put_contents($cachefnam,serialize($donation),LOCK_EX);
  }

  return $donation;
}

function drkmn_masternodes_donations_get($mysqli, $testnet = 0) {

  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_donations_get_%d",$testnet);
  $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
  if ($cachevalid) {
    $donation = unserialize(file_get_contents($cachefnam));
  }
  else {
    $sql = sprintf("SELECT MNPubKey, SUM(MNDonationPercentage) DonationOccurence, COUNT(CONCAT(MasternodeIP,MasternodePort)) DonationCount FROM cmd_info_masternode_donation WHERE MNTestNet = %d AND MNLastReported = 1 GROUP BY MNPubKey",$testnet);

    // Run the query
    $numnodes = 0;
    if ($result = $mysqli->query($sql)) {
      $donation = array();
      while($row = $result->fetch_assoc()){
        $donation[] = array("DonationPubKey" => $row['MNPubKey'],
                            "DonationPercentage" => intval($row['DonationOccurence']),
                            "DonationCount" => intval($row['DonationCount']));
        $numnodes += $row['DonationCount'];
      }
      $totaloccurence = $numnodes*100;
      foreach($donation as $id => $data) {
        $donation[$id]["DonationCountPercent"] = $data["DonationCount"]/$numnodes;
        $donation[$id]["DonationPercentageAverage"] = $data["DonationPercentage"]/$data["DonationCount"];
        $donation[$id]["DonationPaymentsOccurence"] = $data["DonationPercentage"]/$totaloccurence;
      }
    }
    else {
      $donation = false;
    }
    file_put_contents($cachefnam,serialize($donation),LOCK_EX);
  }

  return $donation;
}

// Function to retrieve the balance info
function drkmn_masternodes_balance_get($mysqli, $mnkeys, $testnet = 0) {

  $cacheserial = sha1(serialize($mnkeys));
  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_balance_get_%d_%d_%s",$testnet,count($mnkeys),$cacheserial);
  $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
  if ($cachevalid) {
    $balances = unserialize(file_get_contents($cachefnam));
  }
  else {
    // Retrieve the balance info for the specific pubkey
    $sql = sprintf("SELECT PubKey, Balance, UNIX_TIMESTAMP(LastUpdate) LastUpdate FROM cmd_info_masternode_balance WHERE TestNet = %d",$testnet);
    // Add the filtering to ip:ports (in $mnkeys parameter)
    if (count($mnkeys) > 0) {
      $sql .= " AND (";
      $sqls = '';
      foreach($mnkeys as $mnkey) {
        if (strlen($sqls)>0) {
          $sqls .= ' OR ';
        }
        $sqls .= sprintf("(PubKey = '%s')",$mysqli->real_escape_string($mnkey));
      }
      $sql .= $sqls.")";
    }
    $sql .= " ORDER BY PubKey";

    // Run the query
    $numnodes = 0;
    if ($result = $mysqli->query($sql)) {
      $balances = array();
      // Group the result by masternode ip:port (status is per protocolversion and nodename)
      while($row = $result->fetch_assoc()){
        $balances[$row['PubKey']] = array('Value' => $row['Balance'],
                                          'LastUpdate' => $row['LastUpdate']);
      }
    }
    else {
      $balances = false;
    }
    file_put_contents($cachefnam,serialize($balances),LOCK_EX);
  }

  return $balances;
}

function drkmn_masternodes_count($mysqli,$testnet,&$totalmncount,&$uniquemnips) {

  $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_count_%d",$testnet);
  $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+300)>=time()));
  if ($cachevalid) {
    $tmp = unserialize(file_get_contents($cachefnam));
    $mninfo = $tmp["mninfo"];
    $uniquemnips = $tmp["uniquemnips"];
    $totalmncount = $tmp["totalmncount"];
  }
  else {
    // Retrieve the total unique IPs per protocol version
/*    $sqlmnnum1 = sprintf("(SELECT first.Protocol Protocol, COUNT(1) UniqueActiveMasternodesIPs FROM "
                       ."(SELECT ciml.MasternodeIP MNIP, ciml.MasternodePort MNPort, cns.NodeProtocol Protocol, COUNT(1) ActiveCount FROM cmd_info_masternode_list ciml, cmd_nodes_status cns, cmd_nodes cmn WHERE"
                       ." ciml.NodeID = cns.NodeID AND ciml.NodeID = cmn.NodeID AND cmn.NodeEnabled = 1 AND ciml.MNTestNet = %d"
                       ." AND cns.NodeProcessStatus = 'running' AND (ciml.MasternodeStatus = 'active' OR ciml.MasternodeStatus = 'current')"
                       ." GROUP BY ciml.MasternodeIP, ciml.MasternodePort, cns.NodeProtocol) first GROUP BY first.Protocol) a",$testnet);
*/

    $sqlmnnum1 = sprintf("(SELECT first.Protocol Protocol, COUNT(1) UniqueActiveMasternodesIPs FROM "
                       ."(SELECT cim.MasternodeIP MNIP, cim.MasternodePort MNPort, cim.MasternodeProtocol Protocol, COUNT(1) ActiveCount"
                       ." FROM cmd_info_masternode2_list ciml, cmd_nodes_status cns, cmd_nodes cmn, cmd_info_masternode2 cim WHERE"
                       ." ciml.MasternodeOutputHash = cim.MasternodeOutputHash AND ciml.MasternodeOutputIndex = cim.MasternodeOutputIndex AND "
                       ." cns.NodeID AND ciml.NodeID = cmn.NodeID AND cmn.NodeEnabled = 1 AND ciml.MasternodeTestNet = %d AND "
                       ." ciml.NodeID = cns.NodeID AND ciml.NodeID = cmn.NodeID AND cmn.NodeEnabled = 1"
                       ." AND cns.NodeProcessStatus = 'running' AND (ciml.MasternodeStatus = 'active' OR ciml.MasternodeStatus = 'current')"
                       ." GROUP BY cim.MasternodeIP, cim.MasternodePort, cim.MasternodeProtocol) first GROUP BY first.Protocol) a",$testnet);


    // Retrieve the total masternodes per protocol version
/*    $sqlmnnum2 = sprintf("(SELECT second.Protocol Protocol, COUNT(1) ActiveMasternodesCount FROM "
                       ."(SELECT ciml.MasternodeIP MNIP, ciml.MasternodePort MNPort, cimpk.MNPubKey MNPubkey, cns.NodeProtocol Protocol, COUNT(1) ActiveCount FROM cmd_info_masternode_list ciml,"
                       ." cmd_info_masternode_pubkeys cimpk, cmd_nodes_status cns, cmd_nodes cmn WHERE"
                       ." ciml.MasternodeIP = cimpk.MasternodeIP AND ciml.MasternodePort = cimpk.MasternodePort AND ciml.MNTestNet = cimpk.MNTestNet AND cimpk.MNLastReported = 1 AND"
                       ." ciml.NodeID = cns.NodeID AND ciml.NodeID = cmn.NodeID AND cmn.NodeEnabled = 1 AND ciml.MNTestNet = %d AND cns.NodeProcessStatus = 'running' AND"
                       ." (ciml.MasternodeStatus = 'active' OR ciml.MasternodeStatus = 'current')"
                       ." GROUP BY ciml.MasternodeIP, ciml.MasternodePort, cimpk.MNPubKey, cns.NodeProtocol) second GROUP BY second.Protocol) b",$testnet);
*/
    $sqlmnnum2 = sprintf("(SELECT second.Protocol Protocol, COUNT(1) ActiveMasternodesCount FROM "
                       ."(SELECT cim.MasternodeIP MNIP, cim.MasternodePort MNPort, cim.MasternodeOutputHash MNOutHash, cim.MasternodeOutputIndex MNOutIndex,"
                       ." cim.MasternodeProtocol Protocol, COUNT(1) ActiveCount FROM cmd_info_masternode2_list ciml,"
                       ." cmd_info_masternode2 cim, cmd_nodes_status cns, cmd_nodes cmn WHERE"
                       ." ciml.MasternodeOutputHash = cim.MasternodeOutputHash AND ciml.MasternodeOutputIndex = cim.MasternodeOutputIndex AND ciml.MasternodeTestNet = cim.MasternodeTestNet AND"
                       ." ciml.NodeID = cns.NodeID AND ciml.NodeID = cmn.NodeID AND cmn.NodeEnabled = 1 AND ciml.MasternodeTestNet = %d AND cns.NodeProcessStatus = 'running' AND"
                       ." (ciml.MasternodeStatus = 'active' OR ciml.MasternodeStatus = 'current')"
                       ." GROUP BY cim.MasternodeIP, cim.MasternodePort, cim.MasternodeOutputHash, cim.MasternodeOutputIndex, cim.MasternodeProtocol) second GROUP BY second.Protocol) b",$testnet);

    $sqlmnnum = "SELECT a.Protocol, a.UniqueActiveMasternodesIPs UniqueActiveMasternodesIPs, b.ActiveMasternodesCount ActiveMasternodesCount FROM $sqlmnnum1, $sqlmnnum2 WHERE a.Protocol = b.Protocol";

    $totalmncount = 0;
//    $totalmncount = $sqlmnnum;
    $uniquemnips = 0;
    // Run the queries
    if ($result = $mysqli->query($sqlmnnum)) {
      $mninfo = array();
      $curprotocol = 0;
      // Group the result by masternode ip:port (status is per protocolversion and nodename)
      while($row = $result->fetch_assoc()){
        $mninfo[$row['Protocol']] = array("UniqueActiveMasternodesIPs" => $row['UniqueActiveMasternodesIPs'],
                                          "ActiveMasternodesCount" => $row['ActiveMasternodesCount']);
        if ($curprotocol < $row['Protocol']) {
          $curprotocol = $row['Protocol'];
        }
        $uniquemnips += $row['UniqueActiveMasternodesIPs'];
        $totalmncount += $row['ActiveMasternodesCount'];
      }
     }
    else {
      $mninfo = false;
    }
    $tmp = array("mninfo" => $mninfo, "uniquemnips" => $uniquemnips, "totalmncount" => $totalmncount);
    file_put_contents($cachefnam,serialize($tmp),LOCK_EX);
  }

  return $mninfo;

}

// Get masternodes status
// Parameters:
//   testnet=0|1
//   pubkeys=JSON encoded list of pubkeys
//   ips=JSON encoded list of ip:port
//   protocol=latest|integer (optional, then value=latest)
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

  // Retrieve the optional info parameters (status, balance and portcheck)
  $withbalance = ($request->hasQuery('balance') && ($request->getQuery('balance') == 1));
  $withportcheck = ($request->hasQuery('portcheck') && ($request->getQuery('portcheck') == 1));
  $withlastpaid = ($request->hasQuery('lastpaid') && ($request->getQuery('lastpaid') == 1));
  $withdonation = ($request->hasQuery('donation') && ($request->getQuery('donation') == 1));
  $withvotes = ($request->hasQuery('votes') && ($request->getQuery('votes') == 1));
  $prev12 = ($request->hasQuery('prev12') && ($request->getQuery('prev12') == 1));

  if (count($errmsg) > 0) {
    //Change the HTTP status
    $response->setStatusCode(400, "Bad Request");

    //Send errors to the client
    $response->setJsonContent(array('status' => 'ERROR', 'messages' => $errmsg));
  }
  elseif ($prev12) {
    // Retrieve masternodes list
    $nodes = drkmn_masternodes_get($mysqli, $testnet, $protocol, $mnpubkeys, $mnips, $withlastpaid);
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
        $portcheck = drkmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet);
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
        $balances = drkmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet);
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
        $donation = drkmn_masternodes_donation_get($mysqli, $mnipstrue, $testnet);
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
        $votes = drkmn_masternodes_votes_get($mysqli, $mnipstrue, $testnet);
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
    $nodes = drkmn_masternodes2_get($mysqli, $testnet, $protocol, $mnpubkeys, $mnips, $mnvins);
    if (is_array($nodes)) {
      // Generate the final list of IP:port (resulting from the query)
      $mnipstrue = array();
      $mnpubkeystrue = array();
      foreach($nodes as $node) {
        $tmpip = $node['MasternodeIP']."-".$node['MasternodePort'];
        if (!in_array($tmpip,$mnipstrue)) {
          $mnipstrue[] = $tmpip;
        }
        $mnpubkeystrue[] = $node['MasternodePubkey'];
      }

      // If we need the portcheck info, let's retrieve it
      if ($withportcheck) {
        $portcheck = drkmn_masternodes_portcheck_get($mysqli, $mnipstrue, $testnet);
        if ($portcheck === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        }
        else {
          foreach($nodes as $key => $node) {
            if (array_key_exists($node['MasternodeIP']."-".$node['MasternodePort'],$portcheck)) {
              $nodes[$key]['Portcheck'] = $portcheck[$node['MasternodeIP']."-".$node['MasternodePort']];
            }
            else {
              $nodes[$key]['Portcheck'] = false;
            }
          }
        }
      }

      // If we need the balance info, let's retrieve it
      if ($withbalance) {
        $balances = drkmn_masternodes_balance_get($mysqli, $mnpubkeystrue, $testnet);
        if ($balances === false) {
          $response->setStatusCode(503, "Service Unavailable");
          $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
        }
        else {
          foreach($nodes as $key => $node) {
            if (array_key_exists($node['MasternodePubkey'],$balances)) {
              $nodes[$key]['Balance'] = $balances[$node['MasternodePubkey']];
            }
            else {
              $nodes[$key]['Balance'] = false;
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

    $mndonationlist = drkmn_masternodes_donation_get($mysqli, array(), $testnet);

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

    $peraddress = drkmn_masternodes_donations_get($mysqli, $testnet);
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
    $votes = drkmn_masternodes_votes_get($mysqli, array(), $testnet);
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
        $supplyinfo[$row['Protocol']] = array("TotalMNValue" => $row['TotalMNValue'],
                                              "TotalSupply" => $row['TotalSupply'],
                                              "TotalMNPayed" => $row['TotalMNPayed'],
                                              "TotalBlocks" => $row['TotalBlocks'],
                                              "RatioPayed" => 0);
        $totalsupplyinfo['TotalMNValue'] += $row['TotalMNValue'];
        $totalsupplyinfo['TotalSupply'] += $row['TotalSupply'];
        $totalsupplyinfo['TotalMNPayed'] += $row['TotalMNPayed'];
        $totalsupplyinfo['TotalBlocks'] += $row['TotalBlocks'];
      }
      $totalsupplyinfo['RatioPayed'] = $totalsupplyinfo['TotalMNPayed'] / $totalsupplyinfo['TotalBlocks'];
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => array($mysqli->errno.': '.$mysqli->error)));
      return $response;
    }

    $totalmninfo = 0;
    $totaluniqueips = 0;
    $mninfo = drkmn_masternodes_count($mysqli,$testnet, $totalmninfo,$totaluniqueips);
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
        $finalinfo[$protocol]['ActiveMasternodesUniqueIPs'] = $mninfo[$protocol]['ActiveMasternodesCount'];
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
    // Retrieve all known nodes for current hub
    $sql = "SELECT * FROM cmd_stats_values";
    $numnodes = 0;
    $stats = array();
    if ($result = $mysqli->query($sql)) {
      while($row = $result->fetch_assoc()){
        $stats[$row["StatKey"]] = $row;
      }

      //Change the HTTP status
      $response->setStatusCode(200, "OK");
      $response->setJsonContent(array('status' => 'OK', 'data' => array("tablevars" => $stats)));
    }
    else {
      $response->setStatusCode(503, "Service Unavailable");
      $response->setJsonContent(array('status' => 'ERROR', 'messages' => $mysqli->errno.': '.$mysqli->error));
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
