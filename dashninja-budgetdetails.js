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

// Dash Ninja Front-End (dashninja-fe) - Budget Details
// By elberethzone / https://dashtalk.org/members/elbereth.175/

var dashninjaversion = '1.1.0';
var tableVotes = null;
var tableSuperBlocks = null;
var dashoutputregexp = /^[a-z0-9]{64}-[0-9]+$/;
var dashbudgetregexp = /^[abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ01234567890 .,;_\-/:?@()]+$/;
var budgetid = '';
var budgethash = '';
var latestblock = null;
var currentbudget = null;

$.fn.dataTable.ext.errMode = 'throw';

if (typeof dashninjatestnet === 'undefined') {
  var dashninjatestnet = 0;
}
if (typeof dashninjatestnethost !== 'undefined') {
  if (window.location.hostname == dashninjatestnethost) {
    dashninjatestnet = 1;
  }
}

if (typeof dashninjacoin === 'undefined') {
  var dashninjacoin = ['',''];
}
if (typeof dashninjaaddressexplorer === 'undefined') {
  var dashninjaaddressexplorer = [[],[]];
}
if (typeof dashninjaaddressexplorer[0] === 'undefined') {
  dashninjaaddressexplorer[0] = [];
}
if (typeof dashninjaaddressexplorer[1] === 'undefined') {
  dashninjaaddressexplorer[1] = [];
}

if (typeof dashninjamndetailvin === 'undefined') {
    var dashninjamndetailvin = [[],[]];
}
if (typeof dashninjamndetailvin[0] === 'undefined') {
    dashninjamndetailvin[0] = [];
}
if (typeof dashninjamndetailvin[1] === 'undefined') {
    dashninjamndetailvin[1] = [];
}

if (typeof dashninjaaddressexplorer === 'undefined') {
    var dashninjaaddressexplorer = [[],[]];
}
if (typeof dashninjaaddressexplorer[0] === 'undefined') {
    dashninjaaddressexplorer[0] = [];
}
if (typeof dashninjaaddressexplorer[1] === 'undefined') {
    dashninjaaddressexplorer[1] = [];
}

if (typeof dashninjatxexplorer === 'undefined') {
    var dashninjatxexplorer = [[],[]];
}
if (typeof dashninjatxexplorer[0] === 'undefined') {
    dashninjatxexplorer[0] = [];
}
if (typeof dashninjatxexplorer[1] === 'undefined') {
    dashninjatxexplorer[1] = [];
}

