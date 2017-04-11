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

// Dash Ninja Front-End (dashninja-fe) - Masternode List (v2)
// By elberethzone / https://www.dash.org/forum/members/elbereth.175/

var dashninjaversion = '2.3.7';
var tableLocalNodes = null;
var tableBlockConsensus = null;
var tableMNList = null;
var chartMNVersions = null;
var dashversiondefault = "0.12.1.5";
var dashversion = dashversiondefault;
var dashversioncheck = dashversion;
var dashversionsemaphore = false;
var sentinelversiondefault = "1.0";
var sentinelversion = sentinelversiondefault;
var dashmaxprotocol = 0;

$.fn.dataTable.ext.errMode = 'throw';

if(typeof(Storage) !== "undefined") {
  if (sessionStorage.getItem("nextdashversion") !== null) {
    sessionStorage.removeItem("nextdashversion");
  }
}

if (typeof dashninjatestnet === 'undefined') {
  var dashninjatestnet = 0;
}
if (typeof dashninjatestnethost !== 'undefined') {
  if (window.location.hostname == dashninjatestnethost) {
    dashninjatestnet = 1;
    $('a[name=menuitemexplorer]').attr("href", "https://"+dashninjatestnetexplorer);
  }
}

if (typeof dashninjamndetail === 'undefined') {
  var dashninjamndetail = [[],[]];
}
if (typeof dashninjamndetail[0] === 'undefined') {
  dashninjamndetail[0] = [];
}
if (typeof dashninjamndetail[1] === 'undefined') {
  dashninjamndetail[1] = [];
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

if (typeof dashninjaqueryexplorer === 'undefined') {
  var dashninjaqueryexplorer = [[],[]];
}
if (typeof dashninjaqueryexplorer[0] === 'undefined') {
  dashninjaqueryexplorer[0] = [];
}
if (typeof dashninjaqueryexplorer[1] === 'undefined') {
  dashninjaqueryexplorer[1] = [];
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

function tableLocalNodesRefresh(){
  tableLocalNodes.api().ajax.reload();
  // Set it to refresh in 60sec
  setTimeout(tableLocalNodesRefresh, 60000);
};

function tableBlockConsensusRefresh(){
  tableBlockConsensus.api().ajax.reload();
  // Set it to refresh in 60sec
  setTimeout(tableLocalNodesRefresh, 150000);
};

function tableMNListRefresh(){
  tableMNList.api().ajax.reload();
  // Set it to refresh in 60sec
  setTimeout(tableMNListRefresh, 300000);
};

function mnpaymentsRefresh(){
  $.getJSON( "/api/masternodes/stats?testnet="+dashninjatestnet, function( data ) {
    var date = new Date();
    var n = date.toDateString();
    var time = date.toLocaleTimeString();
    $('#mnpaymentsLR').text( n + ' ' + time );
    $('#mnpayments').text( Math.round(data.data.MasternodeStatsTotal.MasternodeExpectedPayment*10000)/10000 );
    $('#mnpaymentsratio').text( (Math.round(data.data.MasternodeStatsTotal.RatioPayed*10000)/100)+ '%' );
    setTimeout(mnpaymentsRefresh, 300000);
  });
};

function displaydashVersion(dashversion,sentinelversion) {
  if (dashversion != "?") {
    $('#msgalert').show();
  }
  else {
    $('#msgalert').hide();
  }
    $('#currentdashversion').text( dashversion );
    $('#currentsentinelversion').text( sentinelversion );
}

function getLatestdashVersion() {
  var currentdate = new Date();
    dashversion = sessionStorage.getItem("currentdashversion");
    sentinelversion = sessionStorage.getItem("currentsentinelversion");
  var nextdate = sessionStorage.getItem("nextdashversion");
  if ((( dashversion === null ) || (sentinelversion === null)
   || ( sessionStorage.getItem("nextdashversion") === null )
   || ( sessionStorage.getItem("nextdashversion") < currentdate.getTime() )) && (dashversionsemaphore == false)) {
    dashversionsemaphore = true;
    $.getJSON( "/dashninja-latestversion.json?nocache="+ (new Date()).getTime(), function( data ) {
      sessionStorage.setItem('currentdashversion', data.version.string);
      sessionStorage.setItem('currentsentinelversion', data.sentinelversion.string);
      var currentdate = new Date();
      currentdate = new Date(currentdate.getTime() + 15*60000);
      sessionStorage.setItem('nextdashversion', currentdate.getTime());
      dashversionsemaphore = false;
      displaydashVersion(data.version.string,data.sentinelversion.string);
    });
      dashversion = dashversiondefault;
      sentinelversion = sentinelversiondefault;
  }
  else {
    if (dashversion === null) {
      dashversion = dashversiondefault;
    }
      if (sentinelversion === null) {
          sentinelversion = sentinelversiondefault;
      }
    displaydashVersion(dashversion,sentinelversion);
  }
  if ((dashversion.length > 2) && (dashversion.substr(dashversion.length - 2) == ".0")) {
      dashversioncheck = dashversion.substr(0,dashversion.length-2);
  }
  else {
      dashversioncheck = dashversion;
  }
  return dashversioncheck;
};

function getVoteLimit() {
    $.getJSON("/api/governanceproposals/votelimit?testnet=" + dashninjatestnet, function (data) {
        var cls = "alert-danger";
        if (data.data.votelimit.nextvote.BlockTime == 0) {
            var datevotelimit = new Date(data.data.votelimit.nextsuperblock.BlockTime * 1000);
            $('#nextvotelimit').text( "Vote is over for this month, next superblock approximatly on "+datevotelimit.toLocaleString() );
            $('#nextvotelimithr').text( "Too late!");
        }
        else {
            var datevotelimit = new Date(data.data.votelimit.nextvote.BlockTime * 1000);
            $('#nextvotelimit').text("Approximately on "+datevotelimit.toLocaleString());
            $('#nextvotelimithr').text(deltaTimeStampHRlong(data.data.votelimit.nextvote.BlockTime, currenttimestamp()));
            if ((data.data.votelimit.nextvote.BlockTime - currenttimestamp()) <= 86400) {
                cls = "alert-warning";
            }
            else {
                cls = "alert-success";
            }
        }
        $('#msgalert2').removeClass("alert-success").removeClass("alert-danger").removeClass("alert-warning").addClass(cls);
    });
};


$(document).ready(function(){

  $('#dashninjajsversion').text( dashninjaversion );

  if (dashninjatestnet == 1) {
    $('#testnetalert').show();
  }

  if (typeof dashninjator !== 'undefined') {
      $('a[name=dashninjatorurl]').attr("href", "http://"+dashninjator+"/masternodes.html").text( dashninjator+"/masternodes.html" );
      $('span[name=dashninjatordisplay]').show();
  }

  getLatestdashVersion();
  getVoteLimit();

  var pkutxt = '<ul>';
  var ix = 0;
  for ( var i=0, ien=dashninjamndetail[dashninjatestnet].length ; i<ien ; i++ ) {
    if (ix == 0) {
      pkutxt += '<li>[Link]';
    } else {
      pkutxt += '<li>['+ix+']';
    }
    pkutxt += ' '+dashninjamndetail[dashninjatestnet][i][1]+"</li>";
    ix++;
  }
  for ( var i=0, ien=dashninjaaddressexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
    if (ix == 0) {
      pkutxt += '<li>[Link]';
    } else {
      pkutxt += '<li>['+ix+']';
    }
    pkutxt += ' '+dashninjaaddressexplorer[dashninjatestnet][i][1]+"</li>";
    ix++;
  }
  pkutxt += '</ul>';
  $("#pubkeyurllist").html(pkutxt);

   $('#localnodes').on('xhr.dt', function ( e, settings, json ) {
        var date = new Date();
        var n = date.toDateString();
        var time = date.toLocaleTimeString();
        $('#localnodesLR').text( n + ' ' + time );
        $('#localnodes').DataTable().column(1).search('^(?:(?!Disabled).)*$',true,false).draw();
      } );
   tableLocalNodes = $('#localnodes').dataTable( {
        dom: "Tfrtp",
        ajax: "/api/nodes?testnet="+dashninjatestnet,
        "paging": false,
        columns: [
            { data: "NodeName" },
            { data: null, render: function ( data, type, row ) {
                if (data.NodeEnabled == 0) {
                  return '<img src="/static/status/daemon-disabled.png" width=16 height=16 /> Disabled';
                }
                else {
                  var iconurl = '<img src="/static/status/daemon-'+data.NodeProcessStatus+'.png" width=16 height=16 /> ';
                  if (data.NodeProcessStatus == 'running') {
                    return iconurl+'Running';
                  } else if (data.NodeProcessStatus == 'stopped') {
                    return iconurl+'Stopped';
                  } else if (data.NodeProcessStatus == 'notresponding') {
                    return iconurl+'Not Responding';
                  } else {
                    return data.NodeProcessStatus;
                  }
                }
            } },
            { data: null, render: function ( data, type, row) {
               var outtxt = '';
               if ((data.NodeEnabled != 0) && (data.NodeProcessStatus == 'running')) {
                 outtxt = data.NodeVersion;
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row) {
               var outtxt = '';
               if ((data.NodeEnabled != 0) && (data.NodeProcessStatus == 'running')) {
                 outtxt = data.NodeProtocol;
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row) {
               var outtxt = '';
               if ((data.NodeEnabled != 0) && (data.NodeProcessStatus == 'running')) {
                 outtxt = data.NodeBlocks;
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row) {
               var outtxt = '';
               if ((data.NodeEnabled != 0) && (data.NodeProcessStatus == 'running')) {
                 if (type != 'sort') {
                   if (dashninjablockexplorer[dashninjatestnet].length > 0) {
                     outtxt += '<a href="'+dashninjablockexplorer[dashninjatestnet][0][0].replace('%%b%%',data.NodeLastBlockHash)+'">'+data.NodeLastBlockHash+'</a>';
                     for ( var i=1, ien=dashninjablockexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                       outtxt += '<a href="'+dashninjablockexplorer[dashninjatestnet][i][0].replace('%%b%%',data.NodeLastBlockHash)+'">['+i+']</a>';
                     }
                   }
                 }
                 else {
                   outtxt = data.NodeLastBlockHash;
                 }
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row) {
               var outtxt = '';
               if ((data.NodeEnabled != 0) && (data.NodeProcessStatus == 'running')) {
                 outtxt = data.NodeConnections;
               }
               return outtxt;
            } }
        ]
    } );
   setTimeout(tableLocalNodesRefresh, 60000);

   $('#blockconsensus').on('xhr.dt', function ( e, settings, json ) {
        var date = new Date();
        var n = date.toDateString();
        var time = date.toLocaleTimeString();
        $('#blockconsensusLR').text( n + ' ' + time );
      } );
   tableBlockConsensus = $('#blockconsensus').dataTable( {
        dom: "Trtp",
        ajax: "/api/blocks/consensus?testnet="+dashninjatestnet,
        "paging": false,
        "order": [[ 0, "desc" ]],
        columns: [
            { data: "BlockID" },
            { data: null, render: function ( data, type, row ) {
                return (Math.round(data.Consensus*10000)/100)+ '%';
            } },
            { data: null, render: function ( data, type, row ) {
                if (data.ConsensusPubKey == '') {
                  return "<i>None</i>";
                } else {
                  return data.ConsensusPubKey;
                }
            } },
            { data: null, render: function ( data, type, row) {
               var str = '<ul>';
               var sstr = '';
               for (var col in data.Others) {
                 str += '<li>';
                 if ( data.Others[col].Payee == '' ) {
                   str += '<i>None</i>';
                 } else {
                   str += data.Others[col].Payee;
                 }
                 str += ' ('+(Math.round(data.Others[col].RatioVotes*10000)/100)+ '% - Nodes: ';
                 sstr = '';
                 for (var uname in data.Others[col].NodeNames) {
                   if (sstr != '') {
                     sstr += ' ';
                   }
                   sstr += data.Others[col].NodeNames[uname];
                 }
                 str += sstr+')</li>'
               };
               return str+'</ul>';
            } }
        ],
        "createdRow": function ( row, data, index ) {
            if ( data.Consensus == 1 ) {
                $('td',row).eq(1).css({"background-color":"#8FFF8F"});
            } else {
                $('td',row).eq(1).css({"background-color":"#FF8F8F"});
            }
        }
    } );
   setTimeout(tableBlockConsensusRefresh, 150000);

   chartMNVersions = $('#mnversions').highcharts({
     chart: {
       plotBackgroundColor: null,
       plotBorderWidth: null,//null,
       plotShadow: false
     },
     title: {
       text: 'Masternode versions (only compatible protocols)'
     },
     tooltip: {
       pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
     },
     plotOptions: {
       pie: {
         allowPointSelect: true,
         cursor: 'pointer',
         dataLabels: {
           enabled: true,
            format: '<b>{point.name}</b>: {point.percentage:.1f} %',
             style: {
                      color: (Highcharts.theme && Highcharts.theme.contrastTextColor) || 'black'
                    }
         }
       }
     },
     series: [{
       type: 'pie',
       name: 'Masternode version',
       data: [['Unknown', 100]]
     }]
   });

   $('#mnregexp').on( 'keyup click', function () {
     $('#mnlist').DataTable().search($('#mnregexp').val(), true, false).draw();
   } );
 
   $('#mnregexp').val(getParameter("mnregexp"));

   $('#mnlist').on('xhr.dt', function ( e, settings, json ) {
        var date = new Date();
        var n = date.toDateString();
        var time = date.toLocaleTimeString();
        var activeCount = 0;
        var uniqueIPs = [];
        $('#mnlistLR').text( n + ' ' + time );
        var versioninfo = 'Unknown';
        var dataVersionCount = [];
        var mnregexp = $('#mnregexp').val();
        for ( var i=0, ien=json.data.length ; i<ien ; i++ ) {
          if (parseInt(json.data[i].MasternodeProtocol) > dashmaxprotocol) {
            dashmaxprotocol = parseInt(json.data[i].MasternodeProtocol);
          }
          if (json.data[i].ActiveCount > 0) {
            activeCount++;
          }
          if (uniqueIPs.indexOf(json.data[i].MasternodeIP+":"+json.data[i].MasternodePort) == -1) {
            uniqueIPs.push( json.data[i].MasternodeIP+":"+json.data[i].MasternodePort );
          }
          if ((json.data[i].Portcheck != false) && json.data[i].Portcheck.hasOwnProperty("SubVer")) {
            if ((json.data[i].Portcheck.SubVer.length > 10) && (json.data[i].Portcheck.SubVer.substring(0,9) == '/Satoshi:') && (json.data[i].Portcheck.SubVer.substring(json.data[i].Portcheck.SubVer.length-1) == '/')) {
              versioninfo = json.data[i].Portcheck.SubVer.substring(9,json.data[i].Portcheck.SubVer.indexOf('/',10));
            }
            else if ((json.data[i].Portcheck.SubVer.length > 7) && (json.data[i].Portcheck.SubVer.substring(0,6) == '/Core:') && (json.data[i].Portcheck.SubVer.substring(json.data[i].Portcheck.SubVer.length-1) == '/')) {
              versioninfo = json.data[i].Portcheck.SubVer.substring(6,json.data[i].Portcheck.SubVer.indexOf('/',6));
            }
            else if ((json.data[i].Portcheck.SubVer.length > 11) && (json.data[i].Portcheck.SubVer.substring(0,11) == '/Dash Core:') && (json.data[i].Portcheck.SubVer.substring(json.data[i].Portcheck.SubVer.length-1) == '/')) {
              versioninfo = json.data[i].Portcheck.SubVer.substring(11,json.data[i].Portcheck.SubVer.indexOf('/',11));
            }
            else {
              versioninfo = "Unknown";
            }
          }
          else {
            versioninfo = "Unknown";
          }
          versioninfo = versioninfo+" ("+json.data[i].MasternodeProtocol+")";
          if (dataVersionCount.hasOwnProperty(versioninfo)) {
            dataVersionCount[versioninfo]++;
          }
          else {
            dataVersionCount[versioninfo] = 1;
          }
        }

        var dataSet = [];
        for (version in dataVersionCount) {
          if (dataVersionCount.hasOwnProperty(version)) {
            dataSet.push( [version, Math.round((dataVersionCount[version]/json.data.length)*10000)/100] );
          }
        }
        chartMNVersions = $('#mnversions').highcharts();
        chartMNVersions.series[0].setData(dataSet,true);

        var inactiveCount = json.data.length - activeCount;

        $('#mnactive').text( activeCount );
        $('#mninactive').text( inactiveCount );
        $('#mntotal').text( json.data.length );
        $('#uniquemnips').text( uniqueIPs.length );

        if (mnregexp != "") {
          $('#mnlist').DataTable().search(mnregexp, true, false).draw();
        }
   } );
   tableMNList = $('#mnlist').dataTable( {
        ajax: "/api/masternodes?testnet="+dashninjatestnet+"&v12=1&portcheck=1&balance=1&lastpaid=1",
        lengthMenu: [ [50, 100, 250, 500, -1], [50, 100, 250, 500, "All"] ],
        pageLength: 50,
        columns: [
            { data: null, render: function ( data, type, row ) {
               var outtxt = '';
               if (type != 'sort') {
                 if ((dashninjamndetailvin[dashninjatestnet].length > 0) || (dashninjatxexplorer[dashninjatestnet].length > 0)) {
                   var ix = 0;
                   for ( var i=0, ien=dashninjamndetailvin[dashninjatestnet].length ; i<ien ; i++ ) {
                     if (ix == 0) {
                       outtxt += '<a href="'+dashninjamndetailvin[dashninjatestnet][0][0].replace('%%a%%',data.MasternodeOutputHash+'-'+data.MasternodeOutputIndex)+'">'+data.MasternodeOutputHash+'-'+data.MasternodeOutputIndex+'</a>';
                     }
                     else {
                       outtxt += '<a href="'+dashninjamndetailvin[dashninjatestnet][i][0].replace('%%a%%',data.MasternodeOutputHash+'-'+data.MasternodeOutputIndex)+'">['+ix+']</a>';
                     }
                     ix++;
                   }
                   for ( var i=0, ien=dashninjatxexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                     if (ix == 0) {
                       outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][0][0].replace('%%a%%',data.MasternodeOutputHash)+'">'+data.MasternodeOutputHash+'-'+data.MasternodeOutputIndex+'</a>';
                     }
                     else {
                       outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][i][0].replace('%%a%%',data.MasternodeOutputHash)+'">['+ix+']</a>';
                     }
                     ix++;
                   }
                 }
                 else {
                   outtxt = data.MasternodeOutputHash+'-'+data.MasternodeOutputIndex;
                 }
               }
               else {
                 outtxt = data.MasternodeOutputHash+'-'+data.MasternodeOutputIndex;
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row) {
               var outtxt = '';
               if (type != 'sort') {
                 if ((dashninjamndetail[dashninjatestnet].length > 0) || (dashninjaaddressexplorer[dashninjatestnet].length > 0)) {
                   var ix = 0;
                   for ( var i=0, ien=dashninjamndetail[dashninjatestnet].length ; i<ien ; i++ ) {
                     if (ix == 0) {
                       outtxt += '<a href="'+dashninjamndetail[dashninjatestnet][0][0].replace('%%a%%',data.MasternodePubkey)+'">'+data.MasternodePubkey+'</a>';
                     }
                     else {
                       outtxt += '<a href="'+dashninjamndetail[dashninjatestnet][i][0].replace('%%a%%',data.MasternodePubkey)+'">['+ix+']</a>';
                     }
                     ix++;
                   }
                   for ( var i=0, ien=dashninjaaddressexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                     if (ix == 0) {
                       outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][0][0].replace('%%a%%',data.MasternodePubkey)+'">'+data.MasternodePubkey+'</a>';
                     }
                     else {
                       outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][i][0].replace('%%a%%',data.MasternodePubkey)+'">['+ix+']</a>';
                     }
                     ix++;
                   }
                 }
                 else {
                   outtxt = data.MasternodePubkey;
                 }
               }
               else {
                 outtxt = data.MasternodePubkey;
               }
               return outtxt;
            } },
            { data: null, render: function ( data, type, row ) {
                var mnip = "";
                if ( data.MasternodeIP == "::" ) {
                    mnip = data.MasternodeTor+".onion";
                }
                else {
                    mnip = data.MasternodeIP;
                }
                return mnip+':'+data.MasternodePort;
            } },
            { data: null, render: function ( data, type, row ) {
                var txt = "";
                if (data.Portcheck != false) {
                    txt = data.Portcheck.Result;
                    if ((data.Portcheck.Result == 'closed') || (data.Portcheck.Result == 'timeout')) {
                        txt = "Closed";
                    } else if (data.Portcheck.Result == 'unknown') {
                        txt = "Pending";
                    } else if ((data.Portcheck.Result == 'open') || (data.Portcheck.Result == 'rogue')) {
                        txt = "Open";
                    }
                    if (data.Portcheck.NextCheck < currenttimestamp()) {
                        if (txt != "Pending") {
                            txt = txt + ' (Re-check pending)';
                        }
                    }
                    else {
                        txt = txt + ' (' + deltaTimeStampHR(data.Portcheck.NextCheck,currenttimestamp())+')';
                    }
                }
                else {
                    txt = "<i>Unknown</i>";
                }
                return txt;
            } },
            { data: null, render: function ( data, type, row ) {
                var versioninfo = '<i>Unknown</i>';
                if ((data.Portcheck != false) && data.Portcheck.hasOwnProperty("SubVer")) {
                  if ((data.Portcheck.SubVer.length > 10) && (data.Portcheck.SubVer.substring(0,9) == '/Satoshi:') && (data.Portcheck.SubVer.substring(data.Portcheck.SubVer.length-1) == '/')) {
                    versioninfo = data.Portcheck.SubVer.substring(9,data.Portcheck.SubVer.indexOf('/',10));
                  }
                  else if ((data.Portcheck.SubVer.length > 7) && (data.Portcheck.SubVer.substring(0,6) == '/Core:') && (data.Portcheck.SubVer.substring(data.Portcheck.SubVer.length-1) == '/')) {
                    versioninfo = data.Portcheck.SubVer.substring(6,data.Portcheck.SubVer.indexOf('/',6));
                  }
                  else if ((data.Portcheck.SubVer.length > 11) && (data.Portcheck.SubVer.substring(0,11) == '/Dash Core:') && (data.Portcheck.SubVer.substring(data.Portcheck.SubVer.length-1) == '/')) {
                    versioninfo = data.Portcheck.SubVer.substring(11,data.Portcheck.SubVer.indexOf('/',11));
                  }
                }
                return versioninfo;
            } },
            { data: null, render: function ( data, type, row ) {
                return data.MasternodeProtocol;
            } },
            { data: null, render: function ( data, type, row) {
               var balance = parseFloat(data.Balance.Value);
               if (type == 'sort') {
                 return balance;
               }
               else {
                 var num = Math.round( balance * 1000 ) / 1000;
                 return addCommas( num.toFixed(3) );
               }
            } },
            { data: null, render: function ( data, type, row) {
               var lastpaid = parseInt(data.MasternodeLastPaid);
               if (lastpaid > 0) {
                 if (type == 'sort') {
                   return lastpaid;
                 }
                 else {
                   var outtxt = '';
                   outtxt = deltaTimeStampHR(lastpaid,currenttimestamp());
                   return outtxt;
                 }
               }
               else {
                 if (type == 'sort') {
                   return 0;
                 }
                 else {
                   return 'Never/Unknown';
                 }
               }
            } },
            { data: null, render: function ( data, type, row) {
                 var activeseconds = parseInt(data.MasternodeActiveSeconds);
               if (type == 'sort') {
                 return activeseconds;
               } else if (activeseconds < 0) {
                 return 'Just now ('+activeseconds+')';
               }
               else {
                 return diffHR(activeseconds);
               }
            } },
            { data: null, render: function ( data, type, row) {
               if (type == 'sort') {
                 return data.MasternodeLastSeen;
               } else if (data.MasternodeLastSeen > 0) {
                 return deltaTimeStampHR(data.MasternodeLastSeen,currenttimestamp());
               } else {
                 return '';
               }
            } },
            { data: null, render: function ( data, type, row) {
               var activecount = parseInt(data.ActiveCount);
               var inactivecount = parseInt(data.InactiveCount);
               var unlistedcount = parseInt(data.UnlistedCount);
               var total = activecount+inactivecount+unlistedcount;
               var ratio = activecount / total;
               var result = ratio;
               if (type == 'sort') {
                 result =  ratio;
               } else {
                 if ( ratio == 1 ) {
                   result = 'Active';
                 } else if ( ratio == 0 ) {
                   result = 'Inactive';
                 } else if ( unlistedcount > 0 ) {
                   result = 'Partially Unlisted';
                 } else {
                   result = 'Partially Inactive';
                 }
                 result += ' ('+Math.round(ratio*100)+'%)';
               }
               return result;
            } },
        ],
        "createdRow": function ( row, data, index ) {
            dashversioncheck = getLatestdashVersion();
            var color = '#FF8F8F';
            if ( data.Portcheck == false ) {
                color = '#8F8F8F';
            }
            else {
                if (( data.Portcheck.Result == 'open' ) || ( data.Portcheck.Result == 'rogue' )) {
                    color = '#8FFF8F';
                } else if (data.Portcheck.Result == 'unknown') {
                    color = '#8F8F8F';
                }
            }
            $('td',row).eq(3).css({"background-color":color,"text-align": "center"});
            color = '#8FFF8F';
            if ( data.Balance.Value < 1000 ) {
              color = '#FF8F8F';
            }
            $('td',row).eq(6).css({"background-color":color,"text-align": "right"});
            var versioninfo = "Unknown";
            if ((data.Portcheck != false) && data.Portcheck.hasOwnProperty("SubVer")) {
                if ((data.Portcheck.SubVer.length > 10) && (data.Portcheck.SubVer.substring(0, 9) == '/Satoshi:') && (data.Portcheck.SubVer.substring(data.Portcheck.SubVer.length - 1) == '/')) {
                    versioninfo = data.Portcheck.SubVer.substring(9, data.Portcheck.SubVer.indexOf('/', 10));
                }
                else if ((data.Portcheck.SubVer.length > 7) && (data.Portcheck.SubVer.substring(0, 6) == '/Core:') && (data.Portcheck.SubVer.substring(data.Portcheck.SubVer.length - 1) == '/')) {
                    versioninfo = data.Portcheck.SubVer.substring(6, data.Portcheck.SubVer.indexOf('/', 6));
                }
                else if ((data.Portcheck.SubVer.length > 11) && (data.Portcheck.SubVer.substring(0, 11) == '/Dash Core:') && (data.Portcheck.SubVer.substring(data.Portcheck.SubVer.length - 1) == '/')) {
                    versioninfo = data.Portcheck.SubVer.substring(11, data.Portcheck.SubVer.indexOf('/', 11));
                }
            }
            if ( versioninfo == "Unknown" ) {
              color = '#8F8F8F';
            }
            else if ( ( versioninfo.substring(0,5) == "0.10." ) || ( versioninfo.substring(0,7) == "0.11." ) ) {
              color = '#FF8F8F';
            }
            else if ( versioninfo == dashversioncheck ) {
              color = '#8FFF8F';
            }
            else {
              color = '#FFFF8F';
            }
            $('td',row).eq(4).css({"background-color":color});
            var curprotocol = parseInt(data.MasternodeProtocol);
            if ( curprotocol < 70102 ) {
              color = '#FF8F8F';
            }
            else if ( curprotocol == dashmaxprotocol ) {
              color = '#8FFF8F';
            }
            else {
              color = '#FFFF8F';
            }
            $('td',row).eq(5).css({"background-color":color,"text-align": "right"});
            var total = data.ActiveCount+data.InactiveCount+data.UnlistedCount;
            var activecount = parseInt(data.ActiveCount);
            var inactivecount = parseInt(data.InactiveCount);
            var unlistedcount = parseInt(data.UnlistedCount);
            var total = activecount+inactivecount+unlistedcount;
            var ratio = activecount / total;
            if (ratio == 1) {
              color = '#8FFF8F';
            } else if (ratio == 0) {
              color = '#FF8F8F';
            } else if (ratio < 0.5) {
              color = '#ffcb8f';
            } else {
              color = '#FFFF8F';
            }
            $('td',row).eq(10).css({"background-color":color,"text-align": "center"});
        }
    } );
   setTimeout(tableMNListRefresh, 300000);

  //mnpaymentsRefresh();

});
