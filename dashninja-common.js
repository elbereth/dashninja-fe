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

function diffHR(diff) {

  var s = Math.floor( diff % 60 );
  var m = Math.floor( diff / 60 % 60 );
  var h = Math.floor( diff / 3600 % 24 );
  var d = Math.floor( diff / 86400 % 7 );
  var w = Math.floor( diff / 604800 );
  var rtxt = '';
  if (w > 0) {
    rtxt += w+'w';
  }
  if (d > 0) {
    rtxt += d+'d';
  }
  if (h > 0) {
    rtxt += h+'h';
  }
  if (m > 0) {
    rtxt += m+'m';
  }
  if (s > 0) {
    rtxt += s+'s';
  }
  return rtxt.trim();
}

function diffHRlong(diff) {

  var s = Math.floor( diff % 60 );
  var m = Math.floor( diff / 60 % 60 );
  var h = Math.floor( diff / 3600 % 24 );
  var d = Math.floor( diff / 86400 % 7 );
  var w = Math.floor( diff / 604800 );
  var rtxt = '';
  if (w > 0) {
    rtxt += w+' week';
    if (w > 1) {
      rtxt += 's';
    }
    rtxt += ' ';
  }
  if (d > 0) {
    rtxt += d+' day';
    if (d > 1) {
      rtxt += 's';
    }
    rtxt += ' ';
  }
  if (h > 0) {
    rtxt += h+' hour';
    if (h > 1) {
      rtxt += 's';
    }
    rtxt += ' ';
  }
  if (m > 0) {
    rtxt += m+' minute';
    if (m > 1) {
      rtxt += 's';
    }
    rtxt += ' ';
  }
  if (s > 0) {
    rtxt += s+' second';
    if (s > 1) {
      rtxt += 's';
    }
    rtxt += ' ';
  }
  return rtxt.trim();
}

function deltaTimeStampHR(ts1,ts2) {
  var diff = Math.abs( ts2 - ts1 );
  return diffHR(diff);
}


function deltaTimeStampHRlong(ts1,ts2) {
  var diff = Math.abs( ts2 - ts1 );
  return diffHRlong(diff);
}

function currenttimestamp() {
  return Math.round(new Date().getTime() / 1000);
}

function addCommas(nStr)
{
        nStr += '';
        x = nStr.split('.');
        x1 = x[0];
        x2 = x.length > 1 ? '.' + x[1] : '';
        var rgx = /(\d+)(\d{3})/;
        while (rgx.test(x1)) {
                x1 = x1.replace(rgx, '$1' + ',' + '$2');
        }
        return x1 + x2;
}

function timeSince(seconds) {

    var interval = Math.floor(seconds / 31536000);

    if (interval == 1) {
        return interval + " year ago";
    } else if (interval > 1) {
        return interval + " years ago";
    }
    interval = Math.floor(seconds / 2592000);
    if (interval == 1) {
        return interval + " month ago";
    } else if (interval > 1) {
        return interval + " months ago";
    }
    interval = Math.floor(seconds / 86400);
    if (interval == 1) {
        return interval + " day ago";
    } else if (interval > 1) {
        return interval + " days ago";
    }
    interval = Math.floor(seconds / 3600);
    if (interval == 1) {
        return interval + " hour ago";
    } else if (interval > 1) {
        return interval + " hours ago";
    }
    interval = Math.floor(seconds / 60);
    if (interval == 1) {
        return interval + " minute ago";
    } else if (interval > 1) {
        return interval + " minutes ago";
    }
    return "Just now";
}

function getParameter(val) {
    var result = "",
        tmp = [];
    var items = location.search.substr(1).split("&");
    for (var index = 0; index < items.length; index++) {
        tmp = items[index].split("=");
        if (tmp[0] === val) result = decodeURIComponent(tmp[1]);
    }
    return result;
}

var STR_PAD_LEFT = 1;
var STR_PAD_RIGHT = 2;
var STR_PAD_BOTH = 3;

function pad(str, len, pad, dir) {

    if (typeof(len) == "undefined") { var len = 0; }
    if (typeof(pad) == "undefined") { var pad = ' '; }
    if (typeof(dir) == "undefined") { var dir = STR_PAD_RIGHT; }

    if (len + 1 >= str.length) {

        switch (dir){

            case STR_PAD_LEFT:
                str = Array(len + 1 - str.length).join(pad) + str;
                break;

            case STR_PAD_BOTH:
                var right = Math.ceil((padlen = len - str.length) / 2);
                var left = padlen - right;
                str = Array(left+1).join(pad) + str + Array(right+1).join(pad);
                break;

            default:
                str = str + Array(len + 1 - str.length).join(pad);
                break;

        } // switch

    }

    return str;

}