function budgetdetailsRefresh(useHash){
  console.log("DEBUG: budgetdetailsRefresh starting");
  $('#budgetinfoLR').html( '<i class="fa fa-spinner fa-pulse"></i> Refreshing <i class="fa fa-spinner fa-pulse"></i>' );
  var query = 'https://dashninja.pl/api/budgets?testnet='+dashninjatestnet;
  if (useHash) {
    query += '&budgethashes=["'+budgethash+'"]';
  }
  else {
    query += '&budgetids=["'+budgetid+'"]';
  }
    console.log("DEBUG: REST query="+query);
  $.getJSON( query, function( data ) {
   var date = new Date();
   var n = date.toDateString();
   var time = date.toLocaleTimeString();
   var result = "";

   console.log("DEBUG: REST api query responded!");

   if ((!data.hasOwnProperty("data")) || (!data.data.hasOwnProperty("budgets")) || (data.data.budgets == null)) {
       result = 'Unknown budget';
    $('#budgetid').text(result+" ("+budgetid+")");
    $('#budgethash').text(result+" ("+budgethash+")");
       $('#budgethash1').text("???");
       $('#budgethash2').text("???");
       $('#budgethash3').text("???");
       $('#budgethash4').text("???");
       $('#budgetfee').text(result);
       $('#budgeturl').text(result);
    $('#budgetblockstart').text(result);
    $('#budgetblockend').text(result);
    $('#budgetmonthlyamount').text(result);
    $('#budgettotalamount').text(result);
    $('#budgetremainingpayments').text(result);
    $('#budgettotalpayments').text(result);
    $('#budgetpubkey').text(result);
    $('#budgetstatus').text(result);
       $('#budgetlastpaid').text(result);
       $('#budgetyes').text(result);
       $('#budgetno').text(result);
       $('#budgetyesremaining').text(result);
       $('#budgetlastseen').text(result);
       $('#budgetfirstseen').text(result);
   }
   else {

       currentbudget = data.data.budgets[0];
       $('#budgetid').text( data.data.budgets[0].ID );
       $('#budgethash').text( data.data.budgets[0].Hash );
       $('#budgethash1').text( data.data.budgets[0].Hash );
       $('#budgethash2').text( data.data.budgets[0].Hash );
       $('#budgethash3').text( data.data.budgets[0].Hash );
       $('#budgethash4').text( data.data.budgets[0].Hash );

       var outtxt = "";
       if (dashninjatxexplorer[dashninjatestnet].length > 0) {
           var ix = 0;
           for ( var i=0, ien=dashninjatxexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
               if (ix == 0) {
                   outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][0][0].replace('%%a%%',data.data.budgets[0].FeeHash)+'">'+data.data.budgets[0].FeeHash+'</a>';
               }
               else {
                   outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][i][0].replace('%%a%%',data.data.budgets[0].FeeHash)+'">['+ix+']</a>';
               }
               ix++;
           }
       }
       else {
           outtxt = data.data.budgets[0].FeeHash;
       }
       $('#budgetfee').html( outtxt );

       var url = data.data.budgets[0].URL;
       if (url.indexOf("://") == -1) {
           url = "http://"+url;
       }
       $('#budgeturl').html( '<a href="'+url+'">'+data.data.budgets[0].URL+'</a>' );
       $('#budgetblockstart').text( data.data.budgets[0].BlockStart );
       $('#budgetblockend').text( data.data.budgets[0].BlockEnd );
       $('#budgetmonthlyamount').html( addCommas( data.data.budgets[0].MonthlyPayment.toFixed(3) )+' '+dashninjacoin[dashninjatestnet] + ' (<span id="budgetmonthlyamountusd">???</span> USD) (<span id="budgetmonthlyamounteur">???</span> EUR)');
       $('#budgettotalamount').html( addCommas( data.data.budgets[0].TotalPayment.toFixed(3) )+' '+dashninjacoin[dashninjatestnet] + ' (<span id="budgettotalamountusd">???</span> USD) (<span id="budgettotalamounteur">???</span> EUR)' );
       $('#budgettotalpayments').text( data.data.budgets[0].TotalPaymentCount );
       $('#budgetremainingpayments').text( data.data.budgets[0].RemainingPaymentCount );
       $('#budgetyes').text( data.data.budgets[0].Yeas );
       $('#budgetno').text( data.data.budgets[0].Nays );

       outtxt = "";
           if (dashninjaaddressexplorer[dashninjatestnet].length > 0) {
               var ix = 0;
               for ( var i=0, ien=dashninjaaddressexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                   if (ix == 0) {
                       outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][0][0].replace('%%a%%',data.data.budgets[0].PaymentAddress)+'">'+data.data.budgets[0].PaymentAddress+'</a>';
                   }
                   else {
                       outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][i][0].replace('%%a%%',data.data.budgets[0].PaymentAddress)+'">['+ix+']</a>';
                   }
                   ix++;
               }
           }
           else {
               outtxt = data.data.budgets[0].PaymentAddress;
           }
       $('#budgetpubkey').html( outtxt );

       var mnLimit = Math.floor(data.data.stats.totalmns * 0.1);
       var curPositive = data.data.budgets[0].Yeas - data.data.budgets[0].Nays;
       var cls = "danger";
       if (curPositive < mnLimit) {
           $('#budgetyesremaining').text( "Need "+(mnLimit-curPositive)+" YES votes" );
       }
       else {
           $('#budgetyesremaining').text( "Already exceed 10% by "+(curPositive-mnLimit)+" YES votes" );
           cls = "success";
       }
       $('#budgetyesremaining').removeClass("danger").removeClass("success").addClass(cls);

       var result = "";
       if (data.data.budgets[0].LastReported > 0) {
           result = deltaTimeStampHRlong(data.data.budgets[0].LastReported,currenttimestamp())+" ago";
       }
       else {
           result = 'Just now ('+data.data.budgets[0].LastReported+')';
       }
       var dateConv = new Date(data.data.budgets[0].LastReported*1000);
       $('#budgetlastseen').text( result+' ['+dateConv.toLocaleDateString()+' '+dateConv.toLocaleTimeString()+']' );
       $('#budgetlastseen2').text( dateConv.toLocaleString()+' ('+result+')' );
       var result = "";
       if (data.data.budgets[0].FirstReported > 0) {
           result = deltaTimeStampHRlong(data.data.budgets[0].FirstReported,currenttimestamp())+" ago";
       }
       else {
           result = 'Just now ('+data.data.budgets[0].FirstReported+')';
       }
       var dateConv = new Date(data.data.budgets[0].FirstReported*1000);
       $('#budgetfirstseen').text( result+' ['+dateConv.toLocaleDateString()+' '+dateConv.toLocaleTimeString()+']' );

       result = "";
       cls = "";
       if ((currenttimestamp() - data.data.budgets[0].LastReported) > 3600) {
           result = "Unlisted/Dropped";
           cls = "danger";
           $('#voteisover').show();
           $('#voteyes').hide();
           $('#voteno').hide();
       }
       else {
           if (data.data.budgets[0].IsEstablished) {
               if (data.data.budgets[0].IsValid) {
                   result = "Established and Valid";
                   cls = "success";
               }
               else {
                   result = "Invalid ("+data.data.budgets[0].IsValidReason+")";
                   cls = "warning";
               }
           }
           else {
               if (data.data.budgets[0].IsValid) {
                   result = "Valid (Waiting for 24 hours to be established)";
                   cls = "warning";
               }
               else {
                   result = "Invalid ("+data.data.budgets[0].IsValidReason+")";
                   cls = "danger";
               }
           }
           $('#voteisover').hide();
           $('#voteyes').show();
           $('#voteno').show();
       }
       $('#budgetstatus').text(result).removeClass("danger").removeClass("success").removeClass("warning").addClass(cls);;

       if (tableVotes !== null) {
           tableVotes.api().ajax.reload();
       }
       else {
           tableVotes = $('#votestable').dataTable({
               ajax: {
                   url: '/api/budgets/votes?testnet=' + dashninjatestnet + '&budgetid=' + budgetid + '&onlyvalid=1',
                   dataSrc: 'data.budgetsvotes'
               },
               lengthMenu: [[50, 100, 250, 500, -1], [50, 100, 250, 500, "All"]],
               pageLength: 50,
               order: [[0, "desc"]],
               columns: [
                   {
                       data: null, render: function (data, type, row) {
                       var date = new Date(data.VoteTime * 1000);
                       if (type == 'sort') {
                           return date;
                       }
                       else {
                           return date.toLocaleDateString() + " " + date.toLocaleTimeString();
                       }
                   }
                   },
                   {
                       data: null, render: function (data, type, row) {
                       var outtxt = '';
                       if (type != 'sort') {
                           if ((dashninjamndetailvin[dashninjatestnet].length > 0) || (dashninjatxexplorer[dashninjatestnet].length > 0)) {
                               var ix = 0;
                               for (var i = 0, ien = dashninjamndetailvin[dashninjatestnet].length; i < ien; i++) {
                                   if (ix == 0) {
                                       outtxt += '<a href="' + dashninjamndetailvin[dashninjatestnet][0][0].replace('%%a%%', data.MasternodeOutputHash + '-' + data.MasternodeOutputIndex) + '">' + data.MasternodeOutputHash + '-' + data.MasternodeOutputIndex + '</a>';
                                   }
                                   else {
                                       outtxt += '<a href="' + dashninjamndetailvin[dashninjatestnet][i][0].replace('%%a%%', data.MasternodeOutputHash + '-' + data.MasternodeOutputIndex) + '">[' + ix + ']</a>';
                                   }
                                   ix++;
                               }
                               for (var i = 0, ien = dashninjatxexplorer[dashninjatestnet].length; i < ien; i++) {
                                   if (ix == 0) {
                                       outtxt += '<a href="' + dashninjatxexplorer[dashninjatestnet][0][0].replace('%%a%%', data.MasternodeOutputHash) + '">' + data.MasternodeOutputHash + '-' + data.MasternodeOutputIndex + '</a>';
                                   }
                                   else {
                                       outtxt += '<a href="' + dashninjatxexplorer[dashninjatestnet][i][0].replace('%%a%%', data.MasternodeOutputHash) + '">[' + ix + ']</a>';
                                   }
                                   ix++;
                               }
                           }
                           else {
                               outtxt = data.MasternodeOutputHash + '-' + data.MasternodeOutputIndex;
                           }
                       }
                       else {
                           outtxt = data.MasternodeOutputHash + '-' + data.MasternodeOutputIndex;
                       }
                       return outtxt;
                   }
                   },
                   {data: "VoteValue"},
                   {
                       data: null, render: function (data, type, row) {
                       if (type == 'sort') {
                           return data.VoteHash;
                       }
                       else {
                           return '<span data-toggle="tooltip" title="' + data.VoteHash + '">' + data.VoteHash.substring(0, 7) + '</span>';
                       }
                   }
                   }
               ],
               createdRow: function (row, data, index) {
                   if (data.VoteValue == "YES") {
                       $('td', row).eq(2).css({"background-color": "#d6e9c6", "color": "#3c763d"});
                   }
                   else {
                       $('td', row).eq(2).css({"background-color": "#f2dede", "color": "#a94442"});
                   }
               }
           });
       }

       $('#budgetlastpaid').html( '<i class="fa fa-spinner fa-pulse"></i> Calculating... <i class="fa fa-spinner fa-pulse"></i>' );
       if (tableSuperBlocks !== null) {
           tableSuperBlocks.api().ajax.reload();
       }
       else {
           tableSuperBlocks = $('#superblockstable').dataTable({
               ajax: {
                   url: '/api/blocks?testnet=' + dashninjatestnet + '&budgetids=["' + budgetid + '"]&onlysuperblocks=1',
                   dataSrc: 'data.blocks'
               },
               lengthMenu: [[50, 100, 250, 500, -1], [50, 100, 250, 500, "All"]],
               pageLength: 50,
               order: [[0, "desc"]],
               columns: [
                   {
                       data: null, render: function (data, type, row) {
                       if (type == 'sort') {
                           return data.BlockTime;
                       }
                       else {
//                return deltaTimeStampHR(currenttimestamp(),data.BlockTime);
                           return timeSince((currenttimestamp() - data.BlockTime));
                       }

                   }
                   },
                   {
                       data: null, render: function (data, type, row) {
                       var outtxt = data.BlockId;
                       if (type != 'sort') {
                           if (dashninjablockexplorer[dashninjatestnet].length > 0) {
                               outtxt = '<a href="' + dashninjablockexplorer[dashninjatestnet][0][0].replace('%%b%%', data.BlockHash) + '">' + data.BlockId + '</a>';
                           }
                       }
                       return outtxt;
                   }
                   },
                   {
                       data: null, render: function (data, type, row) {
                       var outtxt = data.BlockPoolPubKey;
                       if (data.PoolDescription) {
                           outtxt = data.PoolDescription;
                       }
                       return outtxt;
                   }
                   },
                   {data: "BlockDifficulty"},
                   {
                       data: null, render: function (data, type, row) {
                       if (type == "sort") {
                           return data.BlockMNValue;
                       } else {
                           return data.BlockMNValue + " " + dashninjacoin[dashninjatestnet];
                       }
                   }
                   }
               ],
               createdRow: function (row, data, index) {
               }
           });
       }
  }

   $('#budgetinfoLR').text( date.toLocaleString() );
      refreshFiatValues();
   console.log("DEBUG: auto-refresh starting");
   setTimeout(budgetdetailsRefresh, 300000);
  });
};

