/**
 * MicroEngine MailForm
 * https://microengine.jp/mailform/
 *
 * @copyright Copyright (C) 2014-2017 MicroEngine Inc.
 * @version 1.1.0
 */

var memf = {
    lastZipcode: {},
    zipAddr: function (zip1, zip2, pref, city, town, st) {
        var self = this;
        var zipcode = self.getZipcode(zip1, zip2);
        if (zipcode.length === 7 && self.lastZipcode[zip1] !== zipcode) {
            self.setAddr(zipcode, pref, city, town, st);
            self.lastZipcode[zip1] = zipcode;
        }
    },
    zipAddrRun: function (zip1, zip2, pref, city, town, st) {
        var self = this;
        var zipcode = this.getZipcode(zip1, zip2);
        if (zipcode.length === 7) {
            self.setAddr(zipcode, pref, city, town, st);
        }
    },
    getZipcode: function (zip1, zip2) {
        var zipcode = document.querySelector('[name=' + zip1 + ']').value.replace(/-/, '');
        if (zip2) {
            zipcode += document.querySelector('[name=' + zip2 + ']').value;
        }
        return zipcode;
    },
    setAddr: function (zipcode, pref, city, town, st) {
        var request = new XMLHttpRequest();
        request.open('GET', window.location.pathname + '?_zipcode=' + zipcode, true);

        request.onload = function () {
            if (request.status >= 200 && request.status < 400) {
                // Success!
                var data = JSON.parse(request.responseText);
                var str = '';
                (function (obj) {
                    for (var i in obj) {
                        if (data[i]) {
                            str = data[i] + str;
                        }
                        if (obj[i]) {
                            document.querySelector('[name=' + obj[i] + ']').value = str;
                            str = '';
                        }
                    }
                })({st: st, town: town, city: city, pref: pref});
            }
        };
        request.send();
    }
};
