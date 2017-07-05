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

// Dash Ninja Front-End (dashninja-fe) - Governance
// By elberethzone / https://www.dash.org/forum/members/elbereth.175/

var dashninjaversion = '1.0.4';
var tableGovernance = null;
var tableBudgetsProjection = null;
var tableSuperBlocks = null;
var tableSuperBlocksExpected = null;
var tableMonthlyBudgetPayments = null;
var latestblock = null;
var superblock = null;
var totalmns = 0;
var latestblock2 = null;
var superblock2 = null;
var totalmns2 = 0;
var arrayMonthlyPayments = [];

$.fn.dataTable.ext.errMode = 'throw';

if (typeof dashninjatestnet === 'undefined') {
    var dashninjatestnet = 0;
}
if (typeof dashninjatestnethost !== 'undefined') {
    if (window.location.hostname == dashninjatestnethost) {
        dashninjatestnet = 1;
        $('a[name=menuitemexplorer]').attr("href", "https://"+dashninjatestnetexplorer);
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

if (typeof dashninjatxexplorer === 'undefined') {
    var dashninjatxexplorer = [[],[]];
}
if (typeof dashninjatxexplorer[0] === 'undefined') {
    dashninjatxexplorer[0] = [];
}
if (typeof dashninjatxexplorer[1] === 'undefined') {
    dashninjatxexplorer[1] = [];
}

function tableGovernanceRefresh(){
    tableGovernance.api().ajax.reload();
    // Set it to refresh in 60sec
    setTimeout(tableGovernanceRefresh, 150000);
};

function tableSuperBlocksRefresh(){
    tableSuperBlocks.api().ajax.reload();
    // Set it to refresh in 60sec
    setTimeout(tableSuperBlocksRefresh, 150000);
};

function tableSuperBlocksExpectedRefresh(){
    tableSuperBlocksExpected.api().ajax.reload();
    // Set it to refresh in 60sec
    setTimeout(tableSuperBlocksExpectedRefresh, 150000);
};

$(document).ready(function(){

    $('#dashninjajsversion').text( dashninjaversion );

    if (dashninjatestnet == 1) {
        $('#testnetalert').show();
    }

    if (typeof dashninjator !== 'undefined') {
        $('a[name=dashninjatorurl]').attr("href", "http://"+dashninjator+"/governance.html").text( dashninjator+"/governance.html" );
        $('span[name=dashninjatordisplay]').show();
    }
    if (typeof dashninjai2p !== 'undefined') {
        $('a[name=dashninjai2purl]').attr("href", "http://" + dashninjai2p + "/governance.html").text(dashninjai2p + "/governance.html");
        $('span[name=dashninjai2pdisplay]').show();
    }

    $('#proposalsdetailtable').on('xhr.dt', function ( e, settings, json ) {

        var nextsuperblockdatetimestamp = json.data.stats.latestblock.BlockTime+(((json.data.stats.nextsuperblock.blockheight-json.data.stats.latestblock.BlockId)/553.85)*86400);

         // Calculate the established project total amounts
         var totalamount = 0.0;
         for (var bix in json.data.governanceproposals){
             if ((json.data.governanceproposals[bix].CachedFunding) && (json.data.governanceproposals[bix].EpochEnd > currenttimestamp()) && (json.data.governanceproposals[bix].EpochStart <= nextsuperblockdatetimestamp) && ((currenttimestamp() - json.data.governanceproposals[bix].LastReported) <= 3600)) {
                 totalamount+=json.data.governanceproposals[bix].PaymentAmount;
             }
         }

        // Show global stats
        $('#globalvalidbudget').text(json.data.stats.valid);
        $('#globalestablishedbudget').text(json.data.stats.funded);
        $('#globalestablishedbudgetamount').text(addCommas(Math.round(totalamount*100)/100)+' '+dashninjacoin[dashninjatestnet]);
        if ((json.data.stats.nextsuperblock.blockheight-1662)<=json.data.stats.latestblock.BlockId) {
            $('#globalnextvotelimitdate').text( "Current month vote is over!" );
            $('#globalnextvotelimitremaining').text("Too late...");
            $('#votedeadline').css('color', '');
        }
        else {
            var nextvotelimitdatetimestamp = json.data.stats.latestblock.BlockTime+(((json.data.stats.nextsuperblock.blockheight-1662-json.data.stats.latestblock.BlockId)/553.85)*86400);
            var datevotelimit = new Date(nextvotelimitdatetimestamp*1000);
            $('#globalnextvotelimitdate').text(datevotelimit.toLocaleString());
            $('#globalnextvotelimitremaining').text(deltaTimeStampHRlong(nextvotelimitdatetimestamp,currenttimestamp()));
            if ((nextvotelimitdatetimestamp - currenttimestamp()) <= 86400) {
                $('#votedeadline').css('color', 'red');
            }
            else {
                $('#votedeadline').css('color', 'green');
            }
        }
        var datesuperblock = new Date(nextsuperblockdatetimestamp*1000);
        $('#globalnextsuperblockdate').text(datesuperblock.toLocaleString());
        $('#globalnextsuperblockremaining').text(deltaTimeStampHRlong(nextsuperblockdatetimestamp,currenttimestamp()));
        $('#globalnextsuperblockid').text(json.data.stats.nextsuperblock.blockheight);
        $('#globalnextsuperblockamount').text(addCommas(Math.round(json.data.stats.nextsuperblock.estimatedbudgetamount*100)/100)+' '+dashninjacoin[dashninjatestnet]);
        $('#globalnextsuperblockunallocated').text(addCommas(Math.round((json.data.stats.nextsuperblock.estimatedbudgetamount-totalamount)*100)/100)+' '+dashninjacoin[dashninjatestnet]);


        // Store information for future use
        latestblock = json.data.stats.latestblock;
        superblock = json.data.stats.nextsuperblock;
        totalmns = json.data.stats.totalmns;

        if (tableSuperBlocksExpected !== null) {
            tableSuperBlocksExpected.api().ajax.reload();
        }
        else {
            tableSuperBlocksExpected = $('#superblocksexpectedtable').dataTable({
                ajax: {
                    url: "/api/governancetriggers?testnet=" + dashninjatestnet + "&onlyvalid=1&afterblockheight=" + latestblock.BlockId,
                    dataSrc: 'data.governancetriggers'
                },
                paging: false,
                order: [[0, "desc"]],
                columns: [
                    {
                        data: null, render: function (data, type, row) {
                        var outtxt = data.Hash;
                        return outtxt;
                    }
                    },
                    {
                        data: null, render: function (data, type, row) {
                        var outtxt = data.BlockHeight;
                        return outtxt;
                    }
                    },
                    {
                        data: null, render: function (data, type, row) {
                        var outtxt = data.PaymentPosition;
                        return outtxt;
                    }
                    },
                    {
                        data: null, render: function (data, type, row) {
                        if (type == "sort") {
                            return data.PaymentProposalName;
                        } else {
                            return '<a href="' + dashninjagovernanceproposaldetail[dashninjatestnet].replace('%%b%%', encodeURIComponent(data.PaymentProposalHash)) + '">' + data.PaymentProposalName + '</a>';
                        }
                    }
                    },
                    {
                        data: null, render: function (data, type, row) {
                        if (type == "sort") {
                            return data.PaymentAmount;
                        } else {
                            return addCommas(data.PaymentAmount.toFixed(3)) + " " + dashninjacoin[dashninjatestnet];
                        }
                    }
                    }
                ],
                createdRow: function (row, data, index) {
                }
            });
        }

        // Change the last refresh date
        var date = new Date();
        var n = date.toLocaleDateString();
        var time = date.toLocaleTimeString();
        $('#proposalsdetailtableLR').text( n + ' ' + time );
    } );
    tableGovernance = $('#proposalsdetailtable').dataTable( {
        ajax: { url: "/api/governanceproposals?testnet="+dashninjatestnet,
            dataSrc: 'data.governanceproposals' },
        paging: true,
        lengthMenu: [ [20, 50, 100, 200, -1], [20, 50, 100, 200, "All"] ],
        pageLength: 20,
        order: [[ 0, "desc" ]],
        columns: [
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.FirstReported;
                }
                else {
                    return timeSince((currenttimestamp() - data.FirstReported));
                }
            } },
            { data: null, render: function ( data, type, row ) {
                var outtxt = data.Name;
                if (type != 'sort') {
                    if (data.Name == "") {
                        outtxt = data.Hash;
                    }
                    outtxt = '<a href="'+dashninjagovernanceproposaldetail[dashninjatestnet].replace('%%b%%',data.Hash)+'">'+outtxt+'</a>';
//                    outtxt = '<a href="'+data.URL+'">'+outtxt+'</a>';
                }
                return outtxt;
            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.Hash;
                }
                else {
                    return '<span data-toggle="tooltip" title="'+data.Hash+'">'+data.Hash.substring(0,7)+'</span>';
                }

            } },
            { data: null, render: function ( data, type, row ) {
                var outtxt = "";
                if (type == 'sort') {
                    outtxt = data.CollateralHash;
                }
                else {

                    if (dashninjatxexplorer[dashninjatestnet].length > 0) {
                        var ix = 0;
                        for ( var i=0, ien=dashninjatxexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                            if (ix == 0) {
                                outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][0][0].replace('%%a%%',data.CollateralHash)+'">'+data.CollateralHash.substring(0,7)+'</a>';
                            }
                            else {
                                outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][i][0].replace('%%a%%',data.CollateralHash)+'">['+ix+']</a>';
                            }
                            ix++;
                        }
                    }
                    else {
                        outtxt = data.CollateralHash.substring(0,7);
                    }
                }
                return outtxt;
            } },
            { data: null, render: function ( data, type, row ) {
                var blockdatetimestamp = data.EpochStart;
                return (new Date(blockdatetimestamp*1000)).toLocaleDateString();
            } },
            { data: null, render: function ( data, type, row ) {
                var blockdatetimestamp = data.EpochEnd;
                return (new Date(blockdatetimestamp*1000)).toLocaleDateString();
            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.PaymentAmount;
                }
                else {
                    return addCommas(data.PaymentAmount.toFixed(2))+' '+dashninjacoin[dashninjatestnet];
                }

            } },
            { data: null, render: function ( data, type, row ) {
                var outtxt = "";
                if (type == 'sort') {
                    outtxt = data.PaymentAddress;
                }
                else {
                    if (dashninjaaddressexplorer[dashninjatestnet].length > 0) {
                        var ix = 0;
                        for ( var i=0, ien=dashninjaaddressexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                            if (ix == 0) {
                                outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][0][0].replace('%%a%%',data.PaymentAddress)+'">'+data.PaymentAddress+'</a>';
                            }
                            else {
                                outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][i][0].replace('%%a%%',data.PaymentAddress)+'">['+ix+']</a>';
                            }
                            ix++;
                        }
                    }
                    else {
                        outtxt = data.PaymentAddress;
                    }
                }
                return outtxt;
            } },
            { data: "Yes" },
            { data: "No" },
            { data: "Abstain" },
            { data: null, render: function ( data, type, row ) {
                var total = data.AbsoluteYes/totalmns;
                if (type == 'sort') {
                    return total;
                }
                else {
                    var number = Math.round( total * 10000 ) / 100;
                    return (number.toFixed(2) +'%');
                }
            } },
            { data: null, render: function ( data, type, row ) {
                if (data.BlockchainValidity) {
                    return "Yes";
                }
                else {
                    return "No";
                }
            } },
            { data: null, render: function ( data, type, row ) {
                if (data.CachedFunding) {
                    return "Yes";
                }
                else {
                    return "No";
                }
            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.LastReported;
                }
                else {
                    return timeSince((currenttimestamp() - data.LastReported));
                }
            } },
        ],
        "createdRow": function ( row, data, index ) {
            $('td',row).eq(6).css({"text-align": "right"});
            var totalvotesratio = data.AbsoluteYes/totalmns;
            var isalloted = false;
            var color = '#8F8F8F';
            if (data.BlockchainValidity) {
                if (totalvotesratio < 0.1000) {
                    color = '#FF8F8F';
                }
                else if (totalvotesratio <= 0.25) {
                    color = '#ffcb8f';
                    isalloted = true;
                }
                else if (totalvotesratio <= 0.5) {
                    color = '#FFFF8F';
                    isalloted = true;
                }
                else {
                    color = '#8FFF8F';
                    isalloted = true;
                }
            }
            $('td',row).eq(11).css({"background-color":color,"text-align": "right"});
            if (data.BlockchainValidity) {
                if (isalloted) {
                    color = '#8FFF8F';
                }
                else {
                    color = '#FFFF8F';
                }
            }
            else {
                color = '#8F8F8F';
            }
            $('td', row).eq(1).css({"background-color": color, "text-align": "left"});
            // if ((currenttimestamp() - data.LastReported) > 3600) {
            //     color = '#8F8F8F';
            // }
            // else {
            //     if (isalloted) {
            //         color = '#8FFF8F';
            //     }
            //     else {
            //         if (data.IsEstablished) {
            //             color = '#FFFF8F';
            //         }
            //         else {
            //             if (data.IsValid) {
            //                 color = '#FF8F8F';
            //             }
            //             else {
            //                 color = '#ffcb8f';
            //             }
            //         }
            //     }
            // }
            // $('td',row).eq(1).css({"background-color":color});
            // color = '#FF8F8F';
            // if (data.BlockStart == superblock.blockheight) {
            //     color = '#FFFF8F';
            // } else if (data.BlockStart > superblock.blockheight) {
            //     color = '#8FFF8F';
            // }
            // $('td',row).eq(4).css({"background-color":color,"text-align": "right"});
            // $('td',row).eq(5).css({"text-align": "center"});
            // $('td',row).eq(6).css({"text-align": "right"});
            // $('td',row).eq(7).css({"text-align": "center"});
            // $('td',row).eq(8).css({"text-align": "right"});
            // $('td',row).eq(9).css({"text-align": "right"});
            // $('td',row).eq(10).css({"text-align": "right"});
            // $('td',row).eq(11).css({"text-align": "right"});
            // $('td',row).eq(13).css({"text-align": "right"});
            // $('td',row).eq(14).css({"text-align": "right"});
            if (data.BlockchainValidity) {
                color = '#8FFF8F';
            }
            else {
                color = '#FF8F8F';
            }
            $('td',row).eq(12).css({"background-color":color,"text-align": "center"});
            if (data.CachedFunding) {
                color = '#8FFF8F';
            }
            else {
                color = '#FF8F8F';
            }
            $('td',row).eq(13).css({"background-color":color,"text-align": "center"});
            $('td',row).eq(14).css({"text-align": "center"});
        }
    } );
    setTimeout(tableGovernanceRefresh, 150000);

    $('#superblockstable').on('xhr.dt', function ( e, settings, json ) {
        // Change the last refresh date
        var date = new Date();
        var n = date.toLocaleDateString();
        var time = date.toLocaleTimeString();
        $('#superblockstableLR').text( n + ' ' + time );

/*        var monthlypayments = {};
        var tmpDate;
        var xaxis = [];
        var paidbudgets = [];

        for (var bix in json.data.blocks){
            tmpDate = new Date(json.data.blocks[bix].BlockTime*1000);
            curmonth = tmpDate.getFullYear().toString()+"-"+pad((tmpDate.getMonth()+1).toString(),2,"0",STR_PAD_LEFT);
            if (!monthlypayments.hasOwnProperty(curmonth)) {
                monthlypayments[curmonth] = {};
            }
            monthlypayments[curmonth][json.data.blocks[bix].SuperBlockBudgetName] = json.data.blocks[bix].BlockMNValue;
            if ($.inArray(curmonth,xaxis) == -1) {
                xaxis.push(curmonth);
            }
            if ($.inArray(json.data.blocks[bix].SuperBlockBudgetName,paidbudgets) == -1) {
                paidbudgets.push(json.data.blocks[bix].SuperBlockBudgetName);
            }
        }

        xaxis.sort();
        paidbudgets.sort();

        for (var x in xaxis) {
            var thismonth = 0.0;
            for (var b in monthlypayments[xaxis[x]]) {
                thismonth += monthlypayments[xaxis[x]][b];
            }
            arrayMonthlyPayments.push([xaxis[x], thismonth]);
        }

        var series = [];

        for (var p in paidbudgets) {
            var thisserie = {name: paidbudgets[p],
                             data: []};
            for (var x in xaxis) {
                if (monthlypayments[xaxis[x]].hasOwnProperty(paidbudgets[p])) {
                    thisserie.data.push(monthlypayments[xaxis[x]][paidbudgets[p]]);
                }
                else {
                    thisserie.data.push(null);
                }
            }
            series.push(thisserie);
        }

        if (tableMonthlyBudgetPayments !== null) {
            tableMonthlyBudgetPayments.api().rows().invalidate().draw();
        }
        else {
            tableMonthlyBudgetPayments = $('#monthlybudgetpaymentstable').dataTable({
                data: arrayMonthlyPayments,
                paging: false,
                order: [[0, "desc"]],
                columns: [
                    {title: "Month"},
                    { data: null, render: function ( data, type, row ) {
                        var outtxt = addCommas(data[1].toFixed(3))+" "+dashninjacoin[dashninjatestnet];
                        return outtxt;
                    } }
                ]
            });
        }

        $('#chartpayments').highcharts({
            chart: {
                type: 'area'
            },
            title: {
                text: 'Monthly Budget Payments',
                x: -20 //center
            },
            xAxis: {
                categories: xaxis
            },
            yAxis: {
                title: {
                    text: 'Amount (DASH)'
                },
                plotLines: [{
                    value: 0,
                    width: 1,
                    color: '#808080'
                }]
            },
            tooltip: {
                valueSuffix: 'DASH'
            },
            legend: {
                layout: 'vertical',
                align: 'right',
                verticalAlign: 'middle',
                borderWidth: 0
            },
            plotOptions: {
                area: {
                    stacking: 'normal',
                    lineColor: '#666666',
                    lineWidth: 1,
                    marker: {
                        lineWidth: 1,
                        lineColor: '#666666'
                    }
                }
            },
            series: series
        });
        */

    } );
    tableSuperBlocks = $('#superblockstable').dataTable( {
        ajax: { url: "/api/blocks/superblocks?testnet="+dashninjatestnet,
            dataSrc: 'data.superblocks' },
        paging: true,
        lengthMenu: [ [20, 50, 100, 200, -1], [20, 50, 100, 200, "All"] ],
        pageLength: 20,
        order: [[ 0, "desc" ]],
        columns: [
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.BlockTime;
                }
                else {
//                return deltaTimeStampHR(currenttimestamp(),data.BlockTime);
                    return timeSince((currenttimestamp() - data.BlockTime));
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
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.SuperBlockProposalName;
                } else {
                    if (data.SuperBlockVersion == 1) {
                        return '<a href="' + dashninjabudgetdetail[dashninjatestnet].replace('%%b%%', encodeURIComponent(data.SuperBlockProposalName)) + '">' + data.SuperBlockProposalName + '</a>';
                    }
                    else {
                        return '<a href="' + dashninjagovernanceproposaldetail[dashninjatestnet].replace('%%b%%', encodeURIComponent(data.SuperBlockProposalHash)) + '">' + data.SuperBlockProposalName + '</a>';
                    }
                }
              }
            },
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.SuperBlockPaymentAmount;
                } else {
                    return addCommas(data.SuperBlockPaymentAmount.toFixed(3))+" "+dashninjacoin[dashninjatestnet];
                }
              }
            },
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.SuperBlockPaymentAddress;
                } else {
                    var outtxt = "";
                    var ix = 0;
                    for ( var i=0, ien=dashninjaaddressexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                        if (ix == 0) {
                            outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][0][0].replace('%%a%%',data.SuperBlockPaymentAddress)+'">'+data.SuperBlockPaymentAddress+'</a>';
                        }
                        else {
                            outtxt += '<a href="'+dashninjaaddressexplorer[dashninjatestnet][i][0].replace('%%a%%',data.SuperBlockPaymentAddress)+'">['+ix+']</a>';
                        }
                        ix++;
                    }
                    return outtxt;
                }
              }
            }
        ],
        createdRow: function ( row, data, index ) {
        }
    } );
    setTimeout(tableSuperBlocksRefresh, 150000);

    $('#superblocksexpectedtable').on('xhr.dt', function ( e, settings, json ) {
        // Change the last refresh date
        var date = new Date();
        $('#superblocksexpectedtableLR').text( date.toLocaleString() );
    } );

});