function refreshFiatValues() {

    if (currentbudget !== null) {
        $('#fiatDASHBTCval').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatDASHBTCwho').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatDASHBTCwhen').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatUSDBTCval').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatUSDBTCwho').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatUSDBTCwhen').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatEURBTCval').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatEURBTCwho').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#fiatEURBTCwhen').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#budgetmonthlyamountusd').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#budgetmonthlyamounteur').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#budgettotalamountusd').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        $('#budgettotalamounteur').html( '<i class="fa fa-spinner fa-pulse"></i>' );
        var query = 'https://dashninja.pl/api/tablevars';
        $.getJSON( query, function( data ) {
            console.log("DEBUG: REST api /tablevars query reply!");
            if ((!data.hasOwnProperty("data")) || (!data.data.hasOwnProperty("tablevars")) || (data.data.tablevars === null)
            || (!data.data.tablevars.hasOwnProperty("btcdrk")) || (!data.data.tablevars.hasOwnProperty("eurobtc"))
            || (!data.data.tablevars.hasOwnProperty("usdbtc"))) {
                $('#fiatDASHBTCval').text( '???' );
                $('#fiatDASHBTCwho').text( '???' );
                $('#fiatDASHBTCwhen').text( '???' );
                $('#fiatUSDBTCval').text( '???' );
                $('#fiatUSDBTCwho').text( '???' );
                $('#fiatUSDBTCwhen').text( '???' );
                $('#fiatEURBTCval').text( '???' );
                $('#fiatEURBTCwho').text( '???' );
                $('#fiatEURBTCwhen').text( '???' );
                $('#budgetmonthlyamountusd').text( '???' );
                $('#budgetmonthlyamounteur').text( '???' );
                $('#budgettotalamountusd').text( '???' );
                $('#budgettotalamounteur').text( '???' );
            }
            else {
                $('#fiatDASHBTCval').text( data.data.tablevars.btcdrk.StatValue );
                $('#fiatDASHBTCwho').text( data.data.tablevars.btcdrk.Source );
                var tmpDate = new Date(parseInt(data.data.tablevars.btcdrk.LastUpdate)*1000);
                $('#fiatDASHBTCwhen').text( tmpDate.toLocaleString() );
                $('#fiatUSDBTCval').text( data.data.tablevars.usdbtc.StatValue );
                $('#fiatUSDBTCwho').text( data.data.tablevars.usdbtc.Source );
                tmpDate = new Date(parseInt(data.data.tablevars.usdbtc.LastUpdate)*1000);
                $('#fiatUSDBTCwhen').text( tmpDate.toLocaleString() );
                $('#fiatEURBTCval').text( data.data.tablevars.eurobtc.StatValue );
                $('#fiatEURBTCwho').text( data.data.tablevars.eurobtc.Source );
                tmpDate = new Date(parseInt(data.data.tablevars.eurobtc.LastUpdate)*1000);
                $('#fiatEURBTCwhen').text( tmpDate.toLocaleString() );

                var valBTC = currentbudget.MonthlyPayment * parseFloat(data.data.tablevars.btcdrk.StatValue);
                var valUSD = valBTC * parseFloat(data.data.tablevars.usdbtc.StatValue);
                var valEUR = valBTC * parseFloat(data.data.tablevars.eurobtc.StatValue);
                $('#budgetmonthlyamountusd').text( addCommas(valUSD.toFixed(2)) );
                $('#budgetmonthlyamounteur').text( addCommas(valEUR.toFixed(2)) );

                valBTC = currentbudget.TotalPayment * parseFloat(data.data.tablevars.btcdrk.StatValue);
                valUSD = valBTC * parseFloat(data.data.tablevars.usdbtc.StatValue);
                valEUR = valBTC * parseFloat(data.data.tablevars.eurobtc.StatValue);
                $('#budgettotalamountusd').text( addCommas(valUSD.toFixed(2)) );
                $('#budgettotalamounteur').text( addCommas(valEUR.toFixed(2)) );

            }
        });
    }

}

