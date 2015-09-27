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

// Dash Ninja Front-End (dashninja-fe) - Masternode Detail
// By elberethzone / https://dashtalk.org/members/elbereth.175/

var dashninjaversion = '2.2.4';
var tablePayments = null;
var dataProtocolDesc = [];
var maxProtocol = -1;
var dashmainkeyregexp = /^[7X][a-zA-Z0-9]{33}$/;
var dashtestkeyregexp = /^[yx][a-zA-Z0-9]{33}$/;
var dashoutputregexp = /^[a-z0-9]{64}-[0-9]+$/;
var mnpubkey = '';
var mnvin = '';

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

function tablePaymentsRefresh(){
  tablePayments.api().ajax.reload();
  // Set it to refresh in 60sec
  setTimeout(tablePaymentsRefresh, 150000);
};

function mndetailsRefresh(useVin){
  console.log("DEBUG: mndetailsRefresh starting");
  $('#mninfosLR').html( '<i class="fa fa-spinner fa-pulse"></i> Refreshing <i class="fa fa-spinner fa-pulse"></i>' );
  var query = 'https://dashninja.pl/api/masternodes?balance=1&portcheck=1&lastpaid=1&testnet='+dashninjatestnet;
  if (useVin) {
    query += '&vins=["'+mnvin+'"]';
  }
  else {
    query += '&pubkeys=["'+mnpubkey+'"]';
  }
    console.log("DEBUG: REST query="+query);
  $.getJSON( query, function( data ) {
   var date = new Date();
   var n = date.toDateString();
   var time = date.toLocaleTimeString();
   var result = "";

   console.log("DEBUG: REST api query responded!");

   if ((!data.hasOwnProperty("data")) || (data.data.length < 1)) {
    result = 'Unknown masternode';
    $('#mnoutput').text(result+" ("+mnvin+")");
    $('#mnpubkey').text(result+" ("+mnpubkey+")");      
    $('#mnipport').text(result);      
    $('#mncountry').text(result);      
    $('#mnstatus').text(result).removeClass("danger").removeClass("warning").removeClass("success");
    $('#mnactiveduration').text(result);
    $('#mnlastseen').text(result);
    $('#mnbalance').text(result).removeClass("danger").removeClass("success");
    $('#mnlastpaid').text(result);
    $('#mnportcheck').text(result).removeClass("danger").removeClass("info").removeClass("success");
    $('#mnportchecknext').text(result);
    $('#mnversion').text(result);
   }
   else {

    $('#mnoutput').text( data.data[0].MasternodeOutputHash+"-"+data.data[0].MasternodeOutputIndex );
    $('#mnpubkey').text( data.data[0].MasternodePubkey );
    $('#mnipport').text( data.data[0].MasternodeIP+":"+data.data[0].MasternodePort );
    mnpubkey = data.data[0].MasternodePubkey;

    var activecount = parseInt(data.data[0].ActiveCount);
    var inactivecount = parseInt(data.data[0].InactiveCount);
    var unlistedcount = parseInt(data.data[0].UnlistedCount);
    var total = activecount+inactivecount+unlistedcount;
    var ratio = activecount / total;
    result = ratio;
    var cls = "";
    if ( ratio == 1 ) {
      result = 'Active';
      cls = "success";
    } else if ( ratio == 0 ) {
      result = 'Inactive';
      cls = "danger";
    } else if ( unlistedcount > 0 ) {
      result = 'Partially Unlisted';
      cls = "warning";
    } else {
      result = 'Partially Inactive';
      cls = "warning";
    }
    result += ' ('+Math.round(ratio*100)+'%)';
    $('#mnstatus').text(result).removeClass("danger").removeClass("warning").removeClass("success").addClass(cls);
    if (data.data[0].MasternodeActiveSeconds < 0) {
      result = 'Inactive';
    }
    else {
      result = diffHRlong(data.data[0].MasternodeActiveSeconds);
    }
    $('#mnactiveduration').text ( result);
    if (data.data[0].MasternodeLastSeen > 0) {
        var tmpDate = new Date(data.data[0].MasternodeLastSeen*1000);
        result = tmpDate.toLocaleString()+" ("+deltaTimeStampHRlong(data.data[0].MasternodeLastSeen,currenttimestamp())+" ago)";
    }
    else {
      result = 'Just now ('+data.data[0].MasternodeLastSeen+')';
    }
    $('#mnlastseen').text ( result);

    // Balance data
    var num = Math.round( data.data[0].Balance.Value * 1000 ) / 1000;
    if ( num < 1000 ) {
      cls = "danger";
    } else {
      cls = "success";
    }
    $('#mnbalance').text ( addCommas( num.toFixed(3) )+' '+dashninjacoin[dashninjatestnet]).removeClass("danger").removeClass("success").addClass(cls);

    // Last Paid data
    var outtxt = "";
    if (data.data[0].MasternodeLastPaid != 0) {
        var tmpDate = new Date(data.data[0].MasternodeLastPaid*1000);
      outtxt = tmpDate.toLocaleString()+" ("+deltaTimeStampHRlong(parseInt(data.data[0].MasternodeLastPaid),currenttimestamp())+" ago)";
    }
    else {
      outtxt = 'Never/Unknown';
    }
    $('#mnlastpaid').html( outtxt );

    // Last Paid from blocks data
    var outtxt = "";
    if (data.data[0].LastPaidFromBlocks !== false) {
      var tmpDate = new Date(data.data[0].LastPaidFromBlocks.MNLastPaidTime*1000);
      outtxt = tmpDate.toLocaleString()+" ("+deltaTimeStampHRlong(parseInt(data.data[0].LastPaidFromBlocks.MNLastPaidTime),currenttimestamp())+" ago) on block ";
      if (dashninjaqueryexplorer[dashninjatestnet].length > 0) {
        outtxt += '<a href="'+dashninjaqueryexplorer[dashninjatestnet][0][0].replace('%%q%%',data.data[0].LastPaidFromBlocks.MNLastPaidBlock)+'">'+data.data[0].LastPaidFromBlocks.MNLastPaidBlock+'</a>';
      }
      else {
        outtxt += data.data[0].LastPaidFromBlocks.MNLastPaidBlock;
      }
    }
    else {
      outtxt = 'Never/Unknown';
    }
    $('#mnlastpaidfromblocks').html( outtxt );

    cls = "danger";
    if (Math.abs(parseInt(data.data[0].MasternodeLastPaid)-parseInt(data.data[0].LastPaidFromBlocks.MNLastPaidTime)) < 120) {
      cls = "success";
    }
    $('#mnlastpaid').removeClass("success").removeClass("danger").addClass(cls);
    $('#mnlastpaidfromblocks').removeClass("success").removeClass("danger").addClass(cls);

    // Port Check data
    $('#mncountry').html( '<img src="/static/flags/flags_iso/16/'+data.data[0].Portcheck.CountryCode+'.png" width=16 height=16 /> '+data.data[0].Portcheck.Country );
    var txt = data.data[0].Portcheck.Result;
    cls = "";
    if ((data.data[0].Portcheck.Result == 'closed') || (data.data[0].Portcheck.Result == 'timeout')) {
      txt = "Closed ("+data.data[0].Portcheck.ErrorMessage+")";
      cls = "danger";
    } else if (data.data[0].Portcheck.Result == 'unknown') {
      txt = "Pending";
      cls = "info";
    } else if ((data.data[0].Portcheck.Result == 'open') || (data.data[0].Portcheck.Result == 'rogue')) {
      txt = "Open";
      cls = "success";
    }
    $('#mnportcheck').text(txt).removeClass("danger").removeClass("info").removeClass("success").addClass(cls);
    if (data.data[0].Portcheck.NextCheck < currenttimestamp()) {
      if (txt != "Pending") {
        $('#mnportchecknext').text('Re-check pending');
      }
    }
    else {
      $('#mnportchecknext').text(deltaTimeStampHRlong(data.data[0].Portcheck.NextCheck,currenttimestamp()));
    }
    var versioninfo = '<i>Unknown</i>';
    if ((data.data[0].Portcheck.SubVer.length > 10) && (data.data[0].Portcheck.SubVer.substring(0,9) == '/Satoshi:') && (data.data[0].Portcheck.SubVer.substring(data.data[0].Portcheck.SubVer.length-1) == '/')) {
      versioninfo = data.data[0].Portcheck.SubVer.substring(9,data.data[0].Portcheck.SubVer.indexOf('/',10));
    }
    else if ((data.data[0].Portcheck.SubVer.length > 7) && (data.data[0].Portcheck.SubVer.substring(0,6) == '/Core:') && (data.data[0].Portcheck.SubVer.substring(data.data[0].Portcheck.SubVer.length-1) == '/')) {
      versioninfo = data.data[0].Portcheck.SubVer.substring(6,data.data[0].Portcheck.SubVer.indexOf('/',6));
    }
    else if ((data.data[0].Portcheck.SubVer.length > 11) && (data.data[0].Portcheck.SubVer.substring(0,11) == '/Dash Core:') && (data.data[0].Portcheck.SubVer.substring(data.data[0].Portcheck.SubVer.length-1) == '/')) {
      versioninfo = data.data[0].Portcheck.SubVer.substring(11,data.data[0].Portcheck.SubVer.indexOf('/',11));
    }
    $('#mnversion').html( versioninfo+" (Protocol="+data.data[0].MasternodeProtocol+")" );
   }
   $('#mninfosLR').text( n + ' ' + time );
   tablePayments = $('#paymentstable').dataTable( {
        ajax: { url: '/api/blocks?testnet='+dashninjatestnet+'&pubkeys=["'+data.data[0].MasternodePubkey+'"]&interval=P1M',
                dataSrc: 'data.blocks' },
        paging: false,
        order: [[ 0, "desc" ]],
        columns: [
            { data: null, render: function ( data, type, row ) {
              if (type == 'sort') {
                return data.BlockTime;
              }
              else {
                var date = new Date(data.BlockTime*1000);
                var day = "0"+date.getDate();
                var month = "0"+(date.getMonth()+1);
                var year = date.getFullYear();
                var hours = "0"+date.getHours();
                var minutes = "0" + date.getMinutes();
                var seconds = "0" + date.getSeconds();
                var formattedTime = hours.substr(hours.length-2) + ':' + minutes.substr(minutes.length-2) + ':' + seconds.substr(seconds.length-2);
                return date.getFullYear()+"-"+month.substr(month.length-2)+"-"+day.substr(day.length-2)+" "+formattedTime;
              }

            } },
            { data: null, render: function ( data, type, row ) {
               var outtxt = data.BlockId;
               if (type != 'sort') {
                 if (dashninjablockexplorer[dashninjatestnet].length > 0) {
                   outtxt = '<a href="'+dashninjablockexplorer[dashninjatestnet][0][0].replace('%%b%%',data.BlockHash)+'">'+data.BlockId+'</a>';
                 }
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row ) {
               var outtxt = data.BlockPoolPubKey;
               if (data.PoolDescription) {
                 outtxt = data.PoolDescription;
               }
               return outtxt;
            } },
            { data: "BlockMNValue" },
            { data: null, render: function ( data, type, row ) {
               return (Math.round(data.BlockMNValueRatioExpected*1000)/10).toFixed(1)+"%/"+(Math.round(data.BlockMNValueRatio*1000)/10).toFixed(1)+"%";
            } },
            { data: null, render: function ( data, type, row ) {
               if ((type != "sort") && (data.BlockMNPayeeExpected == "")) {
                 return "<i>Unknown</i>";
               } else if (type == "sort") {
                 return data.BlockMNPayeeExpected;
               } else {
                 return '<a href="'+dashninjamasternodemonitoring[dashninjatestnet].replace('%%p%%',data.BlockMNPayeeExpected)+'">'+data.BlockMNPayeeExpected+'</a>';;
               }
            } },
            { data: null, render: function ( data, type, row ) {
               if ((type != "sort") && (data.BlockMNPayee == "")) {
                 return "<i>Unpaid block</i>";
               } else if (type == "sort") {
                 return data.BlockMNPayee;
               } else {
                 return '<a href="'+dashninjamasternodemonitoring[dashninjatestnet].replace('%%p%%',data.BlockMNPayee)+'">'+data.BlockMNPayee+'</a>';;
               }
            } }
        ],
        createdRow: function ( row, data, index ) {
          if (data.BlockMNPayeeExpected == mnpubkey) {
            $('td',row).eq(5).css({"background-color": "#8FFF8F"});
          }
          else {
            $('td',row).eq(5).css({"background-color": "#FF8F8F"});
          }
          if (data.BlockMNPayee == mnpubkey) {
            $('td',row).eq(6).css({"background-color": "#8FFF8F"});
          }
          else {
            $('td',row).eq(6).css({"background-color": "#FF8F8F"});
          }
        }
   } );
   console.log("DEBUG: auto-refresh starting");
   setTimeout(mndetailsRefresh, 300000);
  });
};

$(document).ready(function(){

  $('#dashninjajsversion').text( dashninjaversion );

  if (dashninjatestnet == 1) {
    $('#testnetalert').show();
  }

  mnpubkey = getParameter("mnpubkey");
  console.log("DEBUG: mnpubkey="+mnpubkey);
  mnvin = getParameter("mnoutput");
  console.log("DEBUG: mnvin="+mnvin);

  if ((mnpubkey == "") && (mnvin == "")) {
    mnpubkey = 'Need "mnpubkey" parameter';
    $('#mnpubkey').text(mnpubkey);
    mnvin = 'Need "mnoutput" parameter';
    $('#mnvin').text(mnvin);
  }
  else {
    if ((mnpubkey != "") && (mnvin == "")) {
      if (((dashninjatestnet == 0) && (!dashmainkeyregexp.test(mnpubkey)))
        || ((dashninjatestnet == 1) && (!dashtestkeyregexp.test(mnpubkey)))) {
        mnpubkey = 'Invalid';
        $('#mnpubkey').text(mnpubkey);
      }
      else {
        mndetailsRefresh(false);
      }
    }
    else {
      if (!dashoutputregexp.test(mnvin)) {
        mnvin = 'Invalid';
        $('#mnoutput').text( mnvin );
      }
      else {
        mndetailsRefresh(true);
      }
    }
  }

  $('#paymentstable').on('xhr.dt', function ( e, settings, json ) {
        // Fill per version stats table
        var totpaid = 0.0;
        var numpaid = 0;
        var missed = 0;
        var hijacked = 0;
        for (var block in json.data.blocks) {
          if(json.data.blocks[block].BlockMNPayee == mnpubkey) {
            totpaid += parseFloat(json.data.blocks[block].BlockMNValue);
            numpaid ++;
            if (json.data.blocks[block].BlockMNPayee != json.data.blocks[block].BlockMNPayeeExpected) {
              hijacked++;
            }
          }
          else {
            if (json.data.blocks[block].BlockMNPayee != json.data.blocks[block].BlockMNPayeeExpected) {
              missed++;
            }
          }
        }
        var num = Math.round( totpaid * 1000 ) / 1000;
        $('#mntotalpaid').text ( addCommas( num.toFixed(3) )+' '+dashninjacoin[dashninjatestnet]+' ('+numpaid+' times / '+missed+' missed / '+hijacked+' hijacked)');

        // Change the last refresh date
        var date = new Date();
        var n = date.toDateString();
        var time = date.toLocaleTimeString();
        $('#paymentstableLR').text( n + ' ' + time );
      } );

});
