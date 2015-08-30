// Either indicate if we are we on testnet (=1) or on mainnet (=0)
//var dashninjatestnet = 0;
// OR indicate the hostname for testnet (if the hostname the page is running is equal to this, it will switch to testnet)
var dashninjatestnethost = 'test.dashninja.pl';

// Coin logos
var dashninjacoin = ['DASH','tDASH'];

// URLs
// Block info
var dashninjablockexplorer = [[["http://explorer.dashninja.pl/block/%%b%%","elberethzone's Dash Blockchain Explorer"],
                           ["http://chainz.cryptoid.info/dash/block.dws?%%b%%.htm","cryptoID Dash Blockchain Explorer"]],
                          [["http://test.explorer.dashninja.pl/block/%%b%%","Dash Ninja Testnet Blockchain Explorer"],
                           ["http://test.explorer.darkcoin.qa/block/%%b%%","Official Testnet Dash Blockchain Explorer"],
                           ["http://test.insight.masternode.io:3001/block/%%b%%","coingun's Testnet Dash Blockchain Explorer"]]];

// Address info
var dashninjamndetail = [[["https://dashninja.pl/mndetails.html?mnpubkey=%%a%%","Dash Ninja Masternode Detail"],
                          ["http://www.dashnodes.com/index/address/%%a%%","darkchild's Masternode Monitoring"]],
                         [["https://test.dashninja.pl/mndetails.html?mnpubkey=%%a%%","Dash Ninja Testnet Masternode Detail"]]];
var dashninjamndetailvin = [[["https://dashninja.pl/mndetails.html?mnoutput=%%a%%","Dash Ninja Masternode Detail"]],
                            [["https://test.dashninja.pl/mndetails.html?mnoutput=%%a%%","Dash Ninja Testnet Masternode Detail"]]];

var dashninjaaddressexplorer = [[["http://explorer.dashninja.pl/address/%%a%%","elberethzone's Dash Blockchain Explorer"],
                                 ["https://chainz.cryptoid.info/dash/address.dws?%%a%%.htm","cryptoID Dash Blockchain Explorer"]],
                                [["http://test.explorer.dashninja.pl/address/%%a%%","Dash Ninja Testnet Blockchain Explorer"],
                                 ["http://test.explorer.darkcoin.qa/address/%%a%%","Official Testnet Dash Blockchain Explorer"],
                                 ["http://test.insight.masternode.io:3001/address/%%a%%","coingun's Testnet Dash Blockchain Explorer"]]];
var dashninjatxexplorer = [[["http://explorer.dashninja.pl/tx/%%a%%","elberethzone's Dash Blockchain Explorer"],
                            ["https://chainz.cryptoid.info/dash/tx.dws?%%a%%.htm","cryptoID Dash Blockchain Explorer"]],
                           [["http://test.explorer.dashninja.pl/tx/%%a%%","Dash Ninja Testnet Blockchain Explorer"],
                            ["http://test.explorer.darkcoin.qa/tx/%%a%%","Official Testnet Dash Blockchain Explorer"]]];

// Search query
var dashninjaqueryexplorer = [[["http://explorer.dashninja.pl/search?q=%%q%%","elberethzone's Dash Blockchain Explorer"],
                             ["https://chainz.cryptoid.info/dash/search.dws?q=%%q%%","cryptoID Dash Blockchain Explorer"]],
                            [["http://test.explorer.dashninja.pl/search?q=%%q%%","Dash Ninja Testnet Blockchain Explorer"],
                             ["http://test.explorer.darkcoin.qa/search?q=%%q%%","Official Testnet Dash Blockchain Explorer"]]];

var dashninjamasternodemonitoring = ["https://dashninja.pl/masternodes.html?mnregexp=%%p%%#mnversions","https://test.dashninja.pl/masternodes.html?mnregexp=%%p%%#mnversions"];

// Blocks per day
var dashblocksperday = 553;