$(document).ready(function(){

  $('#dashninjajsversion').text( dashninjaversion );

  if (dashninjatestnet == 1) {
      $('#testnetalert').show();
  }

  budgetid = getParameter("budgetid");
  console.log("DEBUG: budgetid="+budgetid);
  budgethash = getParameter("budgethash");
  console.log("DEBUG: budgethash="+budgethash);

  if ((budgetid == "") && (budgethash == "")) {
      budgetid = 'Need "budgetid" parameter';
      $('#budgetid').text(mnpubkey);
      budgethash = 'Need "budgethash" parameter';
      $('#budgethash').text(mnvin);
  }
  else {
    if ((budgetid != "") && (budgethash == "")) {
      if (!dashbudgetregexp.test(budgetid)) {
          budgetid = 'Invalid';
          $('#budgetid').text(budgetid);
      }
      else {
          budgetdetailsRefresh(false);
      }
    }
    else {
      if (!dashoutputregexp.test(budgethash)) {
          budgethash = 'Invalid';
          $('#budgethash').text( budgethash );
      }
      else {
          budgetdetailsRefresh(true);
      }
    }
  }

  $('#votestable').on('xhr.dt', function ( e, settings, json ) {
        // Change the last refresh date
        var date = new Date();
        $('#votestableLR').text( date.toLocaleString() );
      } );

    $('#superblockstable').on('xhr.dt', function ( e, settings, json ) {
        latestblock = {BlockTime: 0, BlockId: -1};
        // Fill per version stats table
        for (var blockid in json.data.blocks){
            if(!json.data.blocks.hasOwnProperty(blockid)) {continue;}
            if (json.data.blocks[blockid].BlockTime > latestblock.BlockTime) {
                latestblock = json.data.blocks[blockid];
            }
        }

        var outtxt = "";
        var cls = "danger";
        if (latestblock.BlockId == -1) {
            outtxt = "Never";
        }
        else {
            if (dashninjablockexplorer[dashninjatestnet].length > 0) {
                outtxt = 'Block <a href="' + dashninjablockexplorer[dashninjatestnet][0][0].replace('%%b%%', latestblock.BlockHash) + '">' + latestblock.BlockId + '</a>';
            }
            var tmpDate = new Date(latestblock.BlockTime * 1000);
            outtxt += " on " + tmpDate.toLocaleString() + " (" + timeSince((currenttimestamp() - latestblock.BlockTime)) + ")";
            cls = "success";
        }

        $('#budgetlastpaid').html( outtxt ).removeClass("danger").removeClass("success").addClass(cls);

        // Change the last refresh date
        var date = new Date();
        $('#superblockstableLR').text( date.toLocaleString() );
    } );

});
