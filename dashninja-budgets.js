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

// Dash Ninja Front-End (dashninja-fe) - Budgets
// By elberethzone / https://dashtalk.org/members/elbereth.175/

var dashninjaversion = '1.5.1';
var tableBudgets = null;
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

function tableBudgetsRefresh(){
    tableBudgets.api().ajax.reload();
    // Set it to refresh in 60sec
    setTimeout(tableBudgetsRefresh, 150000);
};

function tableBudgetsProjectionRefresh(){
    tableBudgets.api().ajax.reload();
    // Set it to refresh in 60sec
    setTimeout(tableBudgetsProjectionRefresh, 150000);
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

    $('#budgetsdetailtable').on('xhr.dt', function ( e, settings, json ) {
        // Calculate the established project total amounts
        var totalamount = 0.0;
        for (var bix in json.data.budgets){
            if ((json.data.budgets[bix].IsEstablished) && (json.data.budgets[bix].RemainingPaymentCount >0) && ((currenttimestamp() - json.data.budgets[bix].LastReported) <= 3600)) {
                totalamount+=json.data.budgets[bix].MonthlyPayment;
            }
        }

        // Show global stats
        $('#globalvalidbudget').text(json.data.stats.budgetvalid);
        $('#globalestablishedbudget').text(json.data.stats.budgetvalid);
        $('#globalestablishedbudgetamount').text(addCommas(totalamount)+' '+dashninjacoin[dashninjatestnet]);
        var nextsuperblockdatetimestamp = json.data.stats.latestblock.BlockTime+(((json.data.stats.nextsuperblock.blockheight-json.data.stats.latestblock.BlockId)/553)*86400);
        var datesuperblock = new Date(nextsuperblockdatetimestamp*1000);
        $('#globalnextsuperblockdate').text(datesuperblock.toLocaleString());
        $('#globalnextsuperblockremaining').text(deltaTimeStampHRlong(nextsuperblockdatetimestamp,currenttimestamp()));
        $('#globalnextsuperblockid').text(json.data.stats.nextsuperblock.blockheight);
        $('#globalnextsuperblockamount').text(addCommas(Math.round(json.data.stats.nextsuperblock.estimatedbudgetamount*100)/100)+' '+dashninjacoin[dashninjatestnet]);
//        $('#globalnextsuperblockunallocated').text(addCommas(Math.round((json.data.stats.nextsuperblock.estimatedbudgetamount-totalamount)*100)/100)+' '+dashninjacoin[dashninjatestnet]);

        // Store information for future use
        latestblock = json.data.stats.latestblock;
        superblock = json.data.stats.nextsuperblock;
        totalmns = json.data.stats.totalmns;

        // Change the last refresh date
        var date = new Date();
        var n = date.toLocaleDateString();
        var time = date.toLocaleTimeString();
        $('#budgetstableLR').text( n + ' ' + time );
    } );
    tableBudgets = $('#budgetsdetailtable').dataTable( {
        ajax: { url: "/api/budgets?testnet="+dashninjatestnet,
            dataSrc: 'data.budgets' },
        paging: false,
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
                var outtxt = data.ID;
                if (type != 'sort') {
                    outtxt = '<a href="'+dashninjabudgetdetail[dashninjatestnet].replace('%%b%%',data.ID)+'">'+data.ID+'</a>';
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
                    outtxt = data.FeeHash;
                }
                else {

                    if (dashninjatxexplorer[dashninjatestnet].length > 0) {
                        var ix = 0;
                        for ( var i=0, ien=dashninjatxexplorer[dashninjatestnet].length ; i<ien ; i++ ) {
                            if (ix == 0) {
                                outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][0][0].replace('%%a%%',data.FeeHash)+'">'+data.FeeHash.substring(0,7)+'</a>';
                            }
                            else {
                                outtxt += '<a href="'+dashninjatxexplorer[dashninjatestnet][i][0].replace('%%a%%',data.FeeHash)+'">['+ix+']</a>';
                            }
                            ix++;
                        }
                    }
                    else {
                        outtxt = data.FeeHash.substring(0,7);
                    }
                }
                return outtxt;
            } },
            { data: "BlockStart" },
            { data: null, render: function ( data, type, row ) {
                var blockdatetimestamp = latestblock.BlockTime+(((data.BlockStart-latestblock.BlockId)/553)*86400);
                return (new Date(blockdatetimestamp*1000)).toLocaleDateString();
            } },
            { data: "BlockEnd" },
            { data: null, render: function ( data, type, row ) {
                var blockdatetimestamp = latestblock.BlockTime+(((data.BlockEnd-latestblock.BlockId)/553)*86400);
                return (new Date(blockdatetimestamp*1000)).toLocaleDateString();
            } },
            { data: "TotalPaymentCount" },
            { data: "RemainingPaymentCount" },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.TotalPayment;
                }
                else {
                    return addCommas(data.TotalPayment+' '+dashninjacoin[dashninjatestnet]);
                }

            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.MonthlyPayment;
                }
                else {
                    return addCommas(data.MonthlyPayment+' '+dashninjacoin[dashninjatestnet]);
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
            { data: "Yeas" },
            { data: "Nays" },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.Ratio;
                }
                else {
                    return (Math.round( data.Ratio * 10000 ) / 100) +'%';
                }
            } },
            { data: null, render: function ( data, type, row ) {
                var total = (data.Yeas-data.Nays)/totalmns;
                if (type == 'sort') {
                    return total;
                }
                else {
                    return (Math.round( total * 10000 ) / 100) +'%';
                }
            } },
            { data: null, render: function ( data, type, row ) {
                if (data.IsEstablished) {
                    return "Yes";
                }
                else {
                    return "No";
                }
            } },
            { data: null, render: function ( data, type, row ) {
                if (data.IsValid) {
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
            $('td',row).eq(0).css({"text-align": "center"});
            var totalvotesratio = (data.Yeas-data.Nays)/totalmns;
            var isalloted = false;
            var color = '#8FFF8F';
            if (totalvotesratio < 0.1) {
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
            $('td',row).eq(16).css({"background-color":color,"text-align": "right"});
            if ((currenttimestamp() - data.LastReported) > 3600) {
                color = '#8F8F8F';
            }
            else {
                if (isalloted) {
                    color = '#8FFF8F';
                }
                else {
                    if (data.IsEstablished) {
                        color = '#FFFF8F';
                    }
                    else {
                        if (data.IsValid) {
                            color = '#FF8F8F';
                        }
                        else {
                            color = '#ffcb8f';
                        }
                    }
                }
            }
            $('td',row).eq(1).css({"background-color":color});
            color = '#FF8F8F';
            if (data.BlockStart == superblock.blockheight) {
                color = '#FFFF8F';
            } else if (data.BlockStart > superblock.blockheight) {
                color = '#8FFF8F';
            }
            $('td',row).eq(4).css({"background-color":color,"text-align": "right"});
            $('td',row).eq(5).css({"text-align": "center"});
            $('td',row).eq(6).css({"text-align": "right"});
            $('td',row).eq(7).css({"text-align": "center"});
            $('td',row).eq(8).css({"text-align": "right"});
            $('td',row).eq(9).css({"text-align": "right"});
            $('td',row).eq(10).css({"text-align": "right"});
            $('td',row).eq(11).css({"text-align": "right"});
            $('td',row).eq(13).css({"text-align": "right"});
            $('td',row).eq(14).css({"text-align": "right"});
            if (data.Ratio <= 0.5) {
                color = '#FF8F8F';
            }
            else if (data.Ratio <= 0.75) {
                color = '#FFFF8F';
            }
            else {
                color = '#8FFF8F';
            }
            $('td',row).eq(15).css({"background-color":color,"text-align": "right"});
            if (data.IsEstablished) {
                color = '#8FFF8F';
            }
            else {
                color = '#FF8F8F';
            }
            $('td',row).eq(17).css({"background-color":color,"text-align": "center"});
            if (data.IsValid) {
                color = '#8FFF8F';
            }
            else {
                color = '#FF8F8F';
            }
            $('td',row).eq(18).css({"background-color":color,"text-align": "center"});
            $('td',row).eq(19).css({"text-align": "center"});
        }
    } );
    setTimeout(tableBudgetsRefresh, 150000);

    $('#budgetsprojectiondetailtable').on('xhr.dt', function ( e, settings, json ) {
        // Show global stats
        $('#globalallotedbudget').text(json.data.stats.budgetalloted);

        // Calculate the alloted project total amounts
        var totalamount = 0.0;
        for (var bix in json.data.budgetsprojection){
            if ((json.data.budgetsprojection[bix].IsValid) && (json.data.budgetsprojection[bix].RemainingPaymentCount >0) && ((currenttimestamp() - json.data.budgetsprojection[bix].LastReported) <= 3600)) {
                totalamount+=json.data.budgetsprojection[bix].Alloted;
            }
        }
        $('#globalallotedbudgetamount').text(addCommas(totalamount)+' '+dashninjacoin[dashninjatestnet]);
        $('#globalnextsuperblockunallocated').text(addCommas(Math.round((json.data.stats.nextsuperblock.estimatedbudgetamount-totalamount)*100)/100)+' '+dashninjacoin[dashninjatestnet]);

        // Store information for future use
        latestblock2 = json.data.stats.latestblock;
        superblock2 = json.data.stats.nextsuperblock;
        totalmns2 = json.data.stats.totalmns;

        // Change the last refresh date
        var date = new Date();
        var n = date.toLocaleDateString();
        var time = date.toLocaleTimeString();
        $('#budgetsprojectiontableLR').text( n + ' ' + time );
    } );
    tableBudgetsProjection = $('#budgetsprojectiondetailtable').dataTable( {
        ajax: { url: "/api/budgetsprojection?testnet="+dashninjatestnet,
            dataSrc: 'data.budgetsprojection' },
        paging: false,
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
                var outtxt = data.ID;
                if (type != 'sort') {
                    outtxt = '<a href="'+dashninjabudgetdetail[dashninjatestnet].replace('%%b%%',data.ID)+'">'+data.ID+'</a>';
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
            { data: "BlockStart" },
            { data: null, render: function ( data, type, row ) {
                var blockdatetimestamp = latestblock2.BlockTime+(((data.BlockStart-latestblock2.BlockId)/553)*86400);
                return (new Date(blockdatetimestamp*1000)).toLocaleDateString();
            } },
            { data: "BlockEnd" },
            { data: null, render: function ( data, type, row ) {
                var blockdatetimestamp = latestblock2.BlockTime+(((data.BlockEnd-latestblock2.BlockId)/553)*86400);
                return (new Date(blockdatetimestamp*1000)).toLocaleDateString();
            } },
            { data: "TotalPaymentCount" },
            { data: "RemainingPaymentCount" },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.TotalPayment;
                }
                else {
                    return addCommas(data.TotalPayment+' '+dashninjacoin[dashninjatestnet]);
                }

            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.MonthlyPayment;
                }
                else {
                    return addCommas(data.MonthlyPayment+' '+dashninjacoin[dashninjatestnet]);
                }

            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.Alloted;
                }
                else {
                    return addCommas(data.Alloted+' '+dashninjacoin[dashninjatestnet]);
                }

            } },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.TotalBudgetAlloted;
                }
                else {
                    return addCommas(data.TotalBudgetAlloted+' '+dashninjacoin[dashninjatestnet]);
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
            { data: "Yeas" },
            { data: "Nays" },
            { data: null, render: function ( data, type, row ) {
                if (type == 'sort') {
                    return data.Ratio;
                }
                else {
                    return (Math.round( data.Ratio * 10000 ) / 100) +'%';
                }
            } },
            { data: null, render: function ( data, type, row ) {
                var total = (data.Yeas-data.Nays)/totalmns2;
                if (type == 'sort') {
                    return total;
                }
                else {
                    return (Math.round( total * 10000 ) / 100) +'%';
                }
            } },
            { data: null, render: function ( data, type, row ) {
                if (data.IsValid) {
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
            $('td',row).eq(0).css({"text-align": "center"});
            var color = '#FF8F8F';
            if (data.BlockStart == superblock2.blockheight) {
                color = '#FFFF8F';
            } else if (data.BlockStart > superblock2.blockheight) {
                color = '#8FFF8F';
            }
            if ((currenttimestamp() - data.LastReported) > 3600) {
                color = '#FF8F8F';
            }
            else {
                if (data.IsValid) {
                    color = '#8FFF8F';
                }
                else {
                    color = '#ffff8f';
                }
            }
            $('td',row).eq(1).css({"background-color":color});
            $('td',row).eq(3).css({"background-color":color,"text-align": "right"});
            $('td',row).eq(4).css({"text-align": "center"});
            $('td',row).eq(5).css({"text-align": "right"});
            $('td',row).eq(6).css({"text-align": "center"});
            $('td',row).eq(7).css({"text-align": "right"});
            $('td',row).eq(8).css({"text-align": "right"});
            $('td',row).eq(9).css({"text-align": "right"});
            $('td',row).eq(10).css({"text-align": "right"});
            $('td',row).eq(11).css({"text-align": "right"});
            $('td',row).eq(12).css({"text-align": "right"});
            $('td',row).eq(14).css({"text-align": "right"});
            $('td',row).eq(15).css({"text-align": "right"});
            if (data.Ratio <= 0.5) {
                color = '#FF8F8F';
            }
            else if (data.Ratio <= 0.75) {
                color = '#FFFF8F';
            }
            else {
                color = '#8FFF8F';
            }
            $('td',row).eq(16).css({"background-color":color,"text-align": "right"});
            var totalvotesratio = (data.Yeas-data.Nays)/totalmns2;
            if (totalvotesratio < 0.1) {
                color = '#FF8F8F';
            }
            else if (totalvotesratio <= 0.25) {
                color = '#ffcb8f';
            }
            else if (totalvotesratio <= 0.5) {
                color = '#FFFF8F';
            }
            else {
                color = '#8FFF8F';
            }
            $('td',row).eq(17).css({"background-color":color,"text-align": "right"});
            if (data.IsValid) {
                color = '#8FFF8F';
            }
            else {
                color = '#FF8F8F';
            }
            $('td',row).eq(18).css({"background-color":color,"text-align": "center"});
            $('td',row).eq(19).css({"text-align": "center"});
        }
    } );
    setTimeout(tableBudgetsProjectionRefresh, 150000);

    $('#superblockstable').on('xhr.dt', function ( e, settings, json ) {
        // Change the last refresh date
        var date = new Date();
        var n = date.toLocaleDateString();
        var time = date.toLocaleTimeString();
        $('#superblockstableLR').text( n + ' ' + time );

        var monthlypayments = {};
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

    } );
    tableSuperBlocks = $('#superblockstable').dataTable( {
        ajax: { url: "/api/blocks?testnet="+dashninjatestnet+"&onlysuperblocks=1",
            dataSrc: 'data.blocks' },
        paging: false,
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
            { data: "BlockDifficulty" },
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.SuperBlockBudgetName;
                } else {
                    return '<a href="' + dashninjabudgetdetail[dashninjatestnet].replace('%%b%%',encodeURIComponent(data.SuperBlockBudgetName)) + '">' + data.SuperBlockBudgetName + '</a>';
                }
              }
            },
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.BlockMNValue;
                } else {
                    return addCommas(data.BlockMNValue.toFixed(3))+" "+dashninjacoin[dashninjatestnet];
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
    tableSuperBlocksExpected = $('#superblocksexpectedtable').dataTable( {
        ajax: { url: "/api/budgetsexpected?testnet="+dashninjatestnet,
            dataSrc: 'data.budgetsexpected' },
        paging: false,
        order: [[ 0, "desc" ]],
        columns: [
            { data: null, render: function ( data, type, row ) {
                var outtxt = data.BlockId;
                return outtxt;
            } },
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.BlockProposal;
                } else {
                    return '<a href="' + dashninjabudgetdetail[dashninjatestnet].replace('%%b%%',encodeURIComponent(data.BlockProposal)) + '">' + data.BlockProposal + '</a>';
                }
            }
            },
            { data: null, render: function ( data, type, row ) {
                if (type == "sort") {
                    return data.MonthlyPayment;
                } else {
                    return addCommas(data.MonthlyPayment.toFixed(3))+" "+dashninjacoin[dashninjatestnet];
                }
            }
            }
        ],
        createdRow: function ( row, data, index ) {
        }
    } );
    setTimeout(tableSuperBlocksExpectedRefresh, 150000);

});
