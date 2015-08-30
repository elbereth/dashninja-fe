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
 along with Foobar.  If not, see <http://www.gnu.org/licenses/>.

 */

// Dash Ninja Front-End (dashninja-fe) - Budgets
// By elberethzone / https://dashtalk.org/members/elbereth.175/

var dashninjaversion = '1.0.0';
var tableBudgets = null;
var latestblock = null;
var superblock = null;
var totalmns = 0;

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

$(document).ready(function(){

    $('#dashninjajsversion').text( dashninjaversion );

    if (dashninjatestnet == 1) {
        $('#testnetalert').show();
    }

    $('#budgetsdatailtable').on('xhr.dt', function ( e, settings, json ) {
        // Show global stats
        $('#globalvalidbudget').text(json.data.stats.budgetvalid);
        $('#globalestablishedbudget').text(json.data.stats.budgetvalid);
        var nextsuperblockdatetimestamp = json.data.stats.latestblock.BlockTime+(((json.data.stats.nextsuperblock.blockheight-json.data.stats.latestblock.BlockId)/553)*86400);
        var datesuperblock = new Date(nextsuperblockdatetimestamp*1000);
        $('#globalnextsuperblockdate').text(datesuperblock.toLocaleDateString()+' '+datesuperblock.toLocaleTimeString());
        $('#globalnextsuperblockremaining').text(deltaTimeStampHRlong(nextsuperblockdatetimestamp,currenttimestamp()));
        $('#globalnextsuperblockid').text(json.data.stats.nextsuperblock.blockheight);
        $('#globalnextsuperblockamount').text(addCommas(json.data.stats.nextsuperblock.estimatedbudgetamount)+' '+dashninjacoin[dashninjatestnet]);

        latestblock = json.data.stats.latestblock;
        superblock = json.data.stats.nextsuperblock;
        totalmns = json.data.stats.totalmns;

        // Change the last refresh date
        var date = new Date();
        var n = date.toLocaleDateString();
        var time = date.toLocaleTimeString();
        $('#budgetstableLR').text( n + ' ' + time );
    } );
    tableBudgets = $('#budgetsdatailtable').dataTable( {
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
                if (type == 'sort') {
                    return data.ID;
                }
                else {
                    return '<a href="'+data.URL+'">'+data.ID+'</a>';
                }

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
                var total = data.Yeas+data.Nays+data.Abstains;
                if (type == 'sort') {
                    return total/totalmns;
                }
                else {
                    return (Math.round( total/totalmns * 10000 ) / 100) +'%';
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
            var color = '#FF8F8F';
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
            var totalvotesratio = (data.Yeas+data.Nays+data.Abstains)/totalmns;
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
            $('td',row).eq(16).css({"background-color":color,"text-align": "right"});
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

});
