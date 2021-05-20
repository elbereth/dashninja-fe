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

var dashninjaversion = '0.1.0';
var tablePayments = null;
var tableExStatus = null;
var dataProtocolDesc = [];
var maxProtocol = -1;
var dashmainkeyregexp = /^[7X][a-zA-Z0-9]{33}$/;
var dashtestkeyregexp = /^[yx][a-zA-Z0-9]{33}$/;
var dashoutputregexp = /^[a-z0-9]{64}-[0-9]+$/;
var mnprotx = '';
var mnvin = '';

$.fn.dataTable.ext.errMode = 'throw';

var dashninjatestnet = 0;

if (typeof dashninjatestnethost !== 'undefined') {
    if (window.location.hostname == dashninjatestnethost) {
        dashninjatestnet = 1;
    }
}
if (typeof dashninjatestnettor !== 'undefined') {
    if (window.location.hostname == dashninjatestnettor) {
        dashninjatestnet = 1;
    }
}
if (typeof dashninjatestneti2p !== 'undefined') {
    if (window.location.hostname == dashninjatestneti2p) {
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

function mnprotxdetailsRefresh(useVin){
  console.log("DEBUG: mnprotxdetailsRefresh starting");
  $('#mninfosLR').html( '<i class="fa fa-spinner fa-pulse"></i> Refreshing <i class="fa fa-spinner fa-pulse"></i>' );
  var query = '/api/protx?balance=1&portcheck=1&cachebypass=1&testnet='+dashninjatestnet;
  if (useVin) {
    query += '&vins=["'+mnvin+'"]';
  }
  else {
    query += '&protxhash=["'+mnprotx+'"]';
  }
  console.log("DEBUG: REST query="+query);

  $('#currentloading').removeClass("visually-hidden");
  $('#currentmessage').text("Querying Dash Ninja API...");

  $.getJSON( query, function( data ) {
   var date = new Date();
   var n = date.toDateString();
   var time = date.toLocaleTimeString();
   var result = "";

  $('#currentmessage').text("Dash Ninja API responded!");

   if (!(data.hasOwnProperty("status")) || (data.status != "OK")) {
       $('#currentmessage').text("Query failed ("+data.status+"): ["+data.messages.join(" / ")+"]");
       $('#currentloading').addClass("visually-hidden");
       return;
   }
   else if ((!data.hasOwnProperty("data")) || (!data.data.hasOwnProperty("protx")) || (data.data.protx.length < 1) || (!data.data.hasOwnProperty("cache")) || (!data.data.hasOwnProperty("api"))) {
       $('#currentmessage').text("Masternode not found in Dash Ninja database...");
       $('#currentloading').addClass("visually-hidden");
       result = 'Unknown masternode';
       $('#mnprotx').text(result+" ("+mnprotx+")");
       $('#mnoutput').text(result+" ("+mnvin+")");
    $('#mnpubkey').text(result+" ("+mnpubkey+")");
    $('#mnipport').text(result);      
    $('#mncountry').text(result);
    $('#mnstatus').text(result).removeClass("danger").removeClass("warning").removeClass("success");
    $('#mnstatuspanel').removeClass("panel-primary").removeClass("panel-yellow").removeClass("panel-green").removeClass("panel-red").addClass("panel-primary");
    $('#mnsentinelpanel').removeClass("panel-primary").removeClass("panel-green").removeClass("panel-red").addClass("panel-primary");
    $('#mnsentinelcheck').text('???');
    $('#mnlistsentinelversion').text(result);
    $('#mnlistdaemonversion').text(result);
    $('#mnactiveduration').text(result);
    $('#mnlastseen').text(result);
    $('#mnbalance').text(result).removeClass("danger").removeClass("success");
    $('#mnlastpaid').text(result);
    $('#mnportcheck').text(result).removeClass("danger").removeClass("info").removeClass("success");
    $('#mnportchecknext').text(result);
    $('#mnversion').text(result);
   }
   else {
       $('#currentmessage').text("Dash Ninja API response displayed...");
       $('#currentloading').addClass("visually-hidden");

     $('#mnprotx').text( data.data.protx[0].proTxHash);

     $('#mnoutput').text( data.data.protx[0].collateralHash+"-"+data.data.protx[0].collateralIndex);
     $('#mnpubkey').text( data.data.protx[0].state.payoutAddress );
     $('#mnopkey').text( data.data.protx[0].state.operatorRewardAddress );
     $('#mnownerkey').text( data.data.protx[0].state.keyIDOwner );
     $('#mnvotingkey').text( data.data.protx[0].state.keyIDVoting );
       var mnip = "";
       if ( data.data.protx[0].addrIP == "::" ) {
           mnip = data.data.protx[0].state.addrIP+".onion";
       }
       else {
           mnip = data.data.protx[0].state.addrIP;
       }
    $('#mnipport').text( mnip+":"+data.data.protx[0].state.addrPort );
    mnpubkey = data.data.protx[0].state.payoutAddress;

    var activecount = parseInt(data.data.protx[0].state.activeCount);
    var inactivecount = parseInt(data.data.protx[0].state.inactiveCount);
    var total = activecount+inactivecount;
    var ratio = activecount / total;

    result = ratio;
    var cls = "";
    var cls2 = "";
    if ( ratio == 1 ) {
      result = 'Active';
      cls = "bg-success";
      cls2 = "table-success";
    } else if ( ratio == 0 ) {
      result = 'Inactive';
      cls = "bg-danger";
      cls2 = "table-danger";
    } else {
      result = 'Partially Inactive';
      cls = "bg-warning";
      cls2 = "table-warning";
    }
    result += ' ('+Math.round(ratio*100)+'%)';
    $('#mnstatus').text(result);
    $('#mnstatuscardtitle').removeClass("bg-secondary").removeClass("bg-warning").removeClass("bg-success").removeClass("bg-danger").addClass(cls);
    $('#mnstatusactivecount').text ( data.data.protx[0].state.activeCount+'/'+total).removeClass("table-danger").removeClass("table-success").removeClass("table-warning").addClass(cls2);

    $('#mnstatusregisteredheight').text ( data.data.protx[0].state.registeredHeight);
    $('#mnstatuslastpaidheight').text ( data.data.protx[0].state.lastPaidHeight);
    cls = "table-danger";
    if (data.data.protx[0].state.PoSePenalty == 0) {
        cls = "table-success";
    }
    $('#mnstatusposepenalty').text ( data.data.protx[0].state.PoSePenalty).removeClass("table-danger").removeClass("table-success").addClass(cls);
    cls = "table-danger";
    if (data.data.protx[0].state.PoSeBanHeight == -1) {
        cls = "table-success";
    }
    $('#mnstatusposebanheight').text ( data.data.protx[0].state.PoSeBanHeight).removeClass("table-danger").removeClass("table-success").addClass(cls);
    $('#mnstatusposerevivedheight').text ( data.data.protx[0].state.PoSeRevivedHeight);

    if (data.data.protx[0].state.StateDate > 0) {
        var tmpDate = new Date(data.data.protx[0].state.StateDate*1000);
        result = tmpDate.toLocaleString()+" ("+deltaTimeStampHRlong(data.data.protx[0].state.StateDate,currenttimestamp())+" ago)";
    }
    else {
      result = 'Just now ('+data.data.protx[0].state.StateDate+')';
    }
    $('#mnlastseen').text ( result);

    var cls = "panel-green";
    if (data.data.protx[0].MasternodeSentinelState != 'current') {
      cls = "panel-red";
    }

    // Balance data
    var num = Math.round( data.data.protx[0].Balance.Value * 1000 ) / 1000;
    if ( num < 1000 ) {
      cls = "danger";
    } else {
      cls = "success";
    }
    $('#mnbalance').text ( addCommas( num.toFixed(3) )+' '+dashninjacoin[dashninjatestnet]).removeClass("danger").removeClass("success").addClass(cls);

    // Last Paid data
    /*var outtxt = "";
    if (data.data.protx[0].MasternodeLastPaid != 0) {
        var tmpDate = new Date(data.data.protx[0].MasternodeLastPaid*1000);
      outtxt = tmpDate.toLocaleString()+" ("+deltaTimeStampHRlong(parseInt(data.data.protx[0].MasternodeLastPaid),currenttimestamp())+" ago)";
    }
    else {
      outtxt = 'Never/Unknown';
    }
    $('#mnlastpaid').html( outtxt );*/

    // Last Paid from blocks data
    /*var outtxt = "";
    if (data.data.protx[0].LastPaidFromBlocks !== false) {
      var tmpDate = new Date(data.data.protx[0].LastPaidFromBlocks.MNLastPaidTime*1000);
      outtxt = tmpDate.toLocaleString()+" ("+deltaTimeStampHRlong(parseInt(data.data.protx[0].LastPaidFromBlocks.MNLastPaidTime),currenttimestamp())+" ago) on block ";
      if (dashninjaqueryexplorer[dashninjatestnet].length > 0) {
        outtxt += '<a href="'+dashninjaqueryexplorer[dashninjatestnet][0][0].replace('%%q%%',data.data.protx[0].LastPaidFromBlocks.MNLastPaidBlock)+'">'+data.data.protx[0].LastPaidFromBlocks.MNLastPaidBlock+'</a>';
      }
      else {
        outtxt += data.data.protx[0].LastPaidFromBlocks.MNLastPaidBlock;
      }
    }
    else {
      outtxt = 'Never/Unknown';
    }
    $('#mnlastpaidfromblocks').html( outtxt );

    cls = "danger";
    if (Math.abs(parseInt(data.data.protx[0].MasternodeLastPaid)-parseInt(data.data.protx[0].LastPaidFromBlocks.MNLastPaidTime)) < 120) {
      cls = "success";
    }
    $('#mnlastpaid').removeClass("success").removeClass("danger").addClass(cls);
    $('#mnlastpaidfromblocks').removeClass("success").removeClass("danger").addClass(cls);*/

    // Port Check data
    $('#mncountry').html( '<img src="/static/flags/flags_iso/16/'+data.data.protx[0].Portcheck.CountryCode+'.png" width=16 height=16 /> '+data.data.protx[0].Portcheck.Country );
    var txt = data.data.protx[0].Portcheck.Result;
    cls2 = "";
    if ((data.data.protx[0].Portcheck.Result == 'closed') || (data.data.protx[0].Portcheck.Result == 'timeout')) {
      txt = "Closed ("+data.data.protx[0].Portcheck.ErrorMessage+")";
      cls2 = "bg-danger";
    } else if (data.data.protx[0].Portcheck.Result == 'unknown') {
      txt = "Pending";
      cls2 = "bg-secondary";
    } else if ((data.data.protx[0].Portcheck.Result == 'open') || (data.data.protx[0].Portcheck.Result == 'rogue')) {
      txt = "Open";
      cls2 = "bg-success";
    }
    $('#mnportcheck').text(txt);
    $('#mnportchecktitle').removeClass("bg-secondary").removeClass("bg-danger").removeClass("bg-success").addClass(cls2);

    if (data.data.protx[0].Portcheck.NextCheck < currenttimestamp()) {
      if (txt != "Pending") {
        $('#mnportchecknext').text('Re-check pending');
      }
    }
    else {
      $('#mnportchecknext').text(deltaTimeStampHRlong(data.data.protx[0].Portcheck.NextCheck,currenttimestamp()));
    }
    var date = new Date(data.data.protx[0].Portcheck.NextCheck*1000);
    var n = date.toDateString();
    var time = date.toLocaleTimeString();
       $('#mnportchecknextdate').text(n+' '+time);

       var versioninfo = '<i>Unknown</i>';
    if ((data.data.protx[0].hasOwnProperty("Portcheck")) && (data.data.protx[0].Portcheck != false)) {
        if ((data.data.protx[0].Portcheck.SubVer.length > 10) && (data.data.protx[0].Portcheck.SubVer.substring(0, 9) == '/Satoshi:') && (data.data.protx[0].Portcheck.SubVer.substring(data.data.protx[0].Portcheck.SubVer.length - 1) == '/')) {
            versioninfo = data.data.protx[0].Portcheck.SubVer.substring(9, data.data.protx[0].Portcheck.SubVer.indexOf('/', 10));
        }
        else if ((data.data.protx[0].Portcheck.SubVer.length > 7) && (data.data.protx[0].Portcheck.SubVer.substring(0, 6) == '/Core:') && (data.data.protx[0].Portcheck.SubVer.substring(data.data.protx[0].Portcheck.SubVer.length - 1) == '/')) {
            versioninfo = data.data.protx[0].Portcheck.SubVer.substring(6, data.data.protx[0].Portcheck.SubVer.indexOf('/', 6));
        }
        else if ((data.data.protx[0].Portcheck.SubVer.length > 11) && (data.data.protx[0].Portcheck.SubVer.substring(0, 11) == '/Dash Core:') && (data.data.protx[0].Portcheck.SubVer.substring(data.data.protx[0].Portcheck.SubVer.length - 1) == '/')) {
            versioninfo = data.data.protx[0].Portcheck.SubVer.substring(11, data.data.protx[0].Portcheck.SubVer.indexOf('/', 11));
        }
    }
    else {
        versioninfo = "Unknown";
    }
    $('#mnversion').html( versioninfo+" (Protocol="+data.data.protx[0].MasternodeProtocol+")" );
       $('#mnversionraw').html( data.data.protx[0].Portcheck.SubVer );
       $('#mnportcheckerror').html( data.data.protx[0].Portcheck.ErrorMessage );
   }
   $('#mninfosLR').text( n + ' ' + time );

      tablePayments = $('#paymentstable').dataTable( {
        responsive: true,
        searching: false,
        ajax: { url: '/api/blocks?testnet='+dashninjatestnet+'&pubkeys=["'+data.data.protx[0].state.payoutAddress+'"]&interval=P1M',
                dataSrc: 'data.blocks',
                cache: true },
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
            $('td',row).eq(5).removeClass("table-danger").removeClass("table-success").addClass("table-success");
          }
          else {
            $('td',row).eq(5).removeClass("table-danger").removeClass("table-success").addClass("table-danger");
          }
          if (data.BlockMNPayee == mnpubkey) {
            $('td',row).eq(6).removeClass("table-danger").removeClass("table-success").addClass("table-success");
          }
          else {
              $('td',row).eq(6).removeClass("table-danger").removeClass("table-success").addClass("table-danger");
          }
        }
   } );
      tableExStatus = $('#exstatustable').dataTable( {
          responsive: true,
          searching: false,
          data: data.data.protx[0].ExStatus,
          paging: false,
          order: [[ 0, "asc" ]],
          columns: [
              { data: "NodeName" },
              { data: null, render: function ( data, type, row ) {
                      var outtxt = '';
                      if (type != "sort") {
                          if (data.StatusEx == "ENABLED") {
                              outtxt = '<i class="fa fa-thumbs-up"> ';
                          } else if (data.StatusEx == "PRE_ENABLED") {
                              outtxt = '<i class="fa fa-thumbs-o-up"> ';
                          } else if (data.StatusEx == "WATCHDOG_EXPIRED") {
                              outtxt = '<i class="fa fa-cogs"> ';
                          } else if (data.StatusEx == "POS_ERROR") {
                              outtxt = '<i class="fa fa-exclamation-triangle"> ';
                          } else if (data.StatusEx == "REMOVE") {
                              outtxt = '<i class="fa fa-chain-broken"> ';
                          } else if (data.StatusEx == "EXPIRED") {
                              outtxt = '<i class="fa fa-clock-o"> ';
                          } else if (data.StatusEx == "VIN_SPENT") {
                              outtxt = '<i class="fa fa-money"> ';
                          } else if (data.StatusEx == "NEW_START_REQUIRED") {
                              outtxt = '<i class="fa fa-wrench"> ';
                          } else if (data.StatusEx == "UPDATE_REQUIRED") {
                              outtxt = '<i class="fa fa-wrench"> ';
                          } else if (data.StatusEx != '') {
                              outtxt = '<i class="fa fa-thumbs-down"> ';
                          }
                      }
                      outtxt = outtxt+data.StatusEx;
                      return outtxt; } },
              { data: null, render: function ( data, type, row ) {
                      if (type == "sort") {
                          return data.Status;
                      } else if (data.Status == "active") {
                          return '<i class="fa fa-play"> Active';
                      } else if (data.Status == "inactive") {
                          return '<i class="fa fa-pause"> Inactive';
                      } else if (data.Status == "unlisted") {
                          return '<i class="fa fa-stop"> Unlisted';
                      }
                  } },
              { data: "NodeVersion" },
              { data: "NodeProtocol" }
          ],
          createdRow: function ( row, data, index ) {
              if (data.Status == "active") {
                  $('td',row).eq(2).css({"background-color": "#dff0d8", "color": "#3c763d"});
              }
              else if (data.Status == "inactive") {
                  $('td',row).eq(2).css({"background-color": "#fcf8e3", "color": "#8a6d3b"});
              }
              else {
                  $('td',row).eq(2).css({"background-color": "#f2dede", "color": "#a94442"});
              }
              if (data.StatusEx == "ENABLED") {
                  $('td',row).eq(1).css({"background-color": "#dff0d8", "color": "#3c763d"});
              }
              else if (data.StatusEx == "PRE_ENABLED") {
                  $('td',row).eq(1).css({"background-color": "#fcf8e3", "color": "#8a6d3b"});
              }
              else {
                  $('td',row).eq(1).css({"background-color": "#f2dede", "color": "#a94442"});
              }
          }
      } );
      $('#exstatustableLR').text( n + ' ' + time );
   console.log("DEBUG: auto-refresh starting");
   setTimeout(mnprotxdetailsRefresh, 300000);
  });
};

$(document).ready(function(){

  $('#dashninjajsversion').text( dashninjaversion ).addClass("label-info").removeClass("label-danger");

  if (dashninjatestnet == 1) {
    $('#testnetalert').show();
    $('#mainnetalert').hide();
    $('#testnettitle').show();
    $('a[name=menuitemexplorer]').attr("href", "https://"+dashninjatestnetexplorer);
  }

  mnprotx = getParameter("protxhash");
  console.log("DEBUG: protxhash="+mnprotx);
  mnvin = getParameter("mnoutput");
  console.log("DEBUG: mnvin="+mnvin);

  if ((mnprotx == "") && (mnvin == "")) {
    mnprotx = 'Need "protxhash" parameter';
    $('#mnprotx').text(mnprotx);
    mnvin = 'Need "mnoutput" parameter';
    $('#mnvin').text(mnvin);
  }
  else {
    if ((mnprotx != "") && (mnvin == "")) {
      mnprotxdetailsRefresh(false);
    }
    else {
      if (!dashoutputregexp.test(mnvin)) {
        mnvin = 'Invalid';
        $('#mnoutput').text( mnvin );
      }
      else {
        mnprotxdetailsRefresh(true);
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
