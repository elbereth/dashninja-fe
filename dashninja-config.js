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

// Either indicate if we are we on testnet (=1) or on mainnet (=0)
//var dashninjatestnet = 0;
// OR indicate the hostname for testnet (if the hostname the page is running is equal to this, it will switch to testnet)
var dashninjatestnethost = 'test.dashninja.pl';
var dashninjatestnetexplorer = 'test.explorer.dashninja.pl';

// Tor onion hostname
var dashninjator = 'seuhd5sihasshuqh.onion';
var dashninjatestnettor = 'gycv32vrbvhfohjj.onion';
var dashninjai2p = 'dzjzoefy7fx57h5xkdknikvfv3ckbxu2bx5wryn6taud343g2jma.b32.i2p';
var dashninjatestneti2p = 'hkttp5yfsmmmtsgynadotlk6t3ppsuaj274jzipj4fe7cko3whza.b32.i2p';

// Coin logos
var dashninjacoin = ['DASH','tDASH'];

// URLs
// Block info
// ["https://explorer.dashninja.pl/block/%%b%%","elberethzone's Dash Blockchain Explorer"]
var dashninjablockexplorer = [[["http://chainz.cryptoid.info/dash/block.dws?%%b%%.htm","cryptoID Dash Blockchain Explorer"]],
                          [["https://test.explorer.dashninja.pl/block/%%b%%","Dash Ninja Testnet Blockchain Explorer"],
                           ["https://test.insight.dash.siampm.com/block/%%b%%","Alternate Testnet Dash Blockchain Explorer"]]];

// Address info
var dashninjamndetail = [[["/mndetails.html?mnpubkey=%%a%%","Dash Ninja Masternode Detail"],
                          ["https://www.dashcentral.org/masternodes/%%a%%","Dash Central Masternode Monitoring"]],
                         [["/mndetails.html?mnpubkey=%%a%%","Dash Ninja Testnet Masternode Detail"]]];
var dashninjamndetailvin = [[["/mndetails.html?mnoutput=%%a%%","Dash Ninja Masternode Detail"]],
                            [["/mndetails.html?mnoutput=%%a%%","Dash Ninja Testnet Masternode Detail"]]];

// ["https://explorer.dashninja.pl/address/%%a%%","elberethzone's Dash Blockchain Explorer"],
var dashninjaaddressexplorer = [[["https://chainz.cryptoid.info/dash/address.dws?%%a%%.htm","cryptoID Dash Blockchain Explorer"]],
                                [["https://test.explorer.dashninja.pl/address/%%a%%","Dash Ninja Testnet Blockchain Explorer"],
                                 ["https://test.insight.dash.siampm.com/address/%%a%%","Alternate Testnet Dash Blockchain Explorer"]]];
// ["http://explorer.dashninja.pl/tx/%%a%%","elberethzone's Dash Blockchain Explorer"],
var dashninjatxexplorer = [[["https://chainz.cryptoid.info/dash/tx.dws?%%a%%.htm","cryptoID Dash Blockchain Explorer"]],
                           [["http://test.explorer.dashninja.pl/tx/%%a%%","Dash Ninja Testnet Blockchain Explorer"],
                            ["https://test.insight.dash.siampm.com/tx/%%a%%","Alternate Testnet Dash Blockchain Explorer"]]];

// Search query
// ["https://explorer.dashninja.pl/search?q=%%q%%","elberethzone's Dash Blockchain Explorer"],
var dashninjaqueryexplorer = [[["https://chainz.cryptoid.info/dash/search.dws?q=%%q%%","cryptoID Dash Blockchain Explorer"]],
                            [["https://test.explorer.dashninja.pl/search?q=%%q%%","Dash Ninja Testnet Blockchain Explorer"],
                             ["http://test.explorer.darkcoin.qa/search?q=%%q%%","Official Testnet Dash Blockchain Explorer"]]];

var dashninjamasternodemonitoring = ["/masternodes.html?mnregexp=%%p%%#mnversions","/masternodes.html?mnregexp=%%p%%#mnversions"];

var dashninjabudgetdetail = ["/budgetdetails.html?budgetid=%%b%%","/budgetdetails.html?budgetid=%%b%%"];

var dashninjagovernanceproposaldetail = ["/proposaldetails.html?proposalhash=%%b%%","/proposaldetails.html?proposalhash=%%b%%"];

// Blocks per day
var dashblocksperday = 553;