'use strict';

$(function() {
  $(window).on('load', function (){
    $('.first-section').addClass('show');
  });
})

//トップページheroのスライドショーの設定
$(function() {
  $('.top-hero__bgImg__inner').slick({
      fade: true,
      autoplay: true,
      speed: 1500,
      autoplaySpeed : 3000,
      pauseOnFocus: false,
      pauseOnHover: false,
      arrows: false,
  })
});

// ギャラリーのスライドショーの設定
$(function() {
  $('.slider').slick({
    autoplay: true, // 自動再生ON
    autoplaySpeed: 3000,
    speed: 400,
    dots: false, // ドットインジケーターON
    centerMode: true, // 両サイドに前後のスライド表示
    centerPadding: '0px', // 左右のスライドのpadding
    slidesToShow: 3, // 一度に表示するスライド数
  });
});

//ハンバーガーメニューの実装
$(function() {
  $('.header-menu-btn').on('click', function() {
    $('.sp-menu-nav').fadeToggle();
    $('.sp-header-humburger__bar').toggleClass('close');
    if ($('.header-menu-btn').text() === 'close') {
      $(this).text('menu');
    } else {
      $(this).text('close');
    }
  });
});
$(function() {
  $('.sp-header-humburger').on('click', function() {
    $('.sp-menu-nav').fadeToggle();
    $('.sp-header-humburger__bar').toggleClass('close');
  });
});

//各セクション スクロール時の要素の表示
$(function(){
  $(window).scroll(function (){
    $(".section").each(function(){
      var elemPos = $(this).offset().top;
      var scroll = $(window).scrollTop();
      var windowHeight = $(window).height();
      if (scroll-200 > elemPos - windowHeight){
        $(this).addClass('show');
      }
    });
  });
});

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

