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

define("DASHNINJA_BEV","2.0");

// Function to retrieve the masternode list
function dmn_masternodes_get($mysqli, $testnet = 0, $protocol = 0, $mnpubkeys = array(), $mnips = array(), $withlastpaid = false) {

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
function dmn_masternodes2_get($mysqli, $testnet = 0, $protocol = 0, $mnpubkeys = array(), $mnips = array(), $mnvins = array()) {

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
    cim.MasternodeTor MasternodeTor,
    cim.MasternodePort MasternodePort,
    cim.MasternodePubkey MasternodePubkey,
    MasternodeProtocol,
    MasternodeLastSeen,
    MasternodeActiveSeconds,
    MasternodeLastPaid,
    ActiveCount,
    InactiveCount,
    UnlistedCount,
    cimlp.MNLastPaidBlock MasternodeLastPaidBlockHeight,
    cib.BlockTime MasternodeLastPaidBlockTime,
    cib.BlockMNValue MasternodeLastPaidBlockAmount
FROM
    (cmd_info_masternode2 cim,
    _node_status)
    LEFT JOIN
        cmd_info_masternode_lastpaid cimlp
            ON (cimlp.MNTestNet = cim.MasternodeTestNet AND cimlp.MNPubKey = cim.MasternodePubkey)
    LEFT JOIN
        cmd_info_blocks cib
            ON (cib.BlockTestNet = cimlp.MNTestNet AND cib.BlockId = cimlp.MNLastPaidBlock)
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
                            else {
                                $row['ActiveCount'] = intval($row['ActiveCount']);
                            }
                            if (is_null($row['InactiveCount'])) {
                                $row['InactiveCount'] = 0;
                            }
                            else {
                                $row['InactiveCount'] = intval($row['InactiveCount']);
                            }
                            if (is_null($row['UnlistedCount'])) {
                                $row['UnlistedCount'] = 0;
                            }
                            else {
                                $row['UnlistedCount'] = intval($row['UnlistedCount']);
                            }
                            if (strlen($row['MasternodeLastSeen']) == 16) {
                                $row['MasternodeLastSeen'] = substr($row['MasternodeLastSeen'],0,-6);
                            }
                            if (!is_null($row['MasternodeLastPaidBlockHeight'])) {
                                $row['LastPaidFromBlocks'] = array("MNLastPaidBlock" => $row['MasternodeLastPaidBlockHeight'],
                                    "MNLastPaidTime" => $row['MasternodeLastPaidBlockTime'],
                                    "MNLastPaidAmount" => $row['MasternodeLastPaidBlockAmount']);
                            }
                            else {
                                $row['LastPaidFromBlocks'] = false;
                            }
                            unset($row['MasternodeLastPaidBlockHeight'],$row['MasternodeLastPaidBlockTime'],$row['MasternodeLastPaidBlockAmount']);
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
function dmn_masternodes_votes_get($mysqli, $mnips = array(), $testnet) {

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
function dmn_masternodes_portcheck_get($mysqli, $mnkeys, $testnet = 0) {

//    $cacheserial = sha1(serialize($mnkeys));
//    $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_portcheck_get_%d_%d_%s",$testnet,count($mnkeys),$cacheserial);
    $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_portcheck_get_%d",$testnet);
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
/*        if (count($mnkeys) > 0) {
            $sql .= " AND (";
            $sqls = '';
            foreach($mnkeys as $mnipstr) {
                $mnip = explode(':',$mnipstr);
                if (strlen($sqls)>0) {
                    $sqls .= ' OR ';
                }
                $sqls .= sprintf("(NodeIP = %d AND NodePort = %d)",$mnip[0],$mnip[1]);
            }
            $sql .= $sqls.")";
        }
        $sql .= " ORDER BY NodeIP, NodePort";
*/
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
function dmn_masternodes_donation_get($mysqli, $mnkeys, $testnet = 0) {

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

function dmn_masternodes_donations_get($mysqli, $testnet = 0) {

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
function dmn_masternodes_balance_get($mysqli, $mnkeys, $testnet = 0) {

    // Only add a selection is there is less than 100 keys, it will just make the query slower and not use the cache otherwise
    if (count($mnkeys) > 100) {
        $mnkeys = array();
    }
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

// Function to retrieve the extended status info
function dmn_masternodes_exstatus_get($mysqli, $mnkeys, $testnet = 0) {

    $cacheserial = sha1(serialize($mnkeys));
    $cachefnam = CACHEFOLDER.sprintf("dashninja_masternodes_exstatus_get_%d_%d_%s",$testnet,count($mnkeys),$cacheserial);
    $cachevalid = (is_readable($cachefnam) && ((filemtime($cachefnam)+120)>=time()));
    if ($cachevalid) {
        $exstatus = unserialize(file_get_contents($cachefnam));
    }
    else {
        // Retrieve the extended status info for the specific pubkey
        $sql = sprintf("SELECT MasternodeOutputHash, MasternodeOutputIndex, NodeName, NodeVersion, NodeProtocol, MasternodeStatus, MasternodeStatusEx FROM cmd_info_masternode2_list cim2l LEFT OUTER JOIN cmd_nodes cn ON cn.NodeID = cim2l.NodeID LEFT OUTER JOIN cmd_nodes_status cns ON cn.NodeID = cns.NodeID WHERE MasternodeTestNet = %d AND cn.NodeEnabled = 1",$testnet);
        // Add the filtering to masternode output hash and index (in $mnkeys parameter)
        if (count($mnkeys) > 0) {
            $sql .= " AND (";
            $sqls = '';
            foreach($mnkeys as $mnkey) {
                if (strlen($sqls)>0) {
                    $sqls .= ' OR ';
                }
                list($mnhash,$mnindex) = explode("-",$mnkey);
                $sqls .= sprintf("(MasternodeOutputHash = '%s' AND MasternodeOutputIndex = %d)",$mysqli->real_escape_string($mnhash),intval($mnindex));
            }
            $sql .= $sqls.")";
        }
        $sql .= " ORDER BY MasternodeOutputHash, MasternodeOutputIndex, NodeName";

        // Run the query
        if ($result = $mysqli->query($sql)) {
            $exstatus = array();
            // Group the result by masternode output hash-index
            while($row = $result->fetch_assoc()){
                $exstatus[$row['MasternodeOutputHash']."-".$row['MasternodeOutputIndex']][] = array('NodeName' => $row['NodeName'],
                    'NodeVersion' => intval($row['NodeVersion']),
                    'NodeProtocol' => intval($row['NodeProtocol']),
                    'Status' => $row['MasternodeStatus'],
                    'StatusEx' => $row['MasternodeStatusEx']
                );
            }
        }
        else {
            $exstatus = false;
        }
        file_put_contents($cachefnam,serialize($exstatus),LOCK_EX);
    }

    return $exstatus;
}

// Function to retrieve the masternode count
function dmn_masternodes_count($mysqli, $testnet, &$totalmncount, &$uniquemnips) {

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

